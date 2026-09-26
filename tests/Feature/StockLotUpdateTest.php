<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\ProductionBatch;
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

    $this->user = User::factory()->create(['must_change_password' => false]);
    $this->role = Role::create(['name' => 'Foam Manager', 'slug' => 'foam-manager']);

    UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => $this->role->id,
        'operating_unit_id' => $this->unit->id,
    ]);

    $this->blockItem = InventoryItem::create([
        'name' => 'Foam Block Standard',
        'code' => 'BLOCK-STD',
        'item_type' => 'foam_block',
        'unit_of_measure' => 'm3',
    ]);

    $this->batch = ProductionBatch::create([
        'operating_unit_id' => $this->unit->id,
        'operation_number' => 101,
        'bun_width_m' => 2.4,
        'status' => 'ready_for_grading',
    ]);

    $this->lot = StockLot::create([
        'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->blockItem->id,
        'production_batch_id' => $this->batch->id,
        'lot_number' => '101-01',
        'sequence_in_batch' => 1,
        'pressure' => 30,
        'quantity' => 1,
        'length_m' => 2.0,
        'width_m' => 2.4,
        'height_m' => 1.0,
        'volume_m3' => 4.8,
        'unit_cost' => 100,
        'grade' => 'standard',
        'block_type' => 'block',
        'status' => 'available',
        'record_version' => 1,
    ]);
});

test('operator can update block dimensions, pressure, grade and type', function () {
    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->putJson("/api/v1/stock-lots/{$this->lot->id}", [
            'length_m' => 2.5,
            'width_m' => 2.4,
            'height_m' => 1.2,
            'pressure' => 35,
            'block_type' => 'head',
            'grade' => 'acceptable_variant',
            'record_version' => 1,
            'attribute_values' => ['color' => 'Blue'],
        ]);

    $response->assertStatus(200);

    $fresh = $this->lot->fresh();
    expect((float) $fresh->length_m)->toBe(2.5)
        ->and((float) $fresh->height_m)->toBe(1.2)
        ->and((float) $fresh->volume_m3)->toBe(7.2) // 2.5 * 2.4 * 1.2 = 7.2
        ->and($fresh->pressure)->toBe(35)
        ->and($fresh->block_type)->toBe('head')
        ->and($fresh->grade)->toBe('acceptable_variant')
        ->and($fresh->attribute_values['color'])->toBe('Blue');
});

test('batch update reloads blocks and scrap lots count', function () {
    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->putJson("/api/v1/production-batches/{$this->batch->id}", [
            'bun_width_m' => 2.2,
            'record_version' => 1,
            'formula_params' => [
                'density_band' => 'D25',
                'cure_time_minutes' => 45,
                'conveyor_speed' => 3.5,
            ],
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('bun_width_m', '2.200')
        ->assertJsonPath('blocks_count', 1)
        ->assertJsonPath('formula_params.density_band', 'D25');
});
