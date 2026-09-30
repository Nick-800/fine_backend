<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Bundle;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);

    $this->store = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id, 'name' => 'Showroom', 'unit_type' => 'store',
    ]);
    $this->otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id, 'name' => 'Cutter', 'unit_type' => 'manufactory',
    ]);

    $warehouse = Warehouse::create(['operating_unit_id' => $this->store->id, 'name' => 'Store WH', 'code' => 'WH-S']);

    seedUnitAccounts([$this->store, $this->otherUnit]);

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Cashier', 'slug' => 'pos-cashier']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => $this->store->id]);

    $this->client = Client::create([
        'entity_id' => Entity::create(['entity_type' => 'individual', 'name' => 'Hassan'])->id,
        'operating_unit_id' => $this->store->id,
        'credit_limit' => 5000,
    ]);

    $this->drawer = CashAccount::create([
        'operating_unit_id' => $this->store->id, 'name' => 'Drawer', 'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);

    $this->pillow = InventoryItem::create([
        'name' => 'Pillow', 'code' => 'PIL-1', 'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'each',
    ]);
    StockLot::create([
        'inventory_item_id' => $this->pillow->id, 'warehouse_id' => $warehouse->id,
        'lot_number' => 'PIL-LOT', 'quantity' => 10, 'unit_cost' => 20, 'status' => 'available',
    ]);

    $this->sofa = Bundle::create(['name' => 'جلسة عربية']);

    $this->api = fn () => $this->actingAs($this->user)->withHeaders(['X-Operating-Unit-ID' => $this->store->id]);

    $this->quote = fn (array $overrides = []) => ($this->api)()->postJson('/api/v1/quotations', array_merge([
        'client_id' => $this->client->id,
        'lines' => [
            ['inventory_item_id' => $this->pillow->id, 'quantity' => 4, 'unit_price' => 35],
            ['line_type' => 'bundle', 'bundle_id' => $this->sofa->id, 'quantity' => 1, 'unit_price' => 2200],
        ],
    ], $overrides));
});

test('a quotation is numbered, priced and moves nothing', function () {
    $quotation = ($this->quote)()
        ->assertCreated()
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('effective_status', 'open')
        ->assertJsonPath('total_amount', '2340.0000')
        ->assertJsonPath('lines.1.description', 'جلسة عربية')
        ->json();

    expect($quotation['quotation_number'])->toBe('Q-'.now()->format('Y').'-00001')
        ->and($quotation['valid_until'])->toStartWith(today()->addDays(14)->toDateString())
        ->and((float) StockLot::where('lot_number', 'PIL-LOT')->value('quantity'))->toBe(10.0)
        ->and(JournalEntry::count())->toBe(0)
        ->and(SalesOrder::count())->toBe(0);
});

test('a quotation needs a client registered in this unit', function () {
    $stranger = Client::create([
        'entity_id' => Entity::create(['entity_type' => 'individual', 'name' => 'Stranger'])->id,
        'operating_unit_id' => $this->otherUnit->id,
    ]);

    ($this->quote)(['client_id' => $stranger->id])
        ->assertUnprocessable()->assertJsonPath('code', 'CLIENT_NOT_FOUND');
});

test('converting sells the quotation through a normal checkout, with prices adjustable at the counter', function () {
    $quotation = ($this->quote)()->json();

    $sale = ($this->api)()->postJson("/api/v1/quotations/{$quotation['id']}/convert", [
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => [
            ['inventory_item_id' => $this->pillow->id, 'quantity' => 4, 'unit_price' => 30],
            ['line_type' => 'bundle', 'bundle_id' => $this->sofa->id, 'quantity' => 1, 'unit_price' => 2200],
        ],
    ])->assertCreated()
        ->assertJsonPath('quotation_id', $quotation['id'])
        ->assertJsonPath('total_amount', '2320.0000')
        ->assertJsonPath('fulfillment_status', 'awaiting_definition')
        ->json();

    expect(Quotation::find($quotation['id']))
        ->status->toBe('converted')
        ->converted_sales_order_id->toBe($sale['id'])
        ->and((float) StockLot::where('lot_number', 'PIL-LOT')->value('quantity'))->toBe(6.0);

    // It cannot be sold twice.
    ($this->api)()->postJson("/api/v1/quotations/{$quotation['id']}/convert", [
        'payment_method' => 'cash', 'cash_account_id' => $this->drawer->id,
    ])->assertUnprocessable()->assertJsonPath('code', 'QUOTATION_NOT_OPEN');
});

test('converting without lines sells exactly what was quoted', function () {
    $quotation = ($this->quote)()->json();

    ($this->api)()->postJson("/api/v1/quotations/{$quotation['id']}/convert", [
        'payment_method' => 'receivable',
    ])->assertCreated()->assertJsonPath('total_amount', '2340.0000');
});

test('a retried conversion returns the sale it already made', function () {
    $quotation = ($this->quote)()->json();
    $requestId = (string) Str::uuid();
    $payload = ['payment_method' => 'cash', 'cash_account_id' => $this->drawer->id, 'client_request_id' => $requestId];

    $first = ($this->api)()->postJson("/api/v1/quotations/{$quotation['id']}/convert", $payload)->assertCreated()->json('id');
    $second = ($this->api)()->postJson("/api/v1/quotations/{$quotation['id']}/convert", $payload)->assertSuccessful()->json('id');

    expect($second)->toBe($first)->and(SalesOrder::count())->toBe(1);
});

test('an expired quotation reads as expired and cannot be converted', function () {
    $quotation = ($this->quote)()->json();
    Quotation::whereKey($quotation['id'])->update(['valid_until' => today()->subDay()]);

    ($this->api)()->getJson("/api/v1/quotations/{$quotation['id']}")
        ->assertSuccessful()->assertJsonPath('effective_status', 'expired');

    ($this->api)()->getJson('/api/v1/quotations?status=expired')->assertJsonPath('total', 1);
    ($this->api)()->getJson('/api/v1/quotations?status=open')->assertJsonPath('total', 0);

    ($this->api)()->postJson("/api/v1/quotations/{$quotation['id']}/convert", [
        'payment_method' => 'cash', 'cash_account_id' => $this->drawer->id,
    ])->assertUnprocessable()->assertJsonPath('code', 'QUOTATION_EXPIRED');
});

test('a cancelled quotation cannot be converted', function () {
    $quotation = ($this->quote)()->json();

    ($this->api)()->postJson("/api/v1/quotations/{$quotation['id']}/cancel")
        ->assertSuccessful()->assertJsonPath('status', 'cancelled');

    ($this->api)()->postJson("/api/v1/quotations/{$quotation['id']}/convert", [
        'payment_method' => 'cash', 'cash_account_id' => $this->drawer->id,
    ])->assertUnprocessable()->assertJsonPath('code', 'QUOTATION_NOT_OPEN');
});
