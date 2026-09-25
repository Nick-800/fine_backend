<?php

declare(strict_types=1);

use App\Enums\CutterWorkOrderStatus;
use App\Enums\ProductionBatchStatus;
use App\Models\Company;
use App\Models\CutterWorkOrder;
use App\Models\InventoryItem;
use App\Models\MaterialRequest;
use App\Models\OperatingUnit;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\MaterialResolutionService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Fine Material Request Test',
        'default_currency' => 'LYD',
        'overhead_absorption_enabled' => false,
        'transfer_pricing_mode' => 'at_cost',
        'timezone' => 'Africa/Tripoli',
    ]);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'General Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Main Unit',
        'unit_type' => 'manufactory',
        'currency' => 'LYD',
        'status' => 'active',
    ]);

    $owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Owner']);
    UserRole::create([
        'user_id' => $owner->id,
        'role_id' => $ownerRole->id,
        'operating_unit_id' => null,
    ]);
    $this->owner = $owner;

    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Main Warehouse',
        'is_internal_unit' => true,
    ]);

    $this->foamBlockItem = InventoryItem::create([
        'name' => 'Foam Block',
        'code' => 'FOAM-BLK-001',
        'item_type' => 'foam_block',
        'unit_of_measure' => 'm3',
    ]);

    $this->makePendingMaterialRequest = fn (): MaterialRequest => MaterialRequest::create([
        'fulfilling_module' => MaterialRequest::MODULE_CUTTER,
        'inventory_item_id' => $this->foamBlockItem->id,
        'quantity' => 1,
        'status' => MaterialRequest::STATUS_PENDING,
        'operating_unit_id' => $this->unit->id,
    ]);
});

it('starts a pending material request', function () {
    $request = ($this->makePendingMaterialRequest)();

    $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson("/api/v1/material-requests/{$request->id}/start")
        ->assertOk();

    expect($request->fresh()->status)->toBe(MaterialRequest::STATUS_IN_PROGRESS);
});

it('fulfills a material request via a cutter work order', function () {
    $request = ($this->makePendingMaterialRequest)();

    $cutterOrder = CutterWorkOrder::create([
        'operating_unit_id' => $this->unit->id,
        'order_number' => 'CW-MR-TEST',
        'status' => CutterWorkOrderStatus::Completed,
    ]);

    $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson("/api/v1/material-requests/{$request->id}/fulfill", [
            'fulfilled_by_type' => 'cutter_work_order',
            'fulfilled_by_id' => $cutterOrder->id,
        ])
        ->assertOk();

    expect($request->fresh()->status)->toBe(MaterialRequest::STATUS_FULFILLED)
        ->and($request->fresh()->fulfilled_by_type)->toBe('cutter_work_order')
        ->and($request->fresh()->fulfilled_by_id)->toBe($cutterOrder->id);
});

it('fulfills a material request via markFulfilled with a production batch', function () {
    $request = ($this->makePendingMaterialRequest)();

    $batch = ProductionBatch::create([
        'operating_unit_id' => $this->unit->id,
        'operation_number' => 1,
        'bun_width_m' => 2.4,
        'formula_params' => [],
        'status' => ProductionBatchStatus::Closed,
    ]);

    app(MaterialResolutionService::class)->markFulfilled($request, $batch);

    expect($request->fresh()->status)->toBe(MaterialRequest::STATUS_FULFILLED)
        ->and($request->fresh()->fulfilled_by_type)->toBe('production_batch')
        ->and($request->fresh()->fulfilled_by_id)->toBe($batch->id);
});

it('cancels an open material request', function () {
    $request = ($this->makePendingMaterialRequest)();

    $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson("/api/v1/material-requests/{$request->id}/cancel")
        ->assertOk();

    expect($request->fresh()->status)->toBe(MaterialRequest::STATUS_CANCELLED);
});

it('lists material requests filtered by status and module', function () {
    ($this->makePendingMaterialRequest)();
    $inProgress = ($this->makePendingMaterialRequest)();
    $inProgress->update(['status' => MaterialRequest::STATUS_IN_PROGRESS]);

    $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/material-requests?status='.MaterialRequest::STATUS_IN_PROGRESS)
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
