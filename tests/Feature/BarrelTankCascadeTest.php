<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\TankStock;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);
    $this->blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory',
    ]);
    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'WH', 'code' => 'WH-1',
    ]);
    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Foam Manager', 'slug' => 'foam_manager']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unit->id]);

    // Empty barrels are themselves stock: they come back off the floor.
    $this->emptyBarrel = InventoryItem::create([
        'name' => 'Empty 40L Barrel', 'sku' => 'BARREL-40-EMPTY',
        'item_type' => 'barrel', 'unit_of_measure' => 'each',
    ]);

    $this->chemical = InventoryItem::create([
        'name' => 'Polyol 15%', 'sku' => 'CHEM-P15',
        'item_type' => 'raw_material', 'unit_of_measure' => 'liter',
        'primary_uom' => 'barrel', 'secondary_uom' => 'liter',
        'container_capacity' => 40,
        'empty_container_item_id' => $this->emptyBarrel->id,
    ]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    // Five full 40L barrels = 200L.
    $this->lot = StockLot::create([
        'inventory_item_id' => $this->chemical->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'LOT-P15-001',
        'quantity' => 200,
        'container_quantity' => 5,
        'unit_cost' => 3.0,
        'status' => 'available',
    ]);

    $this->draw = fn (array $body) => ($this->api)()->postJson('/api/v1/tank-stocks/refill-from-lot', [
        'source_stock_lot_id' => $this->lot->id,
        ...$body,
    ]);

    $this->emptiesOnHand = fn () => (float) StockLot::where('inventory_item_id', $this->emptyBarrel->id)->sum('quantity');
});

test('a partial draw opens a barrel without consuming it', function () {
    ($this->draw)(['draw_quantity' => 15])->assertStatus(201);

    $this->lot->refresh();

    // 185L remains, but still across five physical barrels — one now open.
    expect((float) $this->lot->quantity)->toBe(185.0)
        ->and((int) $this->lot->container_quantity)->toBe(5)
        ->and(($this->emptiesOnHand)())->toBe(0.0);
});

test('draining the open barrel recovers exactly one empty', function () {
    ($this->draw)(['draw_quantity' => 15])->assertStatus(201);
    ($this->draw)(['draw_quantity' => 25])->assertStatus(201);

    $this->lot->refresh();

    expect((float) $this->lot->quantity)->toBe(160.0)
        ->and((int) $this->lot->container_quantity)->toBe(4)
        ->and(($this->emptiesOnHand)())->toBe(1.0);
});

test('drawing whole containers expands through capacity', function () {
    ($this->draw)(['draw_containers' => 2])->assertStatus(201);

    $this->lot->refresh();

    // 2 barrels x 40L
    expect((float) $this->lot->quantity)->toBe(120.0)
        ->and((int) $this->lot->container_quantity)->toBe(3)
        ->and(($this->emptiesOnHand)())->toBe(2.0);
});

test('draining the lot consumes it and returns every barrel', function () {
    ($this->draw)(['draw_quantity' => 200])->assertStatus(201);

    $this->lot->refresh();

    expect((float) $this->lot->quantity)->toBe(0.0)
        ->and((int) $this->lot->container_quantity)->toBe(0)
        ->and($this->lot->status)->toBe('consumed')
        ->and(($this->emptiesOnHand)())->toBe(5.0);
});

test('the tank is credited at the source lot cost, not a typed one', function () {
    ($this->draw)(['draw_quantity' => 100])->assertStatus(201);

    $tank = TankStock::where('chemical_inventory_item_id', $this->chemical->id)->first();

    // The lot cost 3.0/L; nothing in the request could override that.
    expect((float) $tank->quantity_on_hand)->toBe(100.0)
        ->and((float) $tank->weighted_avg_unit_cost)->toBe(3.0);
});

test('a refill moves stock rather than inventing it', function () {
    ($this->draw)(['draw_quantity' => 40])->assertStatus(201);

    $movements = ($this->api)()->getJson('/api/v1/inventory-movements?per_page=50')->json('data');
    $types = collect($movements)->pluck('movement_type')->sort()->values()->all();

    // Both legs plus the recovered empty: issue from the lot, receipt into the
    // tank. The old one-sided path recorded only the receipt.
    expect($types)->toBe(['byproduct_yield', 'issue', 'receipt']);

    $issue = collect($movements)->firstWhere('movement_type', 'issue');
    expect((float) $issue['quantity_delta'])->toBe(-40.0);
});

test('a draw larger than the lot is refused and changes nothing', function () {
    ($this->draw)(['draw_quantity' => 500])->assertStatus(500);

    $this->lot->refresh();

    expect((float) $this->lot->quantity)->toBe(200.0)
        ->and(($this->emptiesOnHand)())->toBe(0.0)
        ->and(TankStock::count())->toBe(0);
});

test('an item with no capacity draws by measure and recovers nothing', function () {
    $bulk = InventoryItem::create([
        'name' => 'Bulk Additive', 'sku' => 'CHEM-BULK',
        'item_type' => 'raw_material', 'unit_of_measure' => 'kg',
    ]);

    $bulkLot = StockLot::create([
        'inventory_item_id' => $bulk->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'LOT-BULK-1',
        'quantity' => 500,
        'container_quantity' => 1,
        'unit_cost' => 7.0,
        'status' => 'available',
    ]);

    ($this->api)()->postJson('/api/v1/tank-stocks/refill-from-lot', [
        'source_stock_lot_id' => $bulkLot->id,
        'draw_quantity' => 100,
    ])->assertStatus(201);

    $bulkLot->refresh();

    expect((float) $bulkLot->quantity)->toBe(400.0)
        ->and(($this->emptiesOnHand)())->toBe(0.0);
});

test('drawing by container is refused when no capacity is defined', function () {
    $bulk = InventoryItem::create([
        'name' => 'Bulk Additive', 'sku' => 'CHEM-BULK2',
        'item_type' => 'raw_material', 'unit_of_measure' => 'kg',
    ]);
    $bulkLot = StockLot::create([
        'inventory_item_id' => $bulk->id, 'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'LOT-BULK-2', 'quantity' => 500, 'unit_cost' => 7.0, 'status' => 'available',
    ]);

    ($this->api)()->postJson('/api/v1/tank-stocks/refill-from-lot', [
        'source_stock_lot_id' => $bulkLot->id,
        'draw_containers' => 2,
    ])->assertStatus(500);
});

test('the unsourced adjustment path is marked apart in the ledger', function () {
    ($this->api)()->postJson('/api/v1/tank-stocks/refill', [
        'chemical_inventory_item_id' => $this->chemical->id,
        'refill_quantity' => 50,
        'refill_unit_cost' => 9.0,
    ])->assertStatus(201);

    $movements = ($this->api)()->getJson('/api/v1/inventory-movements?per_page=50')->json('data');

    // A credit with no stock behind it must be pickable out of the ledger.
    expect(collect($movements)->pluck('reason')->all())->toBe(['tank_adjustment']);
});
