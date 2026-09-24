<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ImportOrderStatus;
use App\Enums\LandedCostType;
use App\Enums\PaymentRequestStatus;
use App\Enums\PaymentRoute;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\BankHold;
use App\Models\FxRate;
use App\Models\GoodsReceipt;
use App\Models\ImportOrder;
use App\Models\LandedCostLine;
use App\Models\PaymentRequest;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ImportOrderStateService
{
    /**
     * Defensive defaults — config('fine.fx.tolerance_lyd') wins when present.
     * Tolerance band for the LYD-grounded variance between actual settled and
     * booked expectation. Deviations within this band do not require an
     * `extra_allocation_note`.
     */
    public const FX_TOLERANCE_LYD = 0.01;

    /**
     * Defensive default — config('fine.fx.hard_cap_percent') wins when present.
     * Hard cap, expressed as a percent of settled, above which the variance
     * surface turns red and a confirm chip is required before submit. Beyond
     * the tolerance but within the hard cap, the variance is amber and the
     * note alone suffices.
     */
    public const FX_HARD_CAP_PERCENT = 5.0;

    public static function fxToleranceLyd(): float
    {
        return (float) config('fine.fx.tolerance_lyd', self::FX_TOLERANCE_LYD);
    }

    public static function fxHardCapPercent(): float
    {
        return (float) config('fine.fx.hard_cap_percent', self::FX_HARD_CAP_PERCENT);
    }

    public function __construct(
        private readonly AccountingService $accountingService,
    ) {}

    /**
     * Single source of truth for the FX values that actually move through
     * the books. Given the two optional inputs (`fxRateUsed` and
     * `exactAmountUsedLyd`), returns both derived values consistently:
     *
     *   - If LYD was supplied, the rate is derived (LYD is the truth — what
     *     the bank statement shows).
     *   - If only the rate was supplied, settled is derived.
     *   - If both are supplied, settled wins and the rate is reconciled to
     *     it (the bank's number, not the operator's typing).
     *
     * This is what `payment_requests.fx_rate_used` is persisted as from
     * Phase 1 onward — the effective realized rate, not the user-entered one.
     *
     * @return array{effective_settled: float, effective_rate: float}
     */
    public function deriveEffectiveValues(
        PaymentRequest $paymentRequest,
        ?float $fxRateUsed,
        ?float $exactAmountUsedLyd,
    ): array {
        $amount = (float) $paymentRequest->amount_requested;

        if ($exactAmountUsedLyd !== null && $exactAmountUsedLyd > 0) {
            $settled = round($exactAmountUsedLyd, 4);
            $rate = $amount > 0 ? round($settled / $amount, 6) : (float) ($fxRateUsed ?? 0);

            return ['effective_settled' => $settled, 'effective_rate' => $rate];
        }

        if ($fxRateUsed !== null && $fxRateUsed > 0) {
            $rate = (float) $fxRateUsed;
            $settled = round($amount * $rate, 4);

            return ['effective_settled' => $settled, 'effective_rate' => $rate];
        }

        throw new InvalidArgumentException('Either fx_rate_used or exact_amount_used_lyd is required.');
    }

    /**
     * Variance between actual settled LYD and what the booked FX snapshot
     * predicted. Returns null when there is no booked snapshot to compare
     * against (the completion journal then treats booked = settled).
     */
    public function varianceVsBooked(
        ImportOrder $order,
        float $effectiveSettled,
        string $functionalCurrency,
    ): ?float {
        $bookedRate = $this->bookedFxRate($order, $functionalCurrency);
        if ($bookedRate === null) {
            return null;
        }

        $expected = round((float) $order->totalCost() * $bookedRate, 4);

        return round($effectiveSettled - $expected, 4);
    }

    public function transitionToPendingPayment(ImportOrder $order): ImportOrder
    {
        return DB::transaction(function () use ($order) {
            if ($order->status !== ImportOrderStatus::Draft) {
                throw new InvalidStateTransitionException('Order must be in draft status to transition to pending payment.');
            }

            $amountRequested = $order->totalCost();

            PaymentRequest::create([
                'operating_unit_id' => $order->operating_unit_id,
                'import_order_id' => $order->id,
                'route' => PaymentRoute::Bank,
                'amount_requested' => $amountRequested,
                'status' => PaymentRequestStatus::Pending,
            ]);

            $order->update([
                'status' => ImportOrderStatus::PendingPayment,
            ]);

            return $order->refresh();
        });
    }

    public function selectPaymentRoute(
        ImportOrder $order,
        PaymentRoute $route,
        float $amountRequested,
        ?float $heldAmountLyd = null,
        ?string $invoiceRef = null
    ): ImportOrder {
        return DB::transaction(function () use ($order, $route, $amountRequested, $heldAmountLyd, $invoiceRef) {
            if ($order->status !== ImportOrderStatus::PendingPayment) {
                throw new InvalidStateTransitionException('Order must be in pending_payment status to select payment route.');
            }

            $paymentRequest = $order->paymentRequests()->where('status', PaymentRequestStatus::Pending)->first();

            if (! $paymentRequest) {
                $paymentRequest = PaymentRequest::create([
                    'operating_unit_id' => $order->operating_unit_id,
                    'import_order_id' => $order->id,
                    'route' => $route,
                    'amount_requested' => $amountRequested,
                    'status' => PaymentRequestStatus::Pending,
                ]);
            } else {
                // FX-09: once a hold exists on this request, the route is
                // locked. Switching from Bank to Market would leave the
                // BankHold row stranded while the request is relabeled, and
                // executePayment would still apply the bank-branch math
                // because the guard reads `route === Bank && bankHold`.
                if ($paymentRequest->bankHold && $paymentRequest->route !== $route) {
                    throw new InvalidArgumentException(
                        'Cannot change payment route after a bank hold is established.',
                    );
                }

                $paymentRequest->update([
                    'route' => $route,
                    'invoice_ref' => $invoiceRef,
                    'amount_requested' => $amountRequested,
                ]);
            }

            if ($route === PaymentRoute::Bank) {
                if ($heldAmountLyd === null || $heldAmountLyd <= 0) {
                    throw new InvalidArgumentException('Held amount in LYD is required for bank payment route.');
                }

                // FX-01: updateOrCreate keyed on the request id so a repeated
                // select_route call overwrites the held amount rather than
                // producing a second orphan row.
                BankHold::updateOrCreate(
                    ['payment_request_id' => $paymentRequest->id],
                    [
                        'held_amount_lyd' => $heldAmountLyd,
                        'exact_amount_used' => 0,
                        'released_amount' => 0,
                    ],
                );

                $order->update(['status' => ImportOrderStatus::AwaitingBankApproval]);
            } else {
                $order->update(['status' => ImportOrderStatus::AwaitingTransfer]);
            }

            return $order->refresh();
        });
    }

    public function executePayment(
        PaymentRequest $paymentRequest,
        ?float $fxRateUsed = null,
        ?float $exactAmountUsedLyd = null,
        ?string $bankReference = null,
        ?string $extraAllocationNote = null
    ): PaymentRequest {
        return DB::transaction(function () use ($paymentRequest, $fxRateUsed, $exactAmountUsedLyd, $bankReference, $extraAllocationNote) {
            if ($paymentRequest->status !== PaymentRequestStatus::Pending) {
                throw new InvalidStateTransitionException('Payment request is not pending.');
            }

            // FX-02 / FX-24 / FX-15: derive the single source of truth. The
            // persisted `fx_rate_used` is the *effective* realized rate —
            // derived from the LYD amount when LYD is supplied, from the
            // operator-entered rate otherwise. Both inputs are now accepted
            // on Bank and Market routes; the LYD amount is what actually
            // moves the books.
            ['effective_settled' => $settled, 'effective_rate' => $effectiveRate] =
                $this->deriveEffectiveValues($paymentRequest, $fxRateUsed, $exactAmountUsedLyd);

            $paymentRequest->update([
                'fx_rate_used' => $effectiveRate,
                'extra_allocation_note' => $extraAllocationNote,
                'status' => PaymentRequestStatus::Paid,
            ]);

            if ($paymentRequest->route === PaymentRoute::Bank && $paymentRequest->bankHold) {
                $held = (float) $paymentRequest->bankHold->held_amount_lyd;
                $released = max(0, $held - $settled);

                $paymentRequest->bankHold->update([
                    'exact_amount_used' => $settled,
                    'released_amount' => $released,
                    'bank_reference' => $bankReference,
                ]);
            }

            $order = $paymentRequest->importOrder;

            // Money actually left the company: cash out, advance on the
            // supplier until the goods complete (the completion journal
            // clears 1500 with the same settled amount, so it nets exactly).
            $functionalCurrency = $order->operatingUnit?->company?->default_currency ?? 'LYD';

            if ($settled > 0) {
                $this->accountingService->postJournal(
                    "Import payment executed — {$order->supplier?->name}",
                    [
                        [
                            'account_code' => '15', // Advances to Suppliers
                            'debit' => $settled,
                            'operating_unit_id' => $order->operating_unit_id,
                            'memo' => $order->supplier?->name,
                        ],
                        [
                            'account_code' => '12', // Cash and Bank
                            'credit' => $settled,
                            'operating_unit_id' => $order->operating_unit_id,
                            'memo' => $paymentRequest->route->value.' route',
                        ],
                    ],
                    'PaymentRequest',
                    $paymentRequest->id,
                    $order->operatingUnit?->company_id,
                );
            }

            $order->update(['status' => ImportOrderStatus::Paid]);

            return $paymentRequest->refresh();
        });
    }

    /**
     * What actually left the bank in functional currency: the bank's exact
     * amount when a hold was settled, otherwise the contract value at the
     * executed rate. Both the payment and the completion journals use this
     * one number, so the advance always clears to zero.
     */
    private function settledLyd(ImportOrder $order, string $functionalCurrency): float
    {
        $supplierCostFc = $order->totalCost();

        if ($order->currency === $functionalCurrency) {
            return $supplierCostFc;
        }

        $paid = $order->paymentRequests()
            ->where('status', PaymentRequestStatus::Paid)
            ->whereNotNull('fx_rate_used')
            ->latest('updated_at')
            ->first();

        if ($paid === null) {
            throw new InvalidArgumentException(
                'Order has no executed payment with an FX rate; cannot value the purchase for posting.'
            );
        }

        $exactUsed = (float) ($paid->bankHold?->exact_amount_used ?? 0);

        if ($exactUsed > 0) {
            return round($exactUsed, 4);
        }

        return round($supplierCostFc * (float) $paid->fx_rate_used, 4);
    }

    public function confirmShipment(ImportOrder $order): ImportOrder
    {
        return DB::transaction(function () use ($order) {
            if ($order->status !== ImportOrderStatus::Paid) {
                throw new InvalidStateTransitionException('Order must be paid before confirming shipment.');
            }

            $order->update(['status' => ImportOrderStatus::InTransit]);

            return $order->refresh();
        });
    }

    public function arriveAtPort(ImportOrder $order): ImportOrder
    {
        return DB::transaction(function () use ($order) {
            if ($order->status !== ImportOrderStatus::InTransit) {
                throw new InvalidStateTransitionException('Order must be in_transit before arriving at port.');
            }

            $order->update(['status' => ImportOrderStatus::AtPort]);

            return $order->refresh();
        });
    }

    public function transportToWarehouse(ImportOrder $order): ImportOrder
    {
        return DB::transaction(function () use ($order) {
            if ($order->status !== ImportOrderStatus::AtPort) {
                throw new InvalidStateTransitionException('Order must be at_port before transporting to warehouse.');
            }

            $order->update(['status' => ImportOrderStatus::InTransitToWarehouse]);

            return $order->refresh();
        });
    }

    /**
     * The truck has arrived at the destination warehouse but the goods have
     * not yet been counted / inspected. Records which warehouse they're at
     * so the operator doesn't have to re-pick on the receive step.
     */
    public function arriveAtWarehouse(ImportOrder $order, string $warehouseId): ImportOrder
    {
        return DB::transaction(function () use ($order, $warehouseId) {
            if ($order->status !== ImportOrderStatus::InTransitToWarehouse) {
                throw new InvalidStateTransitionException('Order must be in_transit_to_warehouse before arriving at the warehouse.');
            }

            $order->update([
                'status' => ImportOrderStatus::AtWarehouse,
                'arrived_warehouse_id' => $warehouseId,
            ]);

            return $order->refresh();
        });
    }

    public function receiveGoods(
        ImportOrder $order,
        string $warehouseId,
        float $receivedQty,
        ?string $notes = null
    ): GoodsReceipt {
        return DB::transaction(function () use ($order, $warehouseId, $receivedQty, $notes) {
            // Accept the modern at_warehouse state, plus the legacy
            // awaiting_receipt state for orders that predate the
            // arrived_at_warehouse split.
            if (! in_array($order->status, [
                ImportOrderStatus::AtWarehouse,
                ImportOrderStatus::AwaitingReceipt,
            ], true)) {
                throw new InvalidStateTransitionException('Order must be at_warehouse (or awaiting_receipt) before receiving goods.');
            }

            if ($receivedQty > (float) $order->quantity) {
                throw new InvalidArgumentException('Received quantity cannot exceed ordered quantity.');
            }

            $receipt = GoodsReceipt::create([
                'import_order_id' => $order->id,
                'warehouse_id' => $warehouseId,
                'received_qty' => $receivedQty,
                'condition_notes' => $notes,
            ]);

            $order->update(['status' => ImportOrderStatus::Received]);

            return $receipt;
        });
    }

    public function completeOrder(ImportOrder $order): ImportOrder
    {
        return DB::transaction(function () use ($order) {
            if ($order->status !== ImportOrderStatus::Received) {
                throw new InvalidStateTransitionException('Order must be in received status to complete.');
            }

            if (! $order->goodsReceipt) {
                throw new InvalidArgumentException('Goods receipt must exist to complete order.');
            }

            $unconfirmedLines = $order->landedCostLines()->where('is_confirmed', false)->count();
            if ($unconfirmedLines > 0) {
                throw new InvalidArgumentException('All landed cost lines must be confirmed before completing order.');
            }

            // ACC-02/ACC-10: the purchase enters the ledger here, on Complete,
            // or not at all. Posting before the status flip keeps the guard
            // fatal — a completion that cannot post is refused, not skipped.
            $this->postCompletionJournal($order);

            $order->update(['status' => ImportOrderStatus::Complete]);

            return $order->refresh();
        });
    }

    /**
     * Phase 08 §8.4, ImportOrder.Complete — the purchase reaches the ledger.
     *
     *   DR  1110 Raw Material Inventory   (supplier cost at booked rate + landed costs)
     *   DR  5300 FX Loss                  (realized > booked)
     *   CR  2100 Accounts Payable         (supplier cost at realized rate)
     *   CR  4200 FX Gain                  (realized < booked)
     *   CR  2300 Landed Cost Clearing     (one line per confirmed cost component)
     *
     * Inventory carries the transaction-date (booked) value of the goods plus
     * every confirmed landed cost; the credit clears the 1500 advance that
     * executePayment posted, with exactly the settled amount, so the advance
     * always nets to zero; the difference between booked and settled — rate
     * movement and bank spread alike — is the FX gain/loss (phase-02 §2.8,
     * ACC-09). Landed cost lines of type `supplier_price` are skipped: the
     * order itself is the supplier-price component, and counting a mirror
     * line again would double the inventory value.
     */
    private function postCompletionJournal(ImportOrder $order): void
    {
        $functionalCurrency = $order->operatingUnit?->company?->default_currency ?? 'LYD';

        $supplierCostFc = $order->totalCost();
        $realizedRate = $this->realizedFxRate($order, $functionalCurrency);
        $settled = $this->settledLyd($order, $functionalCurrency);
        $bookedRate = $this->bookedFxRate($order, $functionalCurrency);

        $bookedCost = $bookedRate !== null ? round($supplierCostFc * $bookedRate, 4) : $settled;
        $fxDifference = round($settled - $bookedCost, 4);

        $landedCostLines = $order->landedCostLines()
            ->where('is_confirmed', true)
            ->where('type', '!=', LandedCostType::SupplierPrice)
            ->get();

        $landedCostTotal = 0.0;
        $clearingLines = [];

        foreach ($landedCostLines as $line) {
            $amount = $this->landedCostInFunctionalCurrency($line, $order, $functionalCurrency, $realizedRate);

            if ($amount <= 0.0) {
                continue;
            }

            $landedCostTotal = round($landedCostTotal + $amount, 4);
            $clearingLines[] = [
                'account_code' => '23', // Landed Cost Clearing
                'credit' => $amount,
                'operating_unit_id' => $order->operating_unit_id,
                'memo' => $line->type->value,
            ];
        }

        $receivedQty = (float) $order->goodsReceipt->received_qty;
        $inventoryValue = round($bookedCost + $landedCostTotal, 4);
        $perUnit = $receivedQty > 0.0 ? round($inventoryValue / $receivedQty, 4) : 0.0;

        $lines = [[
            'account_code' => '111', // Raw Material Inventory
            'debit' => $inventoryValue,
            'operating_unit_id' => $order->operating_unit_id,
            'memo' => "{$receivedQty} received at {$perUnit}/unit landed",
        ]];

        if ($fxDifference > 0) {
            $lines[] = [
                'account_code' => '53', // FX Loss
                'debit' => $fxDifference,
                'operating_unit_id' => $order->operating_unit_id,
                'memo' => "booked {$bookedCost}, settled {$settled}",
            ];
        }

        $lines[] = [
            'account_code' => '15', // Advances to Suppliers — cleared
            'credit' => $settled,
            'operating_unit_id' => $order->operating_unit_id,
            'memo' => $order->supplier?->name,
        ];

        if ($fxDifference < 0) {
            $lines[] = [
                'account_code' => '42', // FX Gain
                'credit' => -$fxDifference,
                'operating_unit_id' => $order->operating_unit_id,
                'memo' => "booked {$bookedCost}, settled {$settled}",
            ];
        }

        $this->accountingService->postJournal(
            "Import order from {$order->supplier?->name} completed",
            array_merge($lines, $clearingLines),
            'ImportOrder',
            $order->id,
            $order->operatingUnit?->company_id,
        );
    }

    /**
     * The rate treasury actually settled at. An order cannot reach Received
     * without an executed payment, so a missing rate means the books cannot
     * be made whole — refuse rather than guess.
     */
    private function realizedFxRate(ImportOrder $order, string $functionalCurrency): float
    {
        if ($order->currency === $functionalCurrency) {
            return 1.0;
        }

        $rate = $order->paymentRequests()
            ->where('status', PaymentRequestStatus::Paid)
            ->whereNotNull('fx_rate_used')
            ->latest('updated_at')
            ->value('fx_rate_used');

        if ($rate === null) {
            throw new InvalidArgumentException(
                'Order has no executed payment with an FX rate; cannot value the purchase for posting.'
            );
        }

        return (float) $rate;
    }

    /**
     * The booked estimate: the snapshot taken at order creation, falling back
     * to the rate history for orders that predate the snapshot column. Null
     * means no estimate ever existed — the caller treats booked = realized.
     */
    private function bookedFxRate(ImportOrder $order, string $functionalCurrency): ?float
    {
        if ($order->currency === $functionalCurrency) {
            return 1.0;
        }

        if ($order->booked_fx_rate !== null) {
            return (float) $order->booked_fx_rate;
        }

        $historical = FxRate::query()
            ->where('from_currency', $order->currency)
            ->where('to_currency', $functionalCurrency)
            ->where('captured_at', '<=', $order->created_at)
            ->latest('captured_at')
            ->value('rate');

        return $historical !== null ? (float) $historical : null;
    }

    private function landedCostInFunctionalCurrency(
        LandedCostLine $line,
        ImportOrder $order,
        string $functionalCurrency,
        float $realizedRate,
    ): float {
        $amount = round((float) $line->amount, 4);

        if ($line->currency === $functionalCurrency) {
            return $amount;
        }

        if ($line->currency === $order->currency) {
            return round($amount * $realizedRate, 4);
        }

        throw new InvalidArgumentException(
            "Landed cost line in {$line->currency} cannot be converted to {$functionalCurrency}; no rate is available."
        );
    }
}
