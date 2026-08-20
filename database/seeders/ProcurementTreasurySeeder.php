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
use App\Services\AccountingService;
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

        // GL cash mirrors the cash accounts above (1.5M LYD + 250k USD at
        // the seeded 5.20 rate). Without this, the payment advances below
        // would drive 1200 negative on a fresh seed.
        app(AccountingService::class)->postJournal(
            'Opening treasury cash (seed)',
            [
                ['account_code' => '1200', 'debit' => 2800000.00, 'operating_unit_id' => $unit->id, 'memo' => 'LYD treasury + USD clearing safe at 5.20'],
                ['account_code' => '3100', 'credit' => 2800000.00, 'operating_unit_id' => $unit->id],
            ],
        );

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

        // The payment above is seeded already-Paid, so post what
        // executePayment would have: the advance at the settled amount
        // (bank exact-used). Completion later credits 1500 with the same
        // figure, netting the advance to exactly zero.
        app(AccountingService::class)->postJournal(
            "Import payment executed — {$supplier->name} (seed)",
            [
                ['account_code' => '1500', 'debit' => 650000.00, 'operating_unit_id' => $unit->id, 'memo' => $supplier->name],
                ['account_code' => '1200', 'credit' => 650000.00, 'operating_unit_id' => $unit->id],
            ],
            'PaymentRequest',
            $paymentReq->id,
        );

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
        // Booked at 5.05, settled at 5.15 — completing this order in a demo
        // shows the landed cost allocation and a realized FX loss.
        $order2 = ImportOrder::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'negotiated_price' => 85.00,
            'quantity' => 500,
            'booked_fx_rate' => 5.050000,
            'status' => ImportOrderStatus::Received,
        ]);

        // An order cannot reach Received without an executed payment, and
        // completion refuses to post without one — the trail must exist.
        $paymentReq2 = PaymentRequest::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'import_order_id' => $order2->id,
            'route' => PaymentRoute::Market,
            'invoice_ref' => 'INV-CHEM-2026-002',
            'amount_requested' => 42500.00,
            'status' => PaymentRequestStatus::Paid,
            'fx_rate_used' => 5.150000,
        ]);

        // Same rule as order 1: the seeded Paid payment posts its advance
        // (42,500 USD × 5.15 realized = 218,875 LYD), so completing this
        // order in a demo clears 1500 instead of driving it negative.
        app(AccountingService::class)->postJournal(
            "Import payment executed — {$supplier->name} (seed)",
            [
                ['account_code' => '1500', 'debit' => 218875.00, 'operating_unit_id' => $unit->id, 'memo' => $supplier->name],
                ['account_code' => '1200', 'credit' => 218875.00, 'operating_unit_id' => $unit->id],
            ],
            'PaymentRequest',
            $paymentReq2->id,
        );

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
