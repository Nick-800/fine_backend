<?php

declare(strict_types=1);

namespace Database\Seeders\Dummy;

use App\Enums\AllocationPaymentStatus;
use App\Enums\ImportOrderStatus;
use App\Enums\LandedCostType;
use App\Enums\PaymentRequestStatus;
use App\Enums\PaymentRoute;
use App\Models\BankHold;
use App\Models\Company;
use App\Models\ImportOrder;
use App\Models\ImportOrderItem;
use App\Models\InventoryItem;
use App\Models\LandedCostLine;
use App\Models\OperatingUnit;
use App\Models\PayableSettlement;
use App\Models\PaymentRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Extra import orders in various lifecycle states, additional suppliers,
 * payment requests with bank holds, landed cost lines, and payable
 * settlements.
 */
class ProcurementTreasurySeeder extends Seeder
{
    public function run(): void
    {
        $procurement = OperatingUnit::where('name', 'Central Procurement & Treasury')->first();
        if ($procurement === null) {
            return;
        }

        // Extra suppliers
        $suppliers = [
            ['name' => 'Marseille Plastics SARL', 'contact' => 'sales@mplastics.fr', 'currency' => 'EUR', 'address' => 'Marseille, France'],
            ['name' => 'Tunis Textile Industries', 'contact' => 'orders@tunistex.tn', 'currency' => 'EUR', 'address' => 'Tunis, Tunisia'],
            ['name' => 'Istanbul Foam Lines Ltd.', 'contact' => 'export@istfoam.tr', 'currency' => 'USD', 'address' => 'Istanbul, Turkey'],
            ['name' => 'Alexandria Hardware Co.', 'contact' => 'sales@alexhw.eg', 'currency' => 'EGP', 'address' => 'Alexandria, Egypt'],
        ];
        $supplierIds = [];
        foreach ($suppliers as $s) {
            $supplier = Supplier::firstOrCreate(
                ['name' => $s['name']],
                $s + ['id' => (string) Str::uuid(), 'operating_unit_id' => $procurement->id],
            );
            $supplierIds[] = $supplier->id;
        }

        // Extra import orders in different states
        $statuses = [
            ImportOrderStatus::Draft,
            ImportOrderStatus::PendingPayment,
            ImportOrderStatus::AwaitingBankApproval,
            ImportOrderStatus::InTransit,
            ImportOrderStatus::Received,
        ];

        $chemicalItem = InventoryItem::where('sku', 'CHEM-POLYOL-15')->first();

        $treasuryOfficer = User::where('email', 'treasury@erp.com')->first();

        for ($i = 1; $i <= 5; $i++) {
            $supplierId = $supplierIds[($i - 1) % count($supplierIds)];
            $status = $statuses[($i - 1) % count($statuses)];
            $quantity = fake()->numberBetween(100, 2000);
            $price = fake()->randomFloat(4, 60, 220);

            $order = ImportOrder::where('supplier_id', $supplierId)
                ->where('negotiated_price', $price)
                ->where('quantity', $quantity)
                ->first();

            if ($order === null) {
                $order = ImportOrder::create([
                    'id' => (string) Str::uuid(),
                    'operating_unit_id' => $procurement->id,
                    'supplier_id' => $supplierId,
                    'currency' => 'USD',
                    'negotiated_price' => $price,
                    'quantity' => $quantity,
                    'status' => $status,
                ]);
            }

            if ($chemicalItem !== null && ! $order->items()->exists()) {
                ImportOrderItem::create([
                    'id' => (string) Str::uuid(),
                    'import_order_id' => $order->id,
                    'inventory_item_id' => $chemicalItem->id,
                    'quantity' => $order->quantity,
                    'unit_price' => $order->negotiated_price,
                    'currency' => $order->currency,
                ]);
            }

            // Add landed cost lines for orders in transit / received
            if (in_array($status, [ImportOrderStatus::InTransit, ImportOrderStatus::Received])
                && ! LandedCostLine::where('import_order_id', $order->id)->exists()) {
                LandedCostLine::create([
                    'id' => (string) Str::uuid(),
                    'import_order_id' => $order->id,
                    'type' => LandedCostType::Freight,
                    'amount' => fake()->randomFloat(4, 2000, 25_000),
                    'currency' => 'LYD',
                    'status' => $status === ImportOrderStatus::Received ? AllocationPaymentStatus::Paid : AllocationPaymentStatus::Pending,
                    'is_confirmed' => $status === ImportOrderStatus::Received,
                    'approved_by_user_id' => $status === ImportOrderStatus::Received ? $treasuryOfficer?->id : null,
                    'approved_at' => $status === ImportOrderStatus::Received ? now() : null,
                    'paid_by_user_id' => $status === ImportOrderStatus::Received ? $treasuryOfficer?->id : null,
                    'paid_at' => $status === ImportOrderStatus::Received ? now() : null,
                ]);
            }

            // Payment request + bank hold for orders past pending_payment
            if (in_array($status, [
                ImportOrderStatus::AwaitingBankApproval,
                ImportOrderStatus::InTransit,
                ImportOrderStatus::Received,
            ]) && ! PaymentRequest::where('import_order_id', $order->id)->exists()) {
                $prStatus = $status === ImportOrderStatus::AwaitingBankApproval
                    ? PaymentRequestStatus::Pending
                    : PaymentRequestStatus::Paid;

                $paymentRequest = PaymentRequest::create([
                    'id' => (string) Str::uuid(),
                    'operating_unit_id' => $procurement->id,
                    'import_order_id' => $order->id,
                    'route' => PaymentRoute::Bank,
                    'invoice_ref' => 'INV-DUMMY-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                    'amount_requested' => round((float) $order->negotiated_price * (float) $order->quantity, 4),
                    'status' => $prStatus,
                    'fx_rate_used' => 5.20,
                ]);

                if ($status !== ImportOrderStatus::AwaitingBankApproval) {
                    BankHold::create([
                        'id' => (string) Str::uuid(),
                        'payment_request_id' => $paymentRequest->id,
                        'held_amount_lyd' => round((float) $paymentRequest->amount_requested * 5.20, 4),
                        'exact_amount_used' => round((float) $paymentRequest->amount_requested * 5.20, 4),
                        'released_amount' => 0,
                        'bank_reference' => 'BNK-DUMMY-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                    ]);
                } else {
                    BankHold::create([
                        'id' => (string) Str::uuid(),
                        'payment_request_id' => $paymentRequest->id,
                        'held_amount_lyd' => round((float) $paymentRequest->amount_requested * 5.20 * 1.05, 4),
                        'exact_amount_used' => 0,
                        'released_amount' => 0,
                    ]);
                }
            }
        }

        // A couple of payable settlements so the treasury reports have rows
        $company = Company::first();
        for ($i = 1; $i <= 3; $i++) {
            PayableSettlement::firstOrCreate(
                ['reference' => 'SET-DUMMY-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)],
                [
                    'id' => (string) Str::uuid(),
                    'company_id' => $company?->id,
                    'operating_unit_id' => $procurement->id,
                    'account_code' => '2100',
                    'amount' => fake()->randomFloat(4, 1000, 25_000),
                    'settled_by_user_id' => $treasuryOfficer?->id,
                    'settled_at' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
                ],
            );
        }
    }
}
