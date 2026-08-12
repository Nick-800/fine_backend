<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SalesOrderStatus;
use App\Exceptions\InsufficientComponentStockException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Client;
use App\Models\CreditApprovalRequest;
use App\Models\InternalRestockRequest;
use App\Models\InventoryMovement;
use App\Models\OperatingUnit;
use App\Models\SalesOrder;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SalesOrderService
{
    public function __construct(private readonly AccountingService $accountingService) {}

    /**
     * Which finished-goods account a sold item's value leaves from.
     */
    private function inventoryAccountFor(?string $itemType): string
    {
        return match ($itemType) {
            'foam_block' => '1131',
            'cut_template_piece', 'slice' => '1132',
            'byproduct_fill' => '1133',
            'furniture_finished_good' => '1134',
            default => '1110',
        };
    }

    /**
     * Submit a draft. The outcome is decided here, never by the caller:
     * the credit check (SALE-01) routes an external order to confirmed or
     * pending_approval; internal and walk-in orders skip the gate (SALE-03).
     */
    public function submit(SalesOrder $order): SalesOrder
    {
        return DB::transaction(function () use ($order): SalesOrder {
            $locked = SalesOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== SalesOrderStatus::Draft) {
                throw new InvalidStateTransitionException(
                    "Order {$locked->order_number} is {$locked->status->value}; only a draft can be submitted."
                );
            }

            $total = round((float) $locked->lines()->get()->sum(fn ($l) => $l->lineTotal()), 4);

            if ($total <= 0) {
                throw new InvalidArgumentException("Order {$locked->order_number} has no lines to sell.");
            }

            $locked->total_amount = $total;

            if ($locked->isCreditGated()) {
                // Lock the client so two simultaneous orders cannot both pass on
                // the same remaining headroom.
                $client = Client::whereKey($locked->client_id)->lockForUpdate()->firstOrFail();

                $proposed = round((float) $client->current_balance + $total, 4);
                $limit = (float) $client->credit_limit;

                if ($proposed > $limit) {
                    $locked->status = SalesOrderStatus::PendingApproval;
                    $locked->save();

                    // SALE-02: the order stays blocked until someone with the
                    // authority decides.
                    CreditApprovalRequest::create([
                        'sales_order_id' => $locked->id,
                        'amount_over_limit' => round($proposed - $limit, 4),
                    ]);

                    return $locked->fresh(['creditApprovalRequest']);
                }
            }

            $locked->status = SalesOrderStatus::Confirmed;
            $locked->save();

            return $locked->fresh();
        });
    }

    public function decideCreditApproval(CreditApprovalRequest $request, bool $approved, User $decidedBy, ?string $notes = null): CreditApprovalRequest
    {
        return DB::transaction(function () use ($request, $approved, $decidedBy, $notes): CreditApprovalRequest {
            if ($request->status !== 'pending') {
                throw new InvalidStateTransitionException('This credit request has already been decided.');
            }

            $request->update([
                'status' => $approved ? 'approved' : 'rejected',
                'decided_by_user_id' => $decidedBy->id,
                'decided_at' => now(),
                'notes' => $notes,
            ]);

            $order = $request->salesOrder;
            $order->status = $approved ? SalesOrderStatus::Confirmed : SalesOrderStatus::Rejected;
            $order->save();

            return $request->fresh(['salesOrder']);
        });
    }

    /**
     * Issue the goods. Stock is drawn FIFO from the selling unit at fulfillment
     * time, so COGS is the cost of the lots actually taken (SALE-07/08).
     */
    public function fulfill(SalesOrder $order): SalesOrder
    {
        return DB::transaction(function () use ($order): SalesOrder {
            $locked = SalesOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== SalesOrderStatus::Confirmed) {
                throw new InvalidStateTransitionException(
                    "Order {$locked->order_number} is {$locked->status->value}; only a confirmed order can be fulfilled."
                );
            }

            [$totalCost, $costByAccount] = $this->issueGoods($locked);

            $locked->total_cost = $totalCost;
            $locked->status = SalesOrderStatus::Fulfilled;
            $locked->save();

            if ($locked->isInternal()) {
                $this->postInternalTransfer($locked, $costByAccount);
            } else {
                $this->postExternalFulfillment($locked, $totalCost, $costByAccount);
            }

            return $locked->fresh(['lines']);
        });
    }

    /**
     * SALE-06: payment moves the client balance and the books together.
     */
    public function recordPayment(SalesOrder $order, float $amount, ?string $method = null): SalesOrder
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('A payment must be greater than zero.');
        }

        return DB::transaction(function () use ($order, $amount, $method): SalesOrder {
            $locked = SalesOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->acceptsPayment()) {
                throw new InvalidStateTransitionException(
                    "Order {$locked->order_number} is {$locked->status->value} and cannot take a payment."
                );
            }

            if ($locked->isInternal()) {
                throw new InvalidArgumentException('Internal transfers settle at cost; there is nothing to pay.');
            }

            $outstanding = $locked->outstanding();

            if ($amount > $outstanding) {
                throw new InvalidArgumentException(
                    "Payment of {$amount} exceeds the outstanding {$outstanding}."
                );
            }

            $locked->amount_paid = round((float) $locked->amount_paid + $amount, 4);
            $locked->payment_method = $method ?? $locked->payment_method;
            $locked->status = $locked->outstanding() <= 0.0001
                ? SalesOrderStatus::Paid
                : SalesOrderStatus::PartiallyPaid;
            $locked->save();

            if ($locked->client_id !== null) {
                $client = Client::whereKey($locked->client_id)->lockForUpdate()->first();
                $client->current_balance = round((float) $client->current_balance - $amount, 4);
                $client->save();
            }

            $this->accountingService->postJournal(
                "Payment on sales order {$locked->order_number}",
                [
                    ['account_code' => '1200', 'debit' => $amount, 'operating_unit_id' => $locked->operating_unit_id],
                    ['account_code' => '1300', 'credit' => $amount, 'operating_unit_id' => $locked->operating_unit_id],
                ],
                'SalesOrder',
                $locked->id,
                $locked->operatingUnit?->company_id,
            );

            return $locked->fresh();
        });
    }

    /**
     * An internal transfer is settled the moment the goods arrive; there is no
     * payment leg to wait for.
     */
    public function completeInternal(SalesOrder $order): SalesOrder
    {
        if (! $order->isInternal() || $order->status !== SalesOrderStatus::Fulfilled) {
            throw new InvalidStateTransitionException('Only a fulfilled internal transfer can be completed.');
        }

        $order->status = SalesOrderStatus::Completed;
        $order->save();

        return $order->fresh();
    }

    /**
     * SALE-09: the POS sale is one atomic step — goods out, cash in, done.
     * Nothing intermediate exists for the counter queue to wait on.
     *
     * @param  array<int, array{inventory_item_id: string, quantity: float|int, unit_price: float|int}>  $items
     */
    public function posCheckout(
        string $operatingUnitId,
        array $items,
        string $paymentMethod,
        string $orderNumber,
        ?string $clientId = null,
    ): SalesOrder {
        if (! in_array($paymentMethod, ['cash', 'card'], true)) {
            throw new InvalidArgumentException('POS accepts cash or card; credit sales go through a standard order.');
        }

        return DB::transaction(function () use ($operatingUnitId, $items, $paymentMethod, $orderNumber, $clientId): SalesOrder {
            $order = SalesOrder::create([
                'operating_unit_id' => $operatingUnitId,
                'order_number' => $orderNumber,
                'buyer_type' => $clientId !== null ? 'client' : 'walk_in',
                'client_id' => $clientId,
                'channel' => 'pos',
                'payment_method' => $paymentMethod,
            ]);

            foreach ($items as $item) {
                $order->lines()->create([
                    'inventory_item_id' => $item['inventory_item_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                ]);
            }

            $total = round((float) $order->lines()->get()->sum(fn ($l) => $l->lineTotal()), 4);
            [$totalCost, $costByAccount] = $this->issueGoods($order);

            $order->total_amount = $total;
            $order->total_cost = $totalCost;
            $order->amount_paid = $total;
            $order->status = SalesOrderStatus::Paid;
            $order->save();

            // Cash against revenue, cost out of stock — one balanced entry.
            $lines = [
                ['account_code' => '1200', 'debit' => $total, 'operating_unit_id' => $operatingUnitId],
                ['account_code' => '4100', 'credit' => $total, 'operating_unit_id' => $operatingUnitId],
            ];

            if ($totalCost > 0) {
                $lines[] = ['account_code' => '5100', 'debit' => $totalCost, 'operating_unit_id' => $operatingUnitId];
                foreach ($costByAccount as $account => $amount) {
                    $lines[] = ['account_code' => (string) $account, 'credit' => $amount, 'operating_unit_id' => $operatingUnitId];
                }
            }

            $this->accountingService->postJournal(
                "POS sale {$order->order_number}",
                $lines,
                'SalesOrder',
                $order->id,
                $order->operatingUnit?->company_id,
            );

            return $order->fresh(['lines.inventoryItem']);
        });
    }

    /**
     * Approve, reject, or fulfill a store's restock request. Fulfillment is the
     * physical transfer: stock leaves the source unit and lands in the
     * requesting unit at cost, with the journal moving value between the two
     * unit subledgers (SALE-08).
     */
    public function fulfillRestock(InternalRestockRequest $request): InternalRestockRequest
    {
        return DB::transaction(function () use ($request): InternalRestockRequest {
            if ($request->status !== 'approved') {
                throw new InvalidStateTransitionException(
                    "Restock request {$request->request_number} is {$request->status}; only an approved request can be fulfilled."
                );
            }

            $targetWarehouse = Warehouse::withoutGlobalScopes()
                ->where('operating_unit_id', $request->requesting_unit_id)
                ->first();

            if ($targetWarehouse === null) {
                throw new InvalidArgumentException('The requesting unit has no warehouse to receive stock.');
            }

            $costByAccount = [];
            $totalCost = 0.0;

            foreach ($request->lines()->with('inventoryItem')->get() as $line) {
                [$cost, $drawn] = $this->drawFromUnit(
                    $line->inventory_item_id,
                    (float) $line->quantity,
                    $request->source_unit_id,
                    'InternalRestockRequest',
                    $request->id,
                    'restock_transfer_out',
                );

                $totalCost += $cost;
                $account = $this->inventoryAccountFor($line->inventoryItem?->item_type);
                $costByAccount[$account] = round(($costByAccount[$account] ?? 0) + $cost, 4);

                // Arrives in the store at the cost it left with.
                $received = StockLot::create([
                    'inventory_item_id' => $line->inventory_item_id,
                    'warehouse_id' => $targetWarehouse->id,
                    'lot_number' => 'RST-'.$request->request_number.'-'.substr($line->id, 0, 8),
                    'quantity' => $line->quantity,
                    'unit_cost' => $line->quantity > 0 ? round($cost / (float) $line->quantity, 4) : 0,
                    'status' => 'available',
                ]);

                InventoryMovement::create([
                    'operating_unit_id' => $request->requesting_unit_id,
                    'stock_lot_id' => $received->id,
                    'to_warehouse_id' => $targetWarehouse->id,
                    'sku' => $line->inventoryItem?->sku ?? 'ITEM',
                    'movement_type' => 'transfer',
                    'quantity_delta' => (float) $line->quantity,
                    'unit_cost' => (float) $received->unit_cost,
                    'reason' => 'restock_transfer_in',
                    'reference_document_type' => 'InternalRestockRequest',
                    'reference_id' => $request->id,
                ]);
            }

            $request->status = 'fulfilled';
            $request->save();

            if ($totalCost > 0) {
                $journalLines = [];
                foreach ($costByAccount as $account => $amount) {
                    // Same account, two units: value moves between subledgers
                    // while the company total stays put — at-cost transfer.
                    $journalLines[] = ['account_code' => (string) $account, 'debit' => $amount, 'operating_unit_id' => $request->requesting_unit_id];
                    $journalLines[] = ['account_code' => (string) $account, 'credit' => $amount, 'operating_unit_id' => $request->source_unit_id];
                }

                $this->accountingService->postJournal(
                    "Restock transfer {$request->request_number}",
                    $journalLines,
                    'InternalRestockRequest',
                    $request->id,
                    OperatingUnit::find($request->source_unit_id)?->company_id,
                );
            }

            return $request->fresh(['lines']);
        });
    }

    /**
     * Issue every line of an order from the selling unit's stock.
     *
     * @return array{0: float, 1: array<string, float>}
     */
    private function issueGoods(SalesOrder $order): array
    {
        $totalCost = 0.0;
        $costByAccount = [];

        foreach ($order->lines()->with('inventoryItem')->get() as $line) {
            [$cost] = $this->drawFromUnit(
                $line->inventory_item_id,
                (float) $line->quantity,
                $order->operating_unit_id,
                'SalesOrder',
                $order->id,
                'sale_issue',
            );

            $totalCost += $cost;
            $line->unit_cost_actual = $line->quantity > 0 ? round($cost / (float) $line->quantity, 4) : 0;
            $line->save();

            $account = $this->inventoryAccountFor($line->inventoryItem?->item_type);
            $costByAccount[$account] = round(($costByAccount[$account] ?? 0) + $cost, 4);

            // An internal sale also lands the goods in the buyer unit.
            if ($order->isInternal() && $order->buyer_unit_id !== null) {
                $buyerWarehouse = Warehouse::withoutGlobalScopes()
                    ->where('operating_unit_id', $order->buyer_unit_id)
                    ->first();

                if ($buyerWarehouse === null) {
                    throw new InvalidArgumentException('The buying unit has no warehouse to receive stock.');
                }

                $received = StockLot::create([
                    'inventory_item_id' => $line->inventory_item_id,
                    'warehouse_id' => $buyerWarehouse->id,
                    'lot_number' => 'TRF-'.$order->order_number.'-'.substr($line->id, 0, 8),
                    'quantity' => $line->quantity,
                    'unit_cost' => $line->unit_cost_actual,
                    'status' => 'available',
                ]);

                InventoryMovement::create([
                    'operating_unit_id' => $order->buyer_unit_id,
                    'stock_lot_id' => $received->id,
                    'to_warehouse_id' => $buyerWarehouse->id,
                    'sku' => $line->inventoryItem?->sku ?? 'ITEM',
                    'movement_type' => 'transfer',
                    'quantity_delta' => (float) $line->quantity,
                    'unit_cost' => (float) $line->unit_cost_actual,
                    'reason' => 'internal_transfer_in',
                    'reference_document_type' => 'SalesOrder',
                    'reference_id' => $order->id,
                ]);
            }
        }

        return [round($totalCost, 4), $costByAccount];
    }

    /**
     * FIFO draw of one item from one unit's stock, movement included.
     *
     * @return array{0: float, 1: array<int, StockLot>}
     */
    private function drawFromUnit(
        string $itemId,
        float $quantity,
        string $unitId,
        string $referenceType,
        string $referenceId,
        string $reason,
    ): array {
        // withoutGlobalScopes on both levels: the caller's unit context must not
        // leak into the warehouse subquery, or a restock could never see the
        // *source* unit's stock.
        $lots = StockLot::withoutGlobalScopes()
            ->where('inventory_item_id', $itemId)
            ->where('status', 'available')
            ->whereHas('warehouse', fn ($q) => $q->withoutGlobalScopes()->where('operating_unit_id', $unitId))
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        $available = (float) $lots->sum('quantity');

        if ($available < $quantity) {
            $sku = $lots->first()?->inventoryItem?->sku ?? $itemId;

            throw new InsufficientComponentStockException(
                "Stock cannot cover the sale — {$sku}: need {$quantity}, have {$available}."
            );
        }

        $remaining = $quantity;
        $cost = 0.0;
        $drawn = [];

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $take = min((float) $lot->quantity, $remaining);
            $cost += $take * (float) $lot->unit_cost;
            $remaining = round($remaining - $take, 4);

            $lot->quantity = round((float) $lot->quantity - $take, 4);

            if ((float) $lot->quantity <= 0) {
                $lot->quantity = 0;
                $lot->status = 'consumed';
            }

            $lot->save();
            $drawn[] = $lot;

            InventoryMovement::create([
                'operating_unit_id' => $unitId,
                'stock_lot_id' => $lot->id,
                'from_warehouse_id' => $lot->warehouse_id,
                'sku' => $lot->inventoryItem?->sku ?? 'ITEM',
                'movement_type' => 'sale',
                'quantity_delta' => -$take,
                'unit_cost' => (float) $lot->unit_cost,
                'reason' => $reason,
                'reference_document_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);
        }

        return [round($cost, 4), $drawn];
    }

    private function postExternalFulfillment(SalesOrder $order, float $totalCost, array $costByAccount): void
    {
        $price = (float) $order->total_amount;

        // Revenue is owed by the buyer; cost leaves stock. Both sides in one
        // balanced entry (SALE-07).
        $lines = [
            ['account_code' => '1300', 'debit' => $price, 'operating_unit_id' => $order->operating_unit_id],
            ['account_code' => '4100', 'credit' => $price, 'operating_unit_id' => $order->operating_unit_id],
        ];

        if ($totalCost > 0) {
            $lines[] = ['account_code' => '5100', 'debit' => $totalCost, 'operating_unit_id' => $order->operating_unit_id];
            foreach ($costByAccount as $account => $amount) {
                $lines[] = ['account_code' => (string) $account, 'credit' => $amount, 'operating_unit_id' => $order->operating_unit_id];
            }
        }

        $this->accountingService->postJournal(
            "Sales order {$order->order_number} fulfilled",
            $lines,
            'SalesOrder',
            $order->id,
            $order->operatingUnit?->company_id,
        );

        // The client now owes the invoice value (SALE-06).
        if ($order->client_id !== null) {
            $client = Client::whereKey($order->client_id)->lockForUpdate()->first();
            $client->current_balance = round((float) $client->current_balance + $price, 4);
            $client->save();
        }
    }

    private function postInternalTransfer(SalesOrder $order, array $costByAccount): void
    {
        if ($costByAccount === []) {
            return;
        }

        $lines = [];

        foreach ($costByAccount as $account => $amount) {
            // At-cost transfer: no profit between units, only subledger movement.
            $lines[] = ['account_code' => (string) $account, 'debit' => $amount, 'operating_unit_id' => $order->buyer_unit_id];
            $lines[] = ['account_code' => (string) $account, 'credit' => $amount, 'operating_unit_id' => $order->operating_unit_id];
        }

        $this->accountingService->postJournal(
            "Internal transfer {$order->order_number}",
            $lines,
            'SalesOrder',
            $order->id,
            $order->operatingUnit?->company_id,
        );
    }
}
