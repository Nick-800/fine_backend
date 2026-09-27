<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\InventoryItem;
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

    $this->warehouseA = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Warehouse A']);
    $this->warehouseB = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Warehouse B']);

    $this->item = InventoryItem::create([
        'name' => 'Cotton Filler',
        'code' => 'FILL-COT',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'kg',
    ]);

    $this->user = User::factory()->create(['must_change_password' => false]);
    $this->role = Role::create(['name' => 'Foam Manager', 'slug' => 'foam-manager']);

    UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => $this->role->id,
        'operating_unit_id' => $this->unit->id,
    ]);

    $this->asUser = fn () => $this->actingAs($this->user)->withHeader('X-Operating-Unit-ID', $this->unit->id);
});

// The FIFO-draw / split / cross-unit / insufficient-stock behavior of
// StockLotService::transferBetweenWarehouses() itself is now exercised
// through the warehouse-transfer document flow — see WarehouseTransferTest.
// This file keeps only the still-relevant guard on the generic stock-lot
// update endpoint.
test('a stock lot warehouse_id can no longer be changed via the generic update endpoint', function () {
    $lot = StockLot::factory()->create([
        'inventory_item_id' => $this->item->id,
        'warehouse_id' => $this->warehouseA->id,
        'status' => 'available',
    ]);

    $response = ($this->asUser)()->putJson("/api/v1/stock-lots/{$lot->id}", [
        'warehouse_id' => $this->warehouseB->id,
        'record_version' => $lot->record_version,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['warehouse_id']);
});
