<?php

declare(strict_types=1);

use App\Enums\PurchaseOrderKind;
use App\Enums\PurchaseOrderStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

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

    /** Posts one receivable line for the client in unit A against revenue (41). */
    $this->postClientLine = function (string $date, float $debit = 0, float $credit = 0, ?string $sourceType = null): void {
        app(AccountingService::class)->postJournal('Client entry', [
            ['account_code' => '122', 'debit' => $debit, 'credit' => $credit, 'client_id' => $this->client->id, 'operating_unit_id' => $this->unitA->id],
            ['account_code' => '40201', 'debit' => $credit, 'credit' => $debit, 'operating_unit_id' => $this->unitA->id],
        ], $sourceType, $sourceType !== null ? (string) Str::uuid() : null, entryDate: $date, isManual: true);
    };

    $this->supplier = Supplier::create([
        'name' => 'مورد الأخشاب الدولي',
        'operating_unit_id' => $this->unitA->id,
        'default_currency' => 'LYD',
    ]);
});

test('client balances calculates total debit, credit, net balance, and aging brackets correctly', function () {
    Carbon::setTestNow('2026-10-05 12:00:00');

    ($this->postClientLine)('2026-07-02', debit: 10000); // 95 days before as_of
    ($this->postClientLine)('2026-08-21', debit: 5000);  // 45 days
    ($this->postClientLine)('2026-09-25', debit: 3000);  // 10 days
    // Payment of 12,000 pays off the first debit and 2,000 of the second.
    ($this->postClientLine)('2026-10-05', credit: 12000);

    $response = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson('/api/v1/reports/partner-balances?type=clients&as_of=2026-10-05');

    $response->assertOk();
    $data = $response->json();

    expect($data['type'])->toBe('clients')
        ->and($data['summary']['total_receivables'])->toEqual(6000.0)
        ->and($data['summary']['net_exposure'])->toEqual(6000.0)
        ->and($data['summary']['overdue_total'])->toEqual(3000.0)
        ->and(count($data['rows']))->toBe(1);

    $row = $data['rows'][0];
    expect(array_keys($row))->toBe([
        'id', 'name', 'phone', 'city', 'operating_unit_id', 'operating_unit_name', 'credit_limit', 'payment_terms_days',
        'total_debit', 'total_credit', 'net_balance', 'balance_type', 'aging', 'overdue_amount', 'status',
    ])
        ->and(array_keys($data['summary']))->toBe(['total_receivables', 'total_credits_or_advances', 'net_exposure', 'overdue_total', 'total_count'])
        ->and($row['total_debit'])->toEqual(18000.0)
        ->and($row['total_credit'])->toEqual(12000.0)
        ->and($row['net_balance'])->toEqual(6000.0)
        ->and($row['balance_type'])->toBe('debit')
        // The 5,000 debit has 2,000 paid -> 3,000 unpaid in the 31_60 bracket.
        // The 3,000 debit is unpaid -> 3,000 in the current bracket.
        ->and($row['aging']['current'])->toEqual(3000.0)
        ->and($row['aging']['days_31_60'])->toEqual(3000.0)
        ->and($row['aging']['over_90'])->toEqual(0.0)
        ->and($row['overdue_amount'])->toEqual(3000.0);
});

test('client balances lists idle clients in scope with zero figures', function () {
    Carbon::setTestNow('2026-10-05 12:00:00');

    $idleEntity = Entity::create(['name' => 'عميل بلا حركة', 'entity_type' => 'organization']);
    $idle = Client::create(['entity_id' => $idleEntity->id, 'operating_unit_id' => $this->unitA->id, 'credit_limit' => 0, 'current_balance' => 0]);
    ($this->postClientLine)('2026-09-25', debit: 3000);

    $rows = collect($this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson('/api/v1/reports/partner-balances?type=clients&as_of=2026-10-05')
        ->assertOk()
        ->json('rows'));

    expect($rows->pluck('id')->all())->toBe([$this->client->id, $idle->id]);

    $idleRow = $rows->firstWhere('id', $idle->id);
    expect($idleRow['net_balance'])->toEqual(0.0)
        ->and($idleRow['balance_type'])->toBe('settled')
        ->and($idleRow['overdue_amount'])->toEqual(0.0)
        ->and($idleRow['aging'])->toEqual(['current' => 0.0, 'days_31_60' => 0.0, 'days_61_90' => 0.0, 'over_90' => 0.0]);
});

test('client statement calculates opening balance and chronological running balance', function () {
    Carbon::setTestNow('2026-10-05 12:00:00');

    ($this->postClientLine)('2026-08-01', debit: 4000, sourceType: 'SalesOrder');  // before the period
    ($this->postClientLine)('2026-09-10', debit: 2500, sourceType: 'SalesOrder');  // sale on terms
    ($this->postClientLine)('2026-09-20', credit: 1500, sourceType: 'SalesOrder'); // collection
    ($this->postClientLine)('2026-09-25', debit: 0.5);                              // manual adjustment

    $response = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson("/api/v1/reports/partner-statement?party_type=client&party_id={$this->client->id}&from=2026-09-01&to=2026-09-30");

    $response->assertOk();
    $data = $response->json();

    expect(array_keys($data))->toBe(['party', 'period', 'opening_balance', 'closing_balance', 'total_debit', 'total_credit', 'net_change', 'transactions'])
        ->and(array_keys($data['party']))->toBe(['id', 'name', 'phone', 'address', 'type', 'currency', 'operating_unit_id', 'operating_unit_name', 'credit_limit'])
        ->and($data['period'])->toBe(['from' => '2026-09-01', 'to' => '2026-09-30'])
        ->and($data['opening_balance'])->toEqual(4000.0)
        ->and($data['total_debit'])->toEqual(2500.5)
        ->and($data['total_credit'])->toEqual(1500.0)
        ->and($data['net_change'])->toEqual(1000.5)
        ->and($data['closing_balance'])->toEqual(5000.5)
        ->and(count($data['transactions']))->toBe(3)
        ->and(array_keys($data['transactions'][0]))->toBe([
            'id', 'date', 'document_type', 'document_type_label', 'document_number', 'memo', 'debit', 'credit', 'running_balance',
        ])
        ->and($data['transactions'][0]['date'])->toBe('2026-09-10')
        ->and($data['transactions'][0]['document_type'])->toBe('sale_order')
        ->and($data['transactions'][0]['document_type_label'])->toBe('طلب مبيعات آجل')
        ->and($data['transactions'][0]['running_balance'])->toEqual(6500.0)
        ->and($data['transactions'][1]['document_type'])->toBe('sale_payment')
        ->and($data['transactions'][1]['document_type_label'])->toBe('سداد دفعة')
        ->and($data['transactions'][1]['running_balance'])->toEqual(5000.0)
        ->and($data['transactions'][2]['document_type'])->toBe('journal_entry')
        ->and($data['transactions'][2]['document_type_label'])->toBe('قيد يومية')
        ->and($data['transactions'][2]['running_balance'])->toEqual(5000.5);

    $entry = JournalEntry::findOrFail($data['transactions'][0]['id']);
    expect($data['transactions'][0]['document_number'])->toBe($entry->reference);
});

test('client statement without dates covers the whole history up to today', function () {
    Carbon::setTestNow('2026-10-05 12:00:00');

    ($this->postClientLine)('2026-08-01', debit: 4000, sourceType: 'SalesOrder');
    ($this->postClientLine)('2026-10-05', credit: 1000, sourceType: 'SalesOrder');
    ($this->postClientLine)('2026-10-06', debit: 999); // after today

    $data = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson("/api/v1/reports/partner-statement?party_type=client&party_id={$this->client->id}")
        ->assertOk()
        ->json();

    expect($data['period'])->toBe(['from' => null, 'to' => null])
        ->and($data['opening_balance'])->toEqual(0.0)
        ->and(count($data['transactions']))->toBe(2)
        ->and($data['closing_balance'])->toEqual(3000.0);
});

test('client statement returns an idle client with zero balances', function () {
    Carbon::setTestNow('2026-10-05 12:00:00');

    $data = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson("/api/v1/reports/partner-statement?party_type=client&party_id={$this->client->id}&from=2026-09-01&to=2026-09-30")
        ->assertOk()
        ->json();

    expect($data['party']['id'])->toBe($this->client->id)
        ->and($data['party']['name'])->toBe('شركة البناء الراقي')
        ->and($data['opening_balance'])->toEqual(0.0)
        ->and($data['closing_balance'])->toEqual(0.0)
        ->and($data['transactions'])->toBe([]);
});

test('client statement keeps an idle overpaid balance', function () {
    Carbon::setTestNow('2026-10-05 12:00:00');

    ($this->postClientLine)('2026-08-01', credit: 500);

    $data = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson("/api/v1/reports/partner-statement?party_type=client&party_id={$this->client->id}&from=2026-09-01&to=2026-09-30")
        ->assertOk()
        ->json();

    expect($data['opening_balance'])->toEqual(-500.0)
        ->and($data['closing_balance'])->toEqual(-500.0)
        ->and($data['transactions'])->toBe([]);
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
