<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\InventoryAttributeDefinition;
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
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Foam Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Foam Factory',
        'code' => 'FOAM-01',
        'unit_type' => 'foam_manufactory',
    ]);

    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Main Warehouse',
        'code' => 'WH-MAIN-01',
    ]);

    $this->user = User::factory()->create([
        'must_change_password' => false,
    ]);

    $this->role = Role::create([
        'name' => 'Inventory Manager',
        'slug' => 'inventory_manager',
    ]);

    UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => $this->role->id,
        'operating_unit_id' => $this->unit->id,
    ]);
});

test('can create item categories and attribute definitions', function () {
    $catResponse = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/item-categories', [
            'name' => 'Foam Blocks',
            'code' => 'CAT-FOAM-BLOCK',
            'description' => 'Raw block inventory',
        ]);

    $catResponse->assertStatus(201)
        ->assertJsonPath('code', 'CAT-FOAM-BLOCK');

    $categoryId = $catResponse->json('id');

    $attrResponse = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/item-categories/{$categoryId}/attribute-definitions", [
            'name' => 'Pressure Rating',
            'slug' => 'pressure_kpa',
            'data_type' => 'number',
            'unit_of_measure' => 'kPa',
            'is_required_on_lot' => true,
        ]);

    $attrResponse->assertStatus(201)
        ->assertJsonPath('slug', 'pressure_kpa');
});

test('can link attributes to inventory items via many-to-many relationship', function () {
    $attr1 = InventoryAttributeDefinition::create([
        'name' => 'Density',
        'slug' => 'density_kg_m3',
        'data_type' => 'number',
        'unit_of_measure' => 'kg/m3',
    ]);

    $attr2 = InventoryAttributeDefinition::create([
        'name' => 'Gross Weight',
        'slug' => 'gross_weight_kg',
        'data_type' => 'number',
        'unit_of_measure' => 'kg',
    ]);

    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/inventory-items', [
            'name' => 'Custom Block Item',
            'sku' => 'BLOCK-CUST-01',
            'item_type' => 'foam_block',
            'unit_of_measure' => 'm3',
            'primary_uom' => 'block',
            'secondary_uom' => 'm3',
            'attribute_definition_ids' => [$attr1->id, $attr2->id],
        ]);

    $response->assertStatus(201)
        ->assertJsonCount(2, 'attribute_definitions');

    $itemId = $response->json('id');
    $item = InventoryItem::with('attributeDefinitions')->find($itemId);
    expect($item->attributeDefinitions->pluck('slug')->toArray())->toContain('density_kg_m3', 'gross_weight_kg');
});

test('can store and query stock lots by dynamic JSON attributes', function () {
    $category = ItemCategory::create([
        'name' => 'Foam Blocks',
        'code' => 'CAT-FOAM-01',
    ]);

    $item = InventoryItem::create([
        'category_id' => $category->id,
        'name' => 'Block 35 Pressure',
        'sku' => 'BLOCK-35P',
        'item_type' => 'foam_block',
        'unit_of_measure' => 'm3',
        'primary_uom' => 'block',
        'secondary_uom' => 'm3',
    ]);

    // High Pressure Lot (35 kPa)
    StockLot::create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'LOT-HIGH-35P',
        'quantity' => 4.0,
        'container_quantity' => 1.0,
        'length_m' => 2.0,
        'width_m' => 2.0,
        'height_m' => 1.0,
        'unit_cost' => 800.0,
        'status' => 'available',
        'attribute_values' => [
            'pressure_kpa' => 35.0,
            'density_kg_m3' => 30.0,
        ],
    ]);

    // Low Pressure Lot (20 kPa)
    StockLot::create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'LOT-LOW-20P',
        'quantity' => 4.0,
        'container_quantity' => 1.0,
        'length_m' => 2.0,
        'width_m' => 2.0,
        'height_m' => 1.0,
        'unit_cost' => 500.0,
        'status' => 'available',
        'attribute_values' => [
            'pressure_kpa' => 20.0,
            'density_kg_m3' => 20.0,
        ],
    ]);

    // Filter lots with pressure_kpa >= 30
    $filterResponse = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/stock-lots?attrs[pressure_kpa][gte]=30');

    $filterResponse->assertStatus(200)
        ->assertJsonFragment(['lot_number' => 'LOT-HIGH-35P'])
        ->assertJsonMissing(['lot_number' => 'LOT-LOW-20P']);
});
