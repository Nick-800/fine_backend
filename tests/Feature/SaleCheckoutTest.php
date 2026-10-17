<?php

declare(strict_types=1);

use App\Enums\InventoryEventType;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\CreditApprovalRequest;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\OperatingUnitAccount;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Test Company', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Store', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);

    $makeUnit = fn (string $name) => OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => $name, 'unit_type' => 'store', 'currency' => 'LYD', 'status' => 'active',
    ]);

    $this->unitA = $makeUnit('Showroom A');
    $this->unitB = $makeUnit('Showroom B');

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $this->item = InventoryItem::create([
        'name' => 'Sofa', 'code' => 'SOFA-01', 'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'piece',
    ]);

    $warehouse = Warehouse::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Store WH', 'code' => 'WH-A']);

    foreach ([
        [InventoryEventType::Purchases, '1134'],
        [InventoryEventType::Sales, '41'],
        [InventoryEventType::Cogs, '51'],
    ] as [$event, $code]) {
        OperatingUnitAccount::create([
            'operating_unit_id' => $this->unitA->id,
            'event_type' => $event->value,
            'account_id' => Account::where('account_code', $code)->sole()->id,
        ]);
    }

    StockLot::create([
        'warehouse_id' => $warehouse->id, 'inventory_item_id' => $this->item->id,
        'lot_number' => 'LOT-01', 'quantity' => 10, 'unit_cost' => 50, 'status' => 'available',
    ]);

    $this->drawer = CashAccount::create([
        'operating_unit_id' => $this->unitA->id, 'name' => 'Drawer', 'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);

    $entity = Entity::create(['entity_type' => 'organization', 'name' => 'Al-Waha Traders']);
    $this->client = Client::create([
        'entity_id' => $entity->id, 'operating_unit_id' => $this->unitA->id,
        'credit_limit' => 1000, 'current_balance' => 0,
    ]);

    $this->api = fn (OperatingUnit $unit) => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $unit->id]);

    $this->sell = fn (string $method = 'cash', float $quantity = 1, float $price = 100) => ($this->api)($this->unitA)->postJson('/api/v1/sales', array_filter([
        'client_id' => $this->client->id,
        'payment_method' => $method,
        'cash_account_id' => $method === 'cash' ? $this->drawer->id : null,
        'lines' => [['inventory_item_id' => $this->item->id, 'quantity' => $quantity, 'unit_price' => $price]],
    ]));

    $this->lot = StockLot::withoutGlobalScopes()->where('lot_number', 'LOT-01')->sole();
    $this->accountId = fn (string $code) => Account::where('account_code', $code)->value('id');
    $this->lineSum = fn (string $orderId, string $code, string $side) => (float) JournalLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('source_document_id', $orderId))
        ->where('account_id', ($this->accountId)($code))
        ->sum($side);
});

test('a cash sale deducts stock, debits the drawer and posts revenue and cost', function () {
    $sale = ($this->sell)('cash', 2, 100)->assertCreated()->json();

    expect((float) $this->lot->fresh()->quantity)->toBe(8.0)
        ->and((float) $sale['amount_paid'])->toBe(200.0)
        ->and(($this->lineSum)($sale['id'], '121102', 'debit'))->toBe(200.0)
        ->and(($this->lineSum)($sale['id'], '41', 'credit'))->toBe(200.0)
        ->and(($this->lineSum)($sale['id'], '51', 'debit'))->toBe(100.0);
});

test('a receivable sale over the credit limit waits for approval and moves nothing', function () {
    $sale = ($this->sell)('receivable', 3, 400)->assertCreated()->json();

    $approval = CreditApprovalRequest::withoutGlobalScopes()->where('sales_order_id', $sale['id'])->sole();

    expect($sale['status'])->toBe('pending_approval')
        ->and($approval->status)->toBe('pending')
        ->and((float) $approval->amount_over_limit)->toBe(200.0)
        ->and((float) $this->lot->fresh()->quantity)->toBe(10.0)
        ->and((float) $this->client->fresh()->current_balance)->toBe(0.0);
});

test('approving the credit request completes the sale on account', function () {
    $sale = ($this->sell)('receivable', 3, 400)->assertCreated()->json();
    $approval = CreditApprovalRequest::withoutGlobalScopes()->where('sales_order_id', $sale['id'])->sole();

    ($this->api)($this->unitA)->putJson("/api/v1/credit-approval-requests/{$approval->id}/approve")->assertOk();

    expect(SalesOrder::withoutGlobalScopes()->find($sale['id'])->status->value)->toBe('open')
        ->and((float) $this->lot->fresh()->quantity)->toBe(7.0)
        ->and((float) $this->client->fresh()->current_balance)->toBe(1200.0);
});

test('rejecting the credit request leaves stock and balance untouched', function () {
    $sale = ($this->sell)('receivable', 3, 400)->assertCreated()->json();
    $approval = CreditApprovalRequest::withoutGlobalScopes()->where('sales_order_id', $sale['id'])->sole();

    ($this->api)($this->unitA)->putJson("/api/v1/credit-approval-requests/{$approval->id}/reject")->assertOk();

    expect(SalesOrder::withoutGlobalScopes()->find($sale['id'])->status->value)->toBe('rejected')
        ->and((float) $this->lot->fresh()->quantity)->toBe(10.0)
        ->and((float) $this->client->fresh()->current_balance)->toBe(0.0);
});

test('a partial collection reduces the client balance and the outstanding amount', function () {
    $sale = ($this->sell)('receivable', 2, 100)->assertCreated()->json();

    $after = ($this->api)($this->unitA)->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'amount' => 50,
        'method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])->assertSuccessful()->json();

    expect((float) $after['amount_paid'])->toBe(50.0)
        ->and((float) $this->client->fresh()->current_balance)->toBe(150.0);
});

test('the daily close compares counted cash with cash actually received', function () {
    ($this->sell)('cash', 2, 100)->assertCreated();
    $credit = ($this->sell)('receivable', 1, 100)->assertCreated()->json();
    ($this->api)($this->unitA)->postJson("/api/v1/sales/{$credit['id']}/payments", [
        'amount' => 50, 'method' => 'cash', 'cash_account_id' => $this->drawer->id,
    ])->assertSuccessful();

    $close = ($this->api)($this->unitA)->postJson('/api/v1/pos/daily-close', ['counted_cash' => 240])
        ->assertCreated()->json();

    expect((float) $close['expected_cash'])->toBe(250.0)
        ->and((float) $close['difference'])->toBe(-10.0)
        ->and((int) $close['sales_count'])->toBe(2)
        ->and((float) $close['total_sales'])->toBe(300.0);
});
