<?php

declare(strict_types=1);

use App\Enums\PaymentRequestStatus;
use App\Enums\PaymentRoute;
use App\Enums\PurchaseOrderKind;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\OperatingUnit;
use App\Models\PaymentRequest;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unitA = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'Furniture Unit', 'code' => 'FURN-01', 'unit_type' => 'manufactory',
    ]);
    $this->unitB = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'Showroom Unit', 'code' => 'SHOW-01', 'unit_type' => 'showroom',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->managerA = User::factory()->create(['must_change_password' => false]);
    $managerRole = Role::create(['name' => 'Store Manager', 'slug' => 'store-manager']);
    UserRole::create(['user_id' => $this->managerA->id, 'role_id' => $managerRole->id, 'operating_unit_id' => $this->unitA->id]);

    $this->entityClient = Entity::create([
        'name' => 'شركة البناء الراقي',
        'entity_type' => 'organization',
        'phone' => '0910000001',
    ]);
    $this->client = Client::create([
        'entity_id' => $this->entityClient->id,
        'operating_unit_id' => $this->unitA->id,
        'credit_limit' => 50000.0,
        'current_balance' => 0.0,
    ]);

    $this->supplier = Supplier::create([
        'name' => 'مورد الأخشاب الدولي',
        'operating_unit_id' => $this->unitA->id,
        'default_currency' => 'LYD',
    ]);
});

test('client balances calculates total debit, credit, net balance, and aging brackets correctly', function () {
    Carbon::setTestNow('2026-10-05 12:00:00');

    // Order 1: 95 days ago -> 10,000 LYD
    $order1 = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'order_number' => 'SO-001',
        'buyer_type' => 'client',
        'client_id' => $this->client->id,
        'channel' => 'pos',
        'status' => SalesOrderStatus::Open,
        'payment_method' => 'receivable',
        'sold_by_user_id' => $this->owner->id,
    ]);
    $order1->total_amount = 10000.0;
    $order1->created_at = Carbon::parse('2026-10-05')->subDays(95);
    $order1->save();

    // Order 2: 45 days ago -> 5,000 LYD
    $order2 = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'order_number' => 'SO-002',
        'buyer_type' => 'client',
        'client_id' => $this->client->id,
        'channel' => 'pos',
        'status' => SalesOrderStatus::Open,
        'payment_method' => 'receivable',
        'sold_by_user_id' => $this->owner->id,
    ]);
    $order2->total_amount = 5000.0;
    $order2->created_at = Carbon::parse('2026-10-05')->subDays(45);
    $order2->save();

    // Order 3: 10 days ago -> 3,000 LYD
    $order3 = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'order_number' => 'SO-003',
        'buyer_type' => 'client',
        'client_id' => $this->client->id,
        'channel' => 'pos',
        'status' => SalesOrderStatus::Open,
        'payment_method' => 'receivable',
        'sold_by_user_id' => $this->owner->id,
    ]);
    $order3->total_amount = 3000.0;
    $order3->created_at = Carbon::parse('2026-10-05')->subDays(10);
    $order3->save();

    // Payment: 12,000 LYD (pays off Order 1 completely and 2,000 of Order 2)
    $payment = SalePayment::create([
        'operating_unit_id' => $this->unitA->id,
        'sales_order_id' => $order1->id,
        'client_id' => $this->client->id,
        'amount' => 12000.0,
        'method' => 'cash',
        'received_at' => now(),
        'received_by_user_id' => $this->owner->id,
    ]);

    $response = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson('/api/v1/reports/partner-balances?type=clients&as_of=2026-10-05');

    $response->assertOk();
    $data = $response->json();

    expect($data['type'])->toBe('clients')
        ->and($data['summary']['total_receivables'])->toEqual(6000.0)
        ->and($data['summary']['net_exposure'])->toEqual(6000.0)
        ->and(count($data['rows']))->toBe(1);

    $row = $data['rows'][0];
    expect($row['total_debit'])->toEqual(18000.0)
        ->and($row['total_credit'])->toEqual(12000.0)
        ->and($row['net_balance'])->toEqual(6000.0)
        ->and($row['balance_type'])->toBe('debit')
        // Order 1 (10,000) is fully paid by 12,000 credit.
        // Order 2 (5,000) has 2,000 paid -> 3,000 unpaid in 31_60 bracket.
        // Order 3 (3,000) is 0 paid -> 3,000 unpaid in current bracket.
        ->and($row['aging']['current'])->toEqual(3000.0)
        ->and($row['aging']['days_31_60'])->toEqual(3000.0)
        ->and($row['aging']['over_90'])->toEqual(0.0)
        ->and($row['overdue_amount'])->toEqual(3000.0);
});

test('client statement calculates opening balance and chronological running balance', function () {
    Carbon::setTestNow('2026-10-05 12:00:00');

    // Pre-period order (2026-08-01): 4,000 LYD
    $preOrder = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'order_number' => 'SO-PRE',
        'buyer_type' => 'client',
        'client_id' => $this->client->id,
        'channel' => 'pos',
        'status' => SalesOrderStatus::Open,
        'payment_method' => 'receivable',
        'sold_by_user_id' => $this->owner->id,
    ]);
    $preOrder->total_amount = 4000.0;
    $preOrder->created_at = Carbon::parse('2026-08-01 10:00:00');
    $preOrder->save();

    // Period order (2026-09-10): 2,500 LYD
    $periodOrder = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'order_number' => 'SO-CURR',
        'buyer_type' => 'client',
        'client_id' => $this->client->id,
        'channel' => 'pos',
        'status' => SalesOrderStatus::Open,
        'payment_method' => 'receivable',
        'sold_by_user_id' => $this->owner->id,
    ]);
    $periodOrder->total_amount = 2500.0;
    $periodOrder->created_at = Carbon::parse('2026-09-10 11:00:00');
    $periodOrder->save();

    // Period payment (2026-09-20): 1,500 LYD
    $periodPay = SalePayment::create([
        'operating_unit_id' => $this->unitA->id,
        'sales_order_id' => $periodOrder->id,
        'client_id' => $this->client->id,
        'amount' => 1500.0,
        'method' => 'cash',
        'received_at' => Carbon::parse('2026-09-20 12:00:00'),
        'received_by_user_id' => $this->owner->id,
    ]);
    $periodPay->created_at = Carbon::parse('2026-09-20 12:00:00');
    $periodPay->save();

    $response = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson("/api/v1/reports/partner-statement?party_type=client&party_id={$this->client->id}&from=2026-09-01&to=2026-09-30");

    $response->assertOk();
    $data = $response->json();

    expect($data['opening_balance'])->toEqual(4000.0)
        ->and($data['total_debit'])->toEqual(2500.0)
        ->and($data['total_credit'])->toEqual(1500.0)
        ->and($data['closing_balance'])->toEqual(5000.0)
        ->and(count($data['transactions']))->toBe(2)
        ->and($data['transactions'][0]['document_type'])->toBe('sale_order')
        ->and($data['transactions'][0]['running_balance'])->toEqual(6500.0)
        ->and($data['transactions'][1]['document_type'])->toBe('sale_payment')
        ->and($data['transactions'][1]['running_balance'])->toEqual(5000.0);
});

test('supplier balances summary and statement calculates purchases and payments', function () {
    Carbon::setTestNow('2026-10-05 12:00:00');

    // Local PO received: 8,000 LYD
    $po = PurchaseOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'LYD',
        'kind' => PurchaseOrderKind::Local,
        'negotiated_price' => 8000.0,
        'quantity' => 1,
        'status' => PurchaseOrderStatus::Received,
    ]);

    $response = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson('/api/v1/reports/partner-balances?type=suppliers&as_of=2026-10-05');

    $response->assertOk();
    $data = $response->json();

    expect($data['type'])->toBe('suppliers')
        ->and($data['summary']['total_payables'])->toEqual(8000.0)
        ->and($data['rows'][0]['net_balance'])->toEqual(8000.0)
        ->and($data['rows'][0]['balance_type'])->toBe('credit'); // Fine owes supplier

    // Now test supplier statement
    $statementResponse = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson("/api/v1/reports/partner-statement?party_type=supplier&party_id={$this->supplier->id}");

    $statementResponse->assertOk();
    $stData = $statementResponse->json();

    expect($stData['closing_balance'])->toEqual(8000.0)
        ->and(count($stData['transactions']))->toBe(1);
});

test('cross-unit isolation applies to unit-scoped managers', function () {
    // Manager A is pinned to unit A.
    // When querying without company_wide, it should scope to unit A.
    $response = $this->actingAs($this->managerA)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson('/api/v1/reports/partner-balances?type=clients');

    $response->assertOk();
    expect($response->json()['operating_unit_id'])->toBe($this->unitA->id);
});
