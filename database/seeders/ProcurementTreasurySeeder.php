<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ImportOrderStatus;
use App\Enums\LandedCostType;
use App\Enums\PaymentRequestStatus;
use App\Enums\PaymentRoute;
use App\Models\BankHold;
use App\Models\CashAccount;
use App\Models\FxRate;
use App\Models\GoodsReceipt;
use App\Models\ImportOrder;
use App\Models\LandedCostLine;
use App\Models\OperatingUnit;
use App\Models\PaymentRequest;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class ProcurementTreasurySeeder extends Seeder
{
    public function run(): void
    {
        $unit = OperatingUnit::first();

        if (! $unit) {
            return;
        }

        // 1. Seed FX Rates
        FxRate::create([
            'id' => (string) Str::uuid(),
            'from_currency' => 'USD',
            'to_currency' => 'LYD',
            'rate' => 5.200000,
            'captured_at' => now(),
        ]);

        FxRate::create([
            'id' => (string) Str::uuid(),
            'from_currency' => 'EUR',
            'to_currency' => 'LYD',
            'rate' => 5.650000,
            'captured_at' => now(),
        ]);

        // 2. Seed Cash Accounts
        CashAccount::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'name' => 'Main Operating Cash Treasury',
            'currency' => 'LYD',
            'balance' => 1500000.00,
        ]);

        CashAccount::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'name' => 'Foreign Exchange Clearing Safe',
            'currency' => 'USD',
            'balance' => 250000.00,
        ]);

        // 3. Seed Supplier
        $supplier = Supplier::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'name' => 'Global Chemical & Polymer Corp',
            'contact' => 'orders@globalchem.com',
            'default_currency' => 'USD',
            'address' => 'Istanbul Industrial Park, Turkey',
        ]);

        // 4. Seed Import Order 1 (In Transit with Bank Hold)
        $order1 = ImportOrder::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'negotiated_price' => 125.00,
            'quantity' => 1000,
            'status' => ImportOrderStatus::InTransit,
        ]);

        $paymentReq = PaymentRequest::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'import_order_id' => $order1->id,
            'route' => PaymentRoute::Bank,
            'invoice_ref' => 'INV-CHEM-2026-001',
            'amount_requested' => 125000.00,
            'status' => PaymentRequestStatus::Paid,
            'fx_rate_used' => 5.200000,
        ]);

        BankHold::create([
            'id' => (string) Str::uuid(),
            'payment_request_id' => $paymentReq->id,
            'held_amount_lyd' => 675000.00,
            'exact_amount_used' => 650000.00,
            'released_amount' => 25000.00,
            'bank_reference' => 'BNK-REF-TRIPOLI-901',
        ]);

        LandedCostLine::create([
            'id' => (string) Str::uuid(),
            'import_order_id' => $order1->id,
            'type' => LandedCostType::Freight,
            'amount' => 18500.00,
            'currency' => 'LYD',
            'is_confirmed' => true,
        ]);

        LandedCostLine::create([
            'id' => (string) Str::uuid(),
            'import_order_id' => $order1->id,
            'type' => LandedCostType::Customs,
            'amount' => 12000.00,
            'currency' => 'LYD',
            'is_confirmed' => true,
        ]);

        // 5. Seed Import Order 2 (Received at Warehouse)
        $order2 = ImportOrder::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'negotiated_price' => 85.00,
            'quantity' => 500,
            'status' => ImportOrderStatus::Received,
        ]);

        $warehouse = Warehouse::where('operating_unit_id', $unit->id)->first() ?? Warehouse::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'name' => 'Central Import Depot',
            'is_internal_unit' => true,
        ]);

        GoodsReceipt::create([
            'id' => (string) Str::uuid(),
            'import_order_id' => $order2->id,
            'warehouse_id' => $warehouse->id,
            'received_qty' => 500,
            'condition_notes' => 'Received 500 chemical drums sealed in top grade quality.',
        ]);
    }
}
