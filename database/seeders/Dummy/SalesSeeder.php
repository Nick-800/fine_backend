<?php

declare(strict_types=1);

namespace Database\Seeders\Dummy;

use App\Enums\SaleFulfillmentStatus;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\CreditApprovalRequest;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\PosDailyClose;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * POS sales in every realistic state — completed cash and bank sales, open
 * receivable sales, and one over-limit sale waiting on a credit approval —
 * plus a couple of POS daily closes.
 *
 * SaleCheckoutService draws stock and posts the journal on a real checkout;
 * this seeder only lands rows at the right states for dashboards and
 * reports without driving the full pipeline from the seeder side.
 */
class SalesSeeder extends Seeder
{
    public function run(): void
    {
        $showroom = OperatingUnit::where('name', 'صالة العرض')->first();

        if ($showroom === null) {
            return;
        }

        $clients = Client::where('operating_unit_id', $showroom->id)->limit(6)->get();
        if ($clients->isEmpty()) {
            return;
        }

        $finishedItems = InventoryItem::whereIn('code', [
            'FG-MATTRESS-Q', 'FG-MATTRESS-K', 'FG-CUSHION-SET',
            'FG-SOFA-LEFT', 'FG-BOLSTER', 'FG-BASE-Q',
        ])->get();

        if ($finishedItems->isEmpty()) {
            return;
        }

        $treasuries = CashAccount::where('operating_unit_id', $showroom->id)->get()->keyBy('kind');

        $lots = StockLot::whereIn('inventory_item_id', $finishedItems->pluck('id'))
            ->where('status', 'available')
            ->get()
            ->keyBy('inventory_item_id');

        $plans = [
            ['state' => 'completed', 'method' => 'cash'],
            ['state' => 'completed', 'method' => 'cash'],
            ['state' => 'completed', 'method' => 'bank'],
            ['state' => 'open', 'method' => 'receivable'],
            ['state' => 'open', 'method' => 'receivable'],
            ['state' => 'pending_approval', 'method' => 'receivable'],
        ];

        $sequence = 1;

        foreach ($plans as $plan) {
            $client = $clients->random();
            $items = $finishedItems->shuffle()->take(min(2, $finishedItems->count()));
            $orderNumber = 'S-DUMMY-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
            $sequence++;
            $treasury = $plan['method'] === 'receivable' ? null : $treasuries->get($plan['method']);
            $isSold = $plan['state'] !== 'pending_approval';

            $order = SalesOrder::firstOrCreate(
                ['order_number' => $orderNumber],
                [
                    'id' => (string) Str::uuid(),
                    'operating_unit_id' => $showroom->id,
                    'buyer_type' => 'client',
                    'client_id' => $client->id,
                    'channel' => 'pos',
                    'status' => $plan['state'],
                    'fulfillment_status' => $isSold ? SaleFulfillmentStatus::Delivered : null,
                    'payment_method' => $plan['method'],
                    'cash_account_id' => $treasury?->id,
                ],
            );

            if ($order->lines()->exists()) {
                continue;
            }

            $totalAmount = 0;
            $totalCost = 0;

            foreach ($items as $item) {
                $lot = $lots->get($item->id);
                $unitPrice = fake()->randomFloat(4, 80, 1200);
                $unitCost = $lot ? (float) $lot->unit_cost : $unitPrice * 0.65;
                $quantity = fake()->numberBetween(1, 3);

                SalesOrderLine::create([
                    'id' => (string) Str::uuid(),
                    'sales_order_id' => $order->id,
                    'line_type' => SalesOrderLine::TYPE_ITEM,
                    'description' => $item->name,
                    'inventory_item_id' => $item->id,
                    'stock_lot_id' => $lot?->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'unit_cost_actual' => $isSold ? $unitCost : 0,
                ]);

                $totalAmount += $unitPrice * $quantity;
                $totalCost += $unitCost * $quantity;
            }

            $order->total_amount = round($totalAmount, 4);
            $order->total_cost = $isSold ? round($totalCost, 4) : 0;
            $order->amount_paid = $plan['state'] === 'completed' ? round($totalAmount, 4) : 0;
            $order->save();

            if ($plan['state'] === 'completed') {
                SalePayment::create([
                    'operating_unit_id' => $showroom->id,
                    'sales_order_id' => $order->id,
                    'client_id' => $client->id,
                    'amount' => round($totalAmount, 4),
                    'method' => $plan['method'],
                    'cash_account_id' => $treasury?->id,
                    'received_at' => now(),
                ]);
            }

            if ($plan['state'] === 'pending_approval') {
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
