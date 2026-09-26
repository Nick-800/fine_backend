<?php

declare(strict_types=1);

use App\Models\Bundle;
use App\Models\Company;
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

test('creating a sales order with a bundle_id on a line persists it without affecting totals', function () {
    $bundle = Bundle::create(['name' => 'Bedroom Set']);
    $bundle->items()->create(['inventory_item_id' => $this->mattress->id]);

    $response = ($this->asA)()->postJson('/api/v1/sales-orders', [
        'order_number' => 'SO-API-1',
        'buyer_type' => 'internal_unit',
        'buyer_unit_id' => $this->unitB->id,
        'lines' => [
            ['inventory_item_id' => $this->mattress->id, 'bundle_id' => $bundle->id, 'quantity' => 1, 'unit_price' => 500],
        ],
    ]);

    $response->assertStatus(201);
    $line = SalesOrderLine::where('sales_order_id', $response->json('id'))->first();
    expect($line->bundle_id)->toBe($bundle->id);
});

test('POS checkout accepts and persists bundle_id per item', function () {
    $bundle = Bundle::create(['name' => 'Bedroom Set']);
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

    $response = ($this->asA)()->postJson('/api/v1/pos/sales', [
        'order_number' => 'POS-BUNDLE-1',
        'payment_method' => 'cash',
        'items' => [
            ['inventory_item_id' => $this->mattress->id, 'bundle_id' => $bundle->id, 'quantity' => 1, 'unit_price' => 500],
        ],
    ]);

    $response->assertStatus(201);
    $order = SalesOrder::find($response->json('id'));
    expect($order->lines()->first()->bundle_id)->toBe($bundle->id);
});

test('bundle sales report aggregates orders count, quantity and revenue per bundle', function () {
    $bundle = Bundle::create(['name' => 'Bedroom Set']);
    $bundle->items()->create(['inventory_item_id' => $this->mattress->id]);

    $order1 = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id, 'order_number' => 'SO-1', 'buyer_type' => 'client', 'channel' => 'pos',
    ]);
    $order1->lines()->create([
        'inventory_item_id' => $this->mattress->id, 'bundle_id' => $bundle->id, 'quantity' => 2, 'unit_price' => 300,
    ]);

    $order2 = SalesOrder::create([
        'operating_unit_id' => $this->unitA->id, 'order_number' => 'SO-2', 'buyer_type' => 'client', 'channel' => 'pos',
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
        'operating_unit_id' => $this->unitB->id, 'order_number' => 'SO-B-1', 'buyer_type' => 'client', 'channel' => 'pos',
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
