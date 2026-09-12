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
    public function __construct(
        private readonly AccountingService $accountingService,
    ) {}

    public function transitionToPendingPayment(ImportOrder $order): ImportOrder
    {
        return DB::transaction(function () use ($order) {
            if ($order->status !== ImportOrderStatus::Draft) {
                throw new InvalidStateTransitionException('Order must be in draft status to transition to pending payment.');
            }

            $amountRequested = (float) $order->negotiated_price * (float) $order->quantity;

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

                BankHold::create([
                    'payment_request_id' => $paymentRequest->id,
                    'held_amount_lyd' => $heldAmountLyd,
                    'exact_amount_used' => 0,
                    'released_amount' => 0,
                ]);

                $order->update(['status' => ImportOrderStatus::AwaitingBankApproval]);
            } else {
                $order->update(['status' => ImportOrderStatus::AwaitingTransfer]);
            }

            return $order->refresh();
        });
    }

    public function executePayment(
        PaymentRequest $paymentRequest,
        float $fxRateUsed,
        ?float $exactAmountUsedLyd = null,
        ?string $bankReference = null,
        ?string $extraAllocationNote = null
    ): PaymentRequest {
        return DB::transaction(function () use ($paymentRequest, $fxRateUsed, $exactAmountUsedLyd, $bankReference, $extraAllocationNote) {
            if ($paymentRequest->status !== PaymentRequestStatus::Pending) {
                throw new InvalidStateTransitionException('Payment request is not pending.');
            }

            $paymentRequest->update([
                'fx_rate_used' => $fxRateUsed,
                'extra_allocation_note' => $extraAllocationNote,
                'status' => PaymentRequestStatus::Paid,
            ]);

            if ($paymentRequest->route === PaymentRoute::Bank && $paymentRequest->bankHold) {
                $exactUsed = $exactAmountUsedLyd ?? ((float) $paymentRequest->amount_requested * $fxRateUsed);
                $held = (float) $paymentRequest->bankHold->held_amount_lyd;
                $released = max(0, $held - $exactUsed);

                $paymentRequest->bankHold->update([
                    'exact_amount_used' => $exactUsed,
                    'released_amount' => $released,
                    'bank_reference' => $bankReference,
                ]);
            }

            $order = $paymentRequest->importOrder;

            // Money actually left the company: cash out, advance on the
            // supplier until the goods complete (the completion journal
            // clears 1500 with the same settled amount, so it nets exactly).
            $functionalCurrency = $order->operatingUnit?->company?->default_currency ?? 'LYD';
            $settled = $this->settledLyd($order, $functionalCurrency);

            if ($settled > 0) {
                $this->accountingService->postJournal(
                    "Import payment executed — {$order->supplier?->name}",
                    [
                        [
                            'account_code' => '1500', // Advances to Suppliers
                            'debit' => $settled,
                            'operating_unit_id' => $order->operating_unit_id,
                            'memo' => $order->supplier?->name,
                        ],
                        [
                            'account_code' => '1200', // Cash and Bank
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
        $supplierCostFc = round((float) $order->negotiated_price * (float) $order->quantity, 4);

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

        $supplierCostFc = round((float) $order->negotiated_price * (float) $order->quantity, 4);
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
                'account_code' => '2300', // Landed Cost Clearing
                'credit' => $amount,
                'operating_unit_id' => $order->operating_unit_id,
                'memo' => $line->type->value,
            ];
        }

        $receivedQty = (float) $order->goodsReceipt->received_qty;
        $inventoryValue = round($bookedCost + $landedCostTotal, 4);
        $perUnit = $receivedQty > 0.0 ? round($inventoryValue / $receivedQty, 4) : 0.0;

        $lines = [[
            'account_code' => '1110', // Raw Material Inventory
            'debit' => $inventoryValue,
            'operating_unit_id' => $order->operating_unit_id,
            'memo' => "{$receivedQty} received at {$perUnit}/unit landed",
        ]];

        if ($fxDifference > 0) {
            $lines[] = [
                'account_code' => '5300', // FX Loss
                'debit' => $fxDifference,
                'operating_unit_id' => $order->operating_unit_id,
                'memo' => "booked {$bookedCost}, settled {$settled}",
            ];
        }

        $lines[] = [
            'account_code' => '1500', // Advances to Suppliers — cleared
            'credit' => $settled,
            'operating_unit_id' => $order->operating_unit_id,
            'memo' => $order->supplier?->name,
        ];

        if ($fxDifference < 0) {
            $lines[] = [
                'account_code' => '4200', // FX Gain
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
