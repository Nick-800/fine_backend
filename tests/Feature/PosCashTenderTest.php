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
use App\Models\Role;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->company = Company::create(['name' => 'Fine Test Co', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Store',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $blueprint->id,
        'name' => 'Main Showroom',
        'unit_type' => 'store',
        'currency' => 'LYD',
        'status' => 'active',
    ]);
    linkRevenueAccount($this->unit, '40204');

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $this->item = InventoryItem::create([
        'name' => 'Chair',
        'code' => 'CHR-01',
        'item_type' => 'furniture_finished_good',
        'unit_of_measure' => 'piece',
    ]);

    $warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Showroom WH',
        'code' => 'WH-SR',
    ]);

    foreach ([
        [InventoryEventType::Purchases, '1134'],
        [InventoryEventType::Sales, '40204'],
        [InventoryEventType::Cogs, '51'],
    ] as [$event, $code]) {
        OperatingUnitAccount::create([
            'operating_unit_id' => $this->unit->id,
            'event_type' => $event->value,
            'account_id' => Account::where('account_code', $code)->sole()->id,
        ]);
    }

    $this->lot = StockLot::create([
        'warehouse_id' => $warehouse->id,
        'inventory_item_id' => $this->item->id,
        'lot_number' => 'LOT-TENDER-1',
        'quantity' => 20,
        'unit_cost' => 40,
        'status' => 'available',
    ]);

    $this->drawer = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Cash Drawer',
        'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);

    $entity = Entity::create(['entity_type' => 'organization', 'name' => 'Tripoli Decor']);
    $this->client = Client::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'credit_limit' => 5000,
        'current_balance' => 0,
    ]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);
});

test('tender greater than or equal to total stores change and tendered amount', function (): void {
    $response = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'tendered_amount' => 250,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100],
        ],
    ])->assertCreated();

    $response->assertJsonPath('amount_paid', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonPath('payments.0.tendered_amount', fn ($v): bool => (float) $v === 250.0)
        ->assertJsonPath('payments.0.change_given', fn ($v): bool => (float) $v === 50.0);

    $payment = SalePayment::withoutGlobalScopes()->where('sales_order_id', $response->json('id'))->sole();
    expect((float) $payment->tendered_amount)->toBe(250.0)
        ->and((float) $payment->change_given)->toBe(50.0);
});

test('tender below total is refused and nothing is written', function (): void {
    $initialSalesCount = SalesOrder::withoutGlobalScopes()->count();
    $initialPaymentsCount = SalePayment::withoutGlobalScopes()->count();
    $initialLotQty = (float) $this->lot->fresh()->quantity;

    ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'tendered_amount' => 180,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 100],
        ],
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'TENDER_BELOW_TOTAL')
        ->assertJsonPath('total', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonPath('tendered', fn ($v): bool => (float) $v === 180.0);

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe($initialSalesCount)
        ->and(SalePayment::withoutGlobalScopes()->count())->toBe($initialPaymentsCount)
        ->and((float) $this->lot->fresh()->quantity)->toBe($initialLotQty);
});

test('absent tender leaves behaviour unchanged with null tender fields', function (): void {
    $response = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100],
        ],
    ])->assertCreated();

    $response->assertJsonPath('amount_paid', fn ($v): bool => (float) $v === 100.0)
        ->assertJsonPath('payments.0.tendered_amount', null)
        ->assertJsonPath('payments.0.change_given', null);

    $payment = SalePayment::withoutGlobalScopes()->where('sales_order_id', $response->json('id'))->sole();
    expect($payment->tendered_amount)->toBeNull()
        ->and($payment->change_given)->toBeNull();
});

test('collection with tender records change or refuses tender below amount', function (): void {
    $sale = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'receivable',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 3, 'unit_price' => 100],
        ],
    ])->assertCreated()->json();

    // Tender below collection amount is refused
    ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'amount' => 150,
        'method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'tendered_amount' => 100,
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'TENDER_BELOW_TOTAL');

    expect(SalePayment::withoutGlobalScopes()->where('sales_order_id', $sale['id'])->count())->toBe(0);

    // Valid tender records tendered_amount and change_given
    $collection = ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'amount' => 150,
        'method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'tendered_amount' => 200,
    ])->assertSuccessful();

    $collection->assertJsonPath('amount_paid', fn ($v): bool => (float) $v === 150.0)
        ->assertJsonPath('payments.0.tendered_amount', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonPath('payments.0.change_given', fn ($v): bool => (float) $v === 50.0);

    $payment = SalePayment::withoutGlobalScopes()->where('sales_order_id', $sale['id'])->sole();
    expect((float) $payment->tendered_amount)->toBe(200.0)
        ->and((float) $payment->change_given)->toBe(50.0);
});
