<?php

declare(strict_types=1);

use App\Enums\InventoryEventType;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\OperatingUnitAccount;
use App\Models\PosDailyClose;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\PosDayGuard;
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
        'credit_limit' => 10000, 'current_balance' => 0,
    ]);

    $this->api = fn (OperatingUnit $unit) => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $unit->id]);

    $this->sell = fn (string $method = 'cash') => ($this->api)($this->unitA)->postJson('/api/v1/sales', array_filter([
        'client_id' => $this->client->id,
        'payment_method' => $method,
        'cash_account_id' => $method === 'cash' ? $this->drawer->id : null,
        'lines' => [['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100]],
    ]));

    $this->closeToday = fn (OperatingUnit $unit) => PosDailyClose::withoutGlobalScopes()->create([
        'operating_unit_id' => $unit->id,
        'close_date' => app(PosDayGuard::class)->today($unit),
        'expected_cash' => 0,
        'counted_cash' => 0,
        'difference' => 0,
        'sales_count' => 0,
        'total_sales' => 0,
        'closed_by_user_id' => $this->user->id,
    ]);
});

test('a sale on a closed register day is refused and creates nothing', function () {
    ($this->closeToday)($this->unitA);

    ($this->sell)()
        ->assertStatus(422)
        ->assertJsonPath('code', 'POS_DAY_ALREADY_CLOSED');

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(0);
});

test('closing another unit does not block this one', function () {
    ($this->closeToday)($this->unitB);

    ($this->sell)()->assertCreated();
});

test('collecting a receivable on a closed day is refused', function () {
    $sale = ($this->sell)('receivable')->assertCreated()->json();

    ($this->closeToday)($this->unitA);

    ($this->api)($this->unitA)->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'amount' => 50,
        'method' => 'cash',
        'cash_account_id' => $this->drawer->id,
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'POS_DAY_ALREADY_CLOSED');
});

test('two units can each close the same day', function () {
    ($this->api)($this->unitA)->postJson('/api/v1/pos/daily-close', ['counted_cash' => 0])->assertSuccessful();
    ($this->api)($this->unitB)->postJson('/api/v1/pos/daily-close', ['counted_cash' => 0])->assertSuccessful();

    expect(PosDailyClose::withoutGlobalScopes()->count())->toBe(2);
});
