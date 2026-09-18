<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Test Corp', 'default_currency' => 'LYD']);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Foam Manufactory Blueprint',
        'workflow_set' => ['production_batch' => ['planned', 'closed']],
        'default_role_template' => ['foam-manager' => ['create-foam']],
        'default_inventory_config' => ['warehouse_name' => 'Main Yard'],
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->asOwner = fn () => $this->actingAs($this->owner);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Foam Plant',
        'unit_type' => 'manufactory',
    ]);
});

test('owner can list warehouses for an operating unit', function () {
    Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Raw Materials Yard',
        'is_internal_unit' => true,
    ]);
    Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Finished Foam Yard',
        'is_internal_unit' => false,
    ]);

    $response = ($this->asOwner)()
        ->getJson("/api/v1/operating-units/{$this->unit->id}/warehouses")
        ->assertOk()
        ->json();

    expect($response)->toHaveCount(2);
    expect(collect($response)->pluck('name')->all())->toBe(['Finished Foam Yard', 'Raw Materials Yard']);
});

test('operating unit show endpoint includes eager-loaded warehouses', function () {
    Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Default Store',
        'is_internal_unit' => true,
    ]);

    $data = ($this->asOwner)()
        ->getJson("/api/v1/operating-units/{$this->unit->id}")
        ->assertOk()
        ->json('data');

    expect($data['warehouses'])->toBeArray();
    expect($data['warehouses'])->toHaveCount(1);
    expect($data['warehouses'][0]['name'])->toBe('Default Store');
});

test('owner can create a new warehouse for an operating unit', function () {
    $res = ($this->asOwner)()
        ->postJson("/api/v1/operating-units/{$this->unit->id}/warehouses", [
            'name' => 'Block Storage Bay B',
            'is_internal_unit' => true,
        ])
        ->assertCreated()
        ->json();

    expect($res['name'])->toBe('Block Storage Bay B');
    expect($res['is_internal_unit'])->toBeTrue();
    expect($res['operating_unit_id'])->toBe($this->unit->id);

    $this->assertDatabaseHas('warehouses', [
        'operating_unit_id' => $this->unit->id,
        'name' => 'Block Storage Bay B',
        'is_internal_unit' => true,
    ]);
});

test('owner can update warehouse name and internal flag', function () {
    $warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Old Name',
        'is_internal_unit' => false,
    ]);

    ($this->asOwner)()
        ->putJson("/api/v1/warehouses/{$warehouse->id}", [
            'name' => 'New Name',
            'is_internal_unit' => true,
        ])
        ->assertOk();

    expect($warehouse->fresh()->name)->toBe('New Name');
    expect($warehouse->fresh()->is_internal_unit)->toBeTrue();
});

test('cannot delete warehouse if it still holds stock lots', function () {
    $wh1 = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Primary Yard',
        'is_internal_unit' => true,
    ]);
    $wh2 = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Secondary Yard',
        'is_internal_unit' => true,
    ]);

    $category = ItemCategory::create([
        'name' => 'Chemicals',
        'code' => 'CHEM',
    ]);

    $item = InventoryItem::create([
        'sku' => 'POLY-01',
        'name' => 'Polyol',
        'category_id' => $category->id,
        'item_type' => 'raw_material',
        'unit_of_measure' => 'kg',
        'stock_tracking_policy' => 'bulk',
        'valuation_method' => 'wac',
    ]);

    StockLot::create([
        'warehouse_id' => $wh1->id,
        'inventory_item_id' => $item->id,
        'lot_number' => 'LOT-001',
        'quantity' => 50,
        'initial_quantity' => 50,
        'unit_cost' => 10,
        'status' => 'available',
    ]);

    ($this->asOwner)()
        ->deleteJson("/api/v1/warehouses/{$wh1->id}")
        ->assertStatus(422)
        ->assertJsonFragment(['code' => 'WAREHOUSE_NOT_EMPTY']);
});

test('cannot delete the only warehouse of an operating unit', function () {
    $soleWarehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Only Yard',
        'is_internal_unit' => true,
    ]);

    ($this->asOwner)()
        ->deleteJson("/api/v1/warehouses/{$soleWarehouse->id}")
        ->assertStatus(422)
        ->assertJsonFragment(['code' => 'CANNOT_DELETE_LAST_WAREHOUSE']);
});

test('can delete empty warehouse when operating unit has another warehouse', function () {
    $wh1 = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Yard 1',
        'is_internal_unit' => true,
    ]);
    $wh2 = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Yard 2',
        'is_internal_unit' => true,
    ]);

    ($this->asOwner)()
        ->deleteJson("/api/v1/warehouses/{$wh2->id}")
        ->assertOk();

    $this->assertDatabaseMissing('warehouses', ['id' => $wh2->id]);
});
