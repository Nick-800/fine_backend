<?php

declare(strict_types=1);

namespace Database\Seeders\Dummy;

use App\Models\Client;
use App\Models\CreditApprovalRequest;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\PosDailyClose;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sales orders in every realistic mid-flow state (draft, pending approval
 * with a credit approval request, confirmed, fulfilled, paid), plus a
 * couple of POS daily closes.
 *
 * SalesOrderService enforces state transitions and inventory draws at every
 * step, so this seeder lands rows at the right states for dashboards and
 * reports without trying to drive the full credit-check / fulfilment
 * pipeline from the seeder side.
 */
class SalesSeeder extends Seeder
{
    public function run(): void
    {
        $showroom = OperatingUnit::where('name', 'صالة العرض')->first();
        $storeManager = User::where('email', 'store-manager@erp.com')->first() ?? User::where('email', 'showroom@erp.com')->first();

        if ($showroom === null) {
            return;
        }

        $clients = Client::where('operating_unit_id', $showroom->id)->limit(6)->get();
        if ($clients->isEmpty()) {
            return;
        }

        $finishedItems = InventoryItem::whereIn('sku', [
            'FG-MATTRESS-Q', 'FG-MATTRESS-K', 'FG-CUSHION-SET',
            'FG-SOFA-LEFT', 'FG-BOLSTER', 'FG-BASE-Q',
        ])->get();

        if ($finishedItems->isEmpty()) {
            return;
        }

        $lots = StockLot::whereIn('inventory_item_id', $finishedItems->pluck('id'))
            ->where('status', 'available')
            ->get()
            ->keyBy('inventory_item_id');

        // Walk a handful of orders through every interesting state.
        $plans = [
            ['state' => 'draft', 'channel' => 'standard'],
            ['state' => 'draft', 'channel' => 'pos'],
            ['state' => 'confirmed', 'channel' => 'standard'],
            ['state' => 'confirmed', 'channel' => 'standard'],
            ['state' => 'fulfilled', 'channel' => 'standard'],
            ['state' => 'paid', 'channel' => 'standard'],
            ['state' => 'paid', 'channel' => 'pos'],
            ['state' => 'pending_approval', 'channel' => 'standard'],
        ];

        $sequence = 1;

        foreach ($plans as $plan) {
            $client = $clients->random();
            $items = $finishedItems->shuffle()->take(min(2, $finishedItems->count()));
            $orderNumber = 'SO-DUMMY-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
            $sequence++;

            $buyerType = $plan['channel'] === 'pos' ? 'walk_in' : 'client';
            $clientId = $buyerType === 'client' ? $client->id : null;

            $order = SalesOrder::firstOrCreate(
                ['order_number' => $orderNumber],
                [
                    'id' => (string) Str::uuid(),
                    'operating_unit_id' => $showroom->id,
                    'buyer_type' => $buyerType,
                    'client_id' => $clientId,
                    'channel' => $plan['channel'],
                    'status' => $plan['state'],
                    'payment_method' => $plan['channel'] === 'pos' ? 'cash' : 'cash',
                ],
            );

            $hasLines = $order->lines()->exists();
            $totalAmount = 0;
            $totalCost = 0;

            if (! $hasLines) {
                foreach ($items as $item) {
                    $lot = $lots->get($item->id);
                    $unitPrice = fake()->randomFloat(4, 80, 1200);
                    $unitCost = $lot ? (float) $lot->unit_cost : $unitPrice * 0.65;
                    $quantity = fake()->numberBetween(1, 3);

                    SalesOrderLine::create([
                        'id' => (string) Str::uuid(),
                        'sales_order_id' => $order->id,
                        'inventory_item_id' => $item->id,
                        'stock_lot_id' => $lot?->id,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'unit_cost_actual' => $plan['state'] === 'fulfilled' || $plan['state'] === 'paid' ? $unitCost : 0,
                    ]);

                    $totalAmount += $unitPrice * $quantity;
                    $totalCost += $unitCost * $quantity;
                }

                $order->total_amount = round($totalAmount, 4);
                $order->total_cost = round($totalCost, 4);
                if (in_array($plan['state'], ['paid'])) {
                    $order->amount_paid = round($totalAmount, 4);
                }
                $order->save();
            }

            if ($plan['state'] === 'pending_approval' && ! $order->creditApprovalRequest()->exists()) {
                CreditApprovalRequest::create([
                    'id' => (string) Str::uuid(),
                    'sales_order_id' => $order->id,
                    'amount_over_limit' => round($totalAmount * 0.25, 4),
                    'status' => 'pending',
                ]);
            }
        }

        // POS daily closes for the last few days
        $cashier = User::where('email', 'cashier@erp.com')->first();
        for ($daysAgo = 0; $daysAgo < 3; $daysAgo++) {
            $closeDate = now()->subDays($daysAgo)->toDateString();
            $expected = fake()->randomFloat(4, 800, 6000);

            $existing = PosDailyClose::where('operating_unit_id', $showroom->id)
                ->whereDate('close_date', $closeDate)
                ->first();

            if ($existing !== null) {
                continue;
            }

            PosDailyClose::create([
                'id' => (string) Str::uuid(),
                'operating_unit_id' => $showroom->id,
                'close_date' => $closeDate,
                'expected_cash' => $expected,
                'counted_cash' => $expected + fake()->randomFloat(4, -50, 50),
                'difference' => 0,
                'sales_count' => fake()->numberBetween(5, 20),
                'total_sales' => $expected,
                'notes' => 'Dummy POS close.',
                'closed_by_user_id' => $cashier?->id,
            ]);
        }
    }
}
