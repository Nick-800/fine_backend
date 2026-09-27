<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Bundle;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Support\CurrentUnitContext;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(CurrentUnitContext::class)->clear();

    $this->company = Company::create(['name' => 'Fine Foam Mfg']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $makeUnit = fn (string $name, string $code) => OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => $name,
        'code' => $code,
        'unit_type' => 'showroom',
    ]);

    $this->unitA = $makeUnit('Showroom A', 'SHOW-A');
    $this->unitB = $makeUnit('Showroom B', 'SHOW-B');

    $role = Role::create(['name' => 'Store Manager', 'slug' => 'store-manager']);

    $this->userA = User::factory()->create(['must_change_password' => false]);
    UserRole::create(['user_id' => $this->userA->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unitA->id]);

    $this->userB = User::factory()->create(['must_change_password' => false]);
    UserRole::create(['user_id' => $this->userB->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unitB->id]);

    $this->asA = fn () => $this->actingAs($this->userA)->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id]);
    $this->asB = fn () => $this->actingAs($this->userB)->withHeaders(['X-Operating-Unit-ID' => $this->unitB->id]);

    $this->mattress = InventoryItem::create([
        'name' => 'Mattress Queen', 'code' => 'MATT-Q', 'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'each',
    ]);
    $this->base = InventoryItem::create([
        'name' => 'Bed Base Queen', 'code' => 'BASE-Q', 'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'each',
    ]);
});

test('creating a bundle with a unit header auto-assigns operating_unit_id', function () {
    $response = ($this->asA)()->postJson('/api/v1/bundles', [
        'name' => 'Bedroom Set',
        'items' => [
            ['inventory_item_id' => $this->mattress->id, 'suggested_quantity' => 1],
            ['inventory_item_id' => $this->base->id, 'suggested_quantity' => 1],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('operating_unit_id', $this->unitA->id)
        ->assertJsonCount(2, 'items');
});

test('creating a bundle with no unit context stays shared', function () {
    app(CurrentUnitContext::class)->clear();

    $bundle = Bundle::create(['name' => 'Shared Set']);
    $bundle->items()->create(['inventory_item_id' => $this->mattress->id]);

    expect($bundle->fresh()->operating_unit_id)->toBeNull();
});

test('a shared bundle stays visible to every unit, a unit-scoped one does not', function () {
    app(CurrentUnitContext::class)->clear();

    $shared = Bundle::create(['name' => 'Shared Set']);
    $shared->items()->create(['inventory_item_id' => $this->mattress->id]);

    $scopedToB = Bundle::create(['operating_unit_id' => $this->unitB->id, 'name' => 'B Only Set']);
    $scopedToB->items()->create(['inventory_item_id' => $this->base->id]);

    ($this->asA)()->getJson('/api/v1/bundles')
        ->assertStatus(200)
        ->assertJsonFragment(['name' => 'Shared Set'])
        ->assertJsonMissing(['name' => 'B Only Set']);

    ($this->asB)()->getJson('/api/v1/bundles')
        ->assertStatus(200)
        ->assertJsonFragment(['name' => 'Shared Set'])
        ->assertJsonFragment(['name' => 'B Only Set']);
});

test('updating a bundle replaces its items', function () {
    $pillow = InventoryItem::create([
        'name' => 'Pillow', 'code' => 'PILLOW-1', 'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'each',
    ]);

    $bundle = Bundle::create(['name' => 'Bedroom Set']);
    $bundle->items()->create(['inventory_item_id' => $this->mattress->id]);

    $response = ($this->asA)()->putJson("/api/v1/bundles/{$bundle->id}", [
        'name' => 'Bedroom Set',
        'items' => [
            ['inventory_item_id' => $this->base->id],
            ['inventory_item_id' => $pillow->id, 'suggested_quantity' => 2],
        ],
    ]);

    $response->assertStatus(200)->assertJsonCount(2, 'items');
    expect($bundle->items()->pluck('inventory_item_id')->all())
        ->toEqualCanonicalizing([$this->base->id, $pillow->id]);
});

test('deleting a bundle soft-deletes it while historical sales_order_lines keep their bundle_id', function () {
    $bundle = Bundle::create(['name' => 'Bedroom Set']);
    $bundle->items()->create(['inventory_item_id' => $this->mattress->id]);

    $order = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id,
        'order_number' => 'SO-BUNDLE-1',
        'buyer_type' => 'client',
        'channel' => 'pos',
    ]);
    $line = $order->lines()->create([
        'inventory_item_id' => $this->mattress->id,
        'bundle_id' => $bundle->id,
        'quantity' => 1,
        'unit_price' => 500,
    ]);

    ($this->asA)()->deleteJson("/api/v1/bundles/{$bundle->id}")->assertStatus(200);

    expect($line->fresh()->bundle_id)->toBe($bundle->id);
    expect(Bundle::find($bundle->id))->toBeNull();
});

test('a bundle sells as one line carrying its name: revenue at checkout, nothing issued yet', function () {
    $bundle = Bundle::create(['name' => 'جلسة عربية']);
    $bundle->items()->create(['inventory_item_id' => $this->mattress->id]);

    $warehouse = Warehouse::create(['operating_unit_id' => $this->unitA->id, 'name' => 'Showroom Floor', 'code' => 'WH-A']);
    StockLot::create([
        'inventory_item_id' => $this->mattress->id,
        'warehouse_id' => $warehouse->id,
        'lot_number' => 'LOT-MATT-1',
        'quantity' => 5,
        'unit_cost' => 200,
        'status' => 'available',
    ]);

    $client = Client::create([
        'entity_id' => Entity::create(['entity_type' => 'individual', 'name' => 'Walk-in Ali'])->id,
        'operating_unit_id' => $this->unitA->id,
    ]);
    $drawer = CashAccount::create([
        'operating_unit_id' => $this->unitA->id, 'name' => 'Drawer', 'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);

    $sale = ($this->asA)()->postJson('/api/v1/sales', [
        'client_id' => $client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $drawer->id,
        'lines' => [['line_type' => 'bundle', 'bundle_id' => $bundle->id, 'quantity' => 1, 'unit_price' => 2500]],
    ])->assertCreated()
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('fulfillment_status', 'awaiting_definition')
        ->json();

    $line = SalesOrderLine::where('sales_order_id', $sale['id'])->sole();
    expect($line->line_type)->toBe('bundle')
        ->and($line->description)->toBe('جلسة عربية')
        ->and($line->bundle_id)->toBe($bundle->id)
        ->and($line->inventory_item_id)->toBeNull();

    // No piece has left stock — they are defined and issued after checkout.
    expect((float) StockLot::where('lot_number', 'LOT-MATT-1')->value('quantity'))->toBe(5.0);

    $tb = app(AccountingService::class)->trialBalance();
    $byCode = collect($tb['rows'])->keyBy('account_code');
    expect($tb['balanced'])->toBeTrue()
        ->and((float) $byCode['41']['credit'])->toBe(2500.0)
        ->and($byCode->has('51'))->toBeFalse();

    // The client's invoice shows the bundle as a single item.
    $invoice = ($this->asA)()->getJson("/api/v1/sales/{$sale['id']}/invoice")->assertSuccessful()->json();
    expect($invoice['lines'])->toHaveCount(1)
        ->and($invoice['lines'][0]['description'])->toBe('جلسة عربية')
        ->and($invoice['lines'][0]['line_type'])->toBe('bundle')
        ->and($invoice['lines'][0]['sku'])->toBeNull()
        ->and((float) $invoice['lines'][0]['line_total'])->toBe(2500.0);
});

test('a bundle kept to another unit cannot be sold here', function () {
    $bundleB = ($this->asB)()->postJson('/api/v1/bundles', [
        'name' => 'Showroom B promo',
        'items' => [['inventory_item_id' => $this->mattress->id]],
    ])->assertCreated()->json();

    $client = Client::create([
        'entity_id' => Entity::create(['entity_type' => 'individual', 'name' => 'Walk-in Sara'])->id,
        'operating_unit_id' => $this->unitA->id,
    ]);

    ($this->asA)()->postJson('/api/v1/sales', [
        'client_id' => $client->id,
        'payment_method' => 'receivable',
        'lines' => [['line_type' => 'bundle', 'bundle_id' => $bundleB['id'], 'quantity' => 1, 'unit_price' => 900]],
    ])->assertUnprocessable()->assertJsonPath('code', 'BUNDLE_NOT_FOUND');
});

test('bundle sales report aggregates orders count, quantity and revenue per bundle', function () {
    $bundle = Bundle::create(['name' => 'Bedroom Set']);
    $bundle->items()->create(['inventory_item_id' => $this->mattress->id]);

    $order1 = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id, 'order_number' => 'SO-1', 'buyer_type' => 'client', 'channel' => 'pos', 'status' => 'open',
    ]);
    $order1->lines()->create([
        'inventory_item_id' => $this->mattress->id, 'bundle_id' => $bundle->id, 'quantity' => 2, 'unit_price' => 300,
    ]);

    $order2 = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id, 'order_number' => 'SO-2', 'buyer_type' => 'client', 'channel' => 'pos', 'status' => 'open',
    ]);
    $order2->lines()->create([
        'inventory_item_id' => $this->mattress->id, 'bundle_id' => $bundle->id, 'quantity' => 1, 'unit_price' => 300,
    ]);

    $response = ($this->asA)()->getJson('/api/v1/reports/bundle-sales');

    $response->assertStatus(200);
    $row = collect($response->json('rows'))->firstWhere('bundle_id', $bundle->id);

    expect($row)->not->toBeNull();
    expect($row['orders_count'])->toBe(2);
    expect((float) $row['total_quantity'])->toBe(3.0);
    expect((float) $row['total_revenue'])->toBe(900.0);
});

test('bundle sales report is scoped by operating unit', function () {
    $bundle = Bundle::create(['name' => 'Bedroom Set']);
    $bundle->items()->create(['inventory_item_id' => $this->mattress->id]);

    $orderB = SalesOrder::create([
        'operating_unit_id' => $this->unitB->id, 'order_number' => 'SO-B-1', 'buyer_type' => 'client', 'channel' => 'pos', 'status' => 'open',
    ]);
    $orderB->lines()->create([
        'inventory_item_id' => $this->mattress->id, 'bundle_id' => $bundle->id, 'quantity' => 1, 'unit_price' => 300,
    ]);

    ($this->asA)()->getJson('/api/v1/reports/bundle-sales')
        ->assertStatus(200)
        ->assertJsonCount(0, 'rows');

    ($this->asB)()->getJson('/api/v1/reports/bundle-sales')
        ->assertStatus(200)
        ->assertJsonCount(1, 'rows');
});
