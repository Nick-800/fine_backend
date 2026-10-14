<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ItemCategory;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unitA = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Furniture Plant',
        'code' => 'FURN-01',
        'unit_type' => 'manufactory',
    ]);

    $this->unitB = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Showroom',
        'code' => 'STORE-01',
        'unit_type' => 'showroom',
    ]);

    $this->warehouseA1 = Warehouse::create([
        'operating_unit_id' => $this->unitA->id,
        'name' => 'Main Warehouse A',
        'code' => 'WH-A1',
    ]);

    $this->warehouseA2 = Warehouse::create([
        'operating_unit_id' => $this->unitA->id,
        'name' => 'Secondary Warehouse A',
        'code' => 'WH-A2',
    ]);

    $this->warehouseB = Warehouse::create([
        'operating_unit_id' => $this->unitB->id,
        'name' => 'Showroom Warehouse B',
        'code' => 'WH-B',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->manager = User::factory()->create(['must_change_password' => false]);
    $managerRole = Role::create(['name' => 'Store Manager', 'slug' => 'store-manager']);
    UserRole::create(['user_id' => $this->manager->id, 'role_id' => $managerRole->id, 'operating_unit_id' => $this->unitA->id]);

    $this->unauthorizedUser = User::factory()->create(['must_change_password' => false]);
    $guestRole = Role::create(['name' => 'Guest', 'slug' => 'guest-role']);
    UserRole::create(['user_id' => $this->unauthorizedUser->id, 'role_id' => $guestRole->id, 'operating_unit_id' => $this->unitA->id]);

    $this->category = ItemCategory::create([
        'name' => 'مراتب إسفنج',
        'code' => 'CAT-MATTRESS',
    ]);

    $this->item = InventoryItem::create([
        'name' => 'مرتبة سوبر',
        'code' => 'MATT-SUP-01',
        'item_type' => 'furniture_finished_good',
        'category_id' => $this->category->id,
        'unit_of_measure' => 'piece',
        'selling_price' => 250,
    ]);

    $this->lotA = StockLot::create([
        'inventory_item_id' => $this->item->id,
        'warehouse_id' => $this->warehouseA1->id,
        'lot_number' => 'LOT-001',
        'quantity' => 100,
        'unit_cost' => 120.0,
        'status' => 'available',
    ]);

    $this->createMovement = function (array $attrs): InventoryMovement {
        $createdAt = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);
        $attrs['id'] = $attrs['id'] ?? (string) Str::uuid();
        $movement = InventoryMovement::create($attrs);
        if ($createdAt) {
            DB::table('inventory_movements')->where('id', $movement->id)->update(['created_at' => $createdAt]);
        }

        return $movement->fresh();
    };

    $this->asOwner = fn () => $this->actingAs($this->owner);
    $this->asManager = fn () => $this->actingAs($this->manager);
    $this->asUnauthorized = fn () => $this->actingAs($this->unauthorizedUser);
});

test('it returns empty result when no movements exist for the item', function (): void {
    $response = ($this->asOwner)()->getJson("/api/v1/inventory/items/{$this->item->id}/movement-card?company_wide=1")
        ->assertOk()
        ->json();

    expect($response['item']['id'])->toBe($this->item->id)
        ->and($response['item']['code'])->toBe('MATT-SUP-01')
        ->and($response['opening_balance'])->toEqual(0.0)
        ->and($response['closing_balance'])->toEqual(0.0)
        ->and($response['totals']['in'])->toEqual(0.0)
        ->and($response['totals']['out'])->toEqual(0.0)
        ->and($response['rows'])->toBeEmpty();
});

test('it calculates running balance, in/out split, and movement groups correctly', function (): void {
    // 1. Purchase receipt: +20
    ($this->createMovement)([
        'operating_unit_id' => $this->unitA->id,
        'stock_lot_id' => $this->lotA->id,
        'to_warehouse_id' => $this->warehouseA1->id,
        'sku' => $this->item->code,
        'movement_type' => 'intake',
        'quantity_delta' => 20,
        'unit_cost' => 120,
        'reason' => 'purchase_cash',
        'created_at' => '2026-03-01 10:00:00',
    ]);

    // 2. Sale issue: -5
    ($this->createMovement)([
        'operating_unit_id' => $this->unitA->id,
        'stock_lot_id' => $this->lotA->id,
        'from_warehouse_id' => $this->warehouseA1->id,
        'sku' => $this->item->code,
        'movement_type' => 'sale',
        'quantity_delta' => -5,
        'unit_cost' => 120,
        'reason' => 'sale_pos',
        'created_at' => '2026-03-02 11:00:00',
    ]);

    // 3. Scrap / byproduct: -2
    ($this->createMovement)([
        'operating_unit_id' => $this->unitA->id,
        'stock_lot_id' => $this->lotA->id,
        'from_warehouse_id' => $this->warehouseA1->id,
        'sku' => $this->item->code,
        'movement_type' => 'adjustment',
        'quantity_delta' => -2,
        'unit_cost' => 120,
        'reason' => 'damaged_stock_scrap',
        'created_at' => '2026-03-03 12:00:00',
    ]);

    $response = ($this->asOwner)()->getJson("/api/v1/inventory/items/{$this->item->id}/movement-card?company_wide=1")
        ->assertOk()
        ->json();

    expect($response['opening_balance'])->toEqual(0.0)
        ->and($response['rows'])->toHaveCount(3)
        ->and($response['rows'][0]['group'])->toBe('purchase receipt')
        ->and($response['rows'][0]['in'])->toEqual(20.0)
        ->and($response['rows'][0]['out'])->toEqual(0.0)
        ->and($response['rows'][0]['balance'])->toEqual(20.0)
        ->and($response['rows'][1]['group'])->toBe('sale issue')
        ->and($response['rows'][1]['in'])->toEqual(0.0)
        ->and($response['rows'][1]['out'])->toEqual(5.0)
        ->and($response['rows'][1]['balance'])->toEqual(15.0)
        ->and($response['rows'][2]['group'])->toBe('scrap')
        ->and($response['rows'][2]['in'])->toEqual(0.0)
        ->and($response['rows'][2]['out'])->toEqual(2.0)
        ->and($response['rows'][2]['balance'])->toEqual(13.0)
        ->and($response['totals']['in'])->toEqual(20.0)
        ->and($response['totals']['out'])->toEqual(7.0)
        ->and($response['closing_balance'])->toEqual(13.0);
});

test('it calculates opening balance with from date filter', function (): void {
    // Before period: +50 and -10 -> net opening balance 40
    ($this->createMovement)([
        'operating_unit_id' => $this->unitA->id,
        'stock_lot_id' => $this->lotA->id,
        'to_warehouse_id' => $this->warehouseA1->id,
        'sku' => $this->item->code,
        'movement_type' => 'intake',
        'quantity_delta' => 50,
        'unit_cost' => 100,
        'reason' => 'opening_stock',
        'created_at' => '2026-01-10 10:00:00',
    ]);

    ($this->createMovement)([
        'operating_unit_id' => $this->unitA->id,
        'stock_lot_id' => $this->lotA->id,
        'from_warehouse_id' => $this->warehouseA1->id,
        'sku' => $this->item->code,
        'movement_type' => 'sale',
        'quantity_delta' => -10,
        'unit_cost' => 100,
        'reason' => 'january_sale',
        'created_at' => '2026-01-20 10:00:00',
    ]);

    // During period: +15
    ($this->createMovement)([
        'operating_unit_id' => $this->unitA->id,
        'stock_lot_id' => $this->lotA->id,
        'to_warehouse_id' => $this->warehouseA1->id,
        'sku' => $this->item->code,
        'movement_type' => 'intake',
        'quantity_delta' => 15,
        'unit_cost' => 100,
        'reason' => 'february_restock',
        'created_at' => '2026-02-15 10:00:00',
    ]);

    $response = ($this->asOwner)()->getJson("/api/v1/inventory/items/{$this->item->id}/movement-card?from=2026-02-01&to=2026-02-28&company_wide=1")
        ->assertOk()
        ->json();

    expect($response['opening_balance'])->toEqual(40.0)
        ->and($response['rows'])->toHaveCount(1)
        ->and($response['rows'][0]['in'])->toEqual(15.0)
        ->and($response['rows'][0]['balance'])->toEqual(55.0)
        ->and($response['closing_balance'])->toEqual(55.0);
});

test('it balances only the filtered warehouse stock including transfers', function (): void {
    // WH-A1 gets 100
    ($this->createMovement)([
        'operating_unit_id' => $this->unitA->id,
        'stock_lot_id' => $this->lotA->id,
        'to_warehouse_id' => $this->warehouseA1->id,
        'sku' => $this->item->code,
        'movement_type' => 'intake',
        'quantity_delta' => 100,
        'unit_cost' => 100,
        'reason' => 'initial_intake',
        'created_at' => '2026-03-01 08:00:00',
    ]);

    // Transfer from WH-A1 to WH-A2: 30 units (two rows)
    ($this->createMovement)([
        'operating_unit_id' => $this->unitA->id,
        'stock_lot_id' => $this->lotA->id,
        'from_warehouse_id' => $this->warehouseA1->id,
        'sku' => $this->item->code,
        'movement_type' => 'transfer',
        'quantity_delta' => -30,
        'unit_cost' => 100,
        'reason' => 'warehouse_transfer',
        'created_at' => '2026-03-02 09:00:00',
    ]);

    ($this->createMovement)([
        'operating_unit_id' => $this->unitA->id,
        'stock_lot_id' => $this->lotA->id,
        'to_warehouse_id' => $this->warehouseA2->id,
        'sku' => $this->item->code,
        'movement_type' => 'transfer',
        'quantity_delta' => 30,
        'unit_cost' => 100,
        'reason' => 'warehouse_transfer',
        'created_at' => '2026-03-02 09:00:01',
    ]);

    // Query WH-A1
    $responseA1 = ($this->asOwner)()->getJson("/api/v1/inventory/items/{$this->item->id}/movement-card?warehouse_id={$this->warehouseA1->id}&company_wide=1")
        ->assertOk()
        ->json();

    expect($responseA1['closing_balance'])->toEqual(70.0)
        ->and($responseA1['totals']['in'])->toEqual(100.0)
        ->and($responseA1['totals']['out'])->toEqual(30.0);

    // Query WH-A2
    $responseA2 = ($this->asOwner)()->getJson("/api/v1/inventory/items/{$this->item->id}/movement-card?warehouse_id={$this->warehouseA2->id}&company_wide=1")
        ->assertOk()
        ->json();

    expect($responseA2['closing_balance'])->toEqual(30.0)
        ->and($responseA2['totals']['in'])->toEqual(30.0)
        ->and($responseA2['totals']['out'])->toEqual(0.0)
        ->and($responseA2['rows'][0]['group'])->toBe('internal transfer');
});

test('it scopes results to the operating unit and 404s for invalid warehouse or item', function (): void {
    // Movement in Unit A
    ($this->createMovement)([
        'operating_unit_id' => $this->unitA->id,
        'stock_lot_id' => $this->lotA->id,
        'to_warehouse_id' => $this->warehouseA1->id,
        'sku' => $this->item->code,
        'movement_type' => 'intake',
        'quantity_delta' => 10,
        'unit_cost' => 100,
        'reason' => 'intake_a',
        'created_at' => '2026-03-01 10:00:00',
    ]);

    // Movement in Unit B
    ($this->createMovement)([
        'operating_unit_id' => $this->unitB->id,
        'stock_lot_id' => $this->lotA->id,
        'to_warehouse_id' => $this->warehouseB->id,
        'sku' => $this->item->code,
        'movement_type' => 'intake',
        'quantity_delta' => 50,
        'unit_cost' => 100,
        'reason' => 'intake_b',
        'created_at' => '2026-03-01 10:00:00',
    ]);

    // Unit manager for Unit A sees only Unit A movements
    $resA = ($this->asManager)()->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson("/api/v1/inventory/items/{$this->item->id}/movement-card")
        ->assertOk()
        ->json();

    expect($resA['closing_balance'])->toEqual(10.0);

    // Unit manager trying to filter by a warehouse from Unit B gets 404
    ($this->asManager)()->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson("/api/v1/inventory/items/{$this->item->id}/movement-card?warehouse_id={$this->warehouseB->id}")
        ->assertNotFound();

    // Querying non-existent item returns 404
    $fakeId = (string) Str::uuid();
    ($this->asManager)()->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson("/api/v1/inventory/items/{$fakeId}/movement-card")
        ->assertNotFound();
});

test('it denies access to users without authorized roles', function (): void {
    ($this->asUnauthorized)()->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson("/api/v1/inventory/items/{$this->item->id}/movement-card")
        ->assertForbidden();
});
