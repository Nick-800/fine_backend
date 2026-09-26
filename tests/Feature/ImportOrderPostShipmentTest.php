<?php

declare(strict_types=1);

use App\Enums\ImportOrderStatus;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\ImportOrder;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Fine Post-Shipment Test',
        'default_currency' => 'LYD',
        'overhead_absorption_enabled' => false,
        'transfer_pricing_mode' => 'at_cost',
        'timezone' => 'Africa/Tripoli',
    ]);

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

    $this->otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Other Unit',
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

    $this->supplier = Supplier::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Global Steel Trading',
        'default_currency' => 'USD',
    ]);

    $this->rawMaterial = InventoryItem::create([
        'name' => 'Polyol Resin',
        'code' => 'RAW-POL-001',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'kg',
    ]);

    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Main Warehouse',
        'is_internal_unit' => true,
    ]);

    $this->otherWarehouse = Warehouse::create([
        'operating_unit_id' => $this->otherUnit->id,
        'name' => 'Other Warehouse',
        'is_internal_unit' => true,
    ]);
});

function seedPaidOrder(object $t): ImportOrder
{
    $order = ImportOrder::create([
        'operating_unit_id' => $t->unit->id,
        'supplier_id' => $t->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 100,
        'quantity' => 10,
        'status' => ImportOrderStatus::Paid,
        'booked_fx_rate' => '4.8',
    ]);

    return $order;
}

it('flips at_port to in_transit_to_warehouse on transport_to_warehouse', function () {
    $order = seedPaidOrder($this);
    $order->update(['status' => ImportOrderStatus::AtPort]);

    $this->actingAs($this->owner)
        ->postJson("/api/v1/import-orders/{$order->id}/transition", [
            'action' => 'transport_warehouse',
        ])
        ->assertOk();

    expect($order->fresh()->status)->toBe(ImportOrderStatus::InTransitToWarehouse);
});

it('writes arrived_warehouse_id when the truck shows up', function () {
    $order = seedPaidOrder($this);
    $order->update(['status' => ImportOrderStatus::InTransitToWarehouse]);

    $this->actingAs($this->owner)
        ->postJson("/api/v1/import-orders/{$order->id}/transition", [
            'action' => 'arrived_at_warehouse',
            'warehouse_id' => $this->warehouse->id,
        ])
        ->assertOk();

    $order->refresh();
    expect($order->status)->toBe(ImportOrderStatus::AtWarehouse);
    expect($order->arrived_warehouse_id)->toBe($this->warehouse->id);
});

it('refuses arrived_at_warehouse without a warehouse_id', function () {
    $order = seedPaidOrder($this);
    $order->update(['status' => ImportOrderStatus::InTransitToWarehouse]);

    $this->actingAs($this->owner)
        ->postJson("/api/v1/import-orders/{$order->id}/transition", [
            'action' => 'arrived_at_warehouse',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['warehouse_id']);
});

it('refuses arrived_at_warehouse from the wrong status', function () {
    $order = seedPaidOrder($this);

    $this->actingAs($this->owner)
        ->postJson("/api/v1/import-orders/{$order->id}/transition", [
            'action' => 'arrived_at_warehouse',
            'warehouse_id' => $this->warehouse->id,
        ])
        ->assertStatus(422);
});

it('refuses arrived_at_warehouse from a warehouse in another operating unit', function () {
    $order = seedPaidOrder($this);
    $order->update(['status' => ImportOrderStatus::InTransitToWarehouse]);

    $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson("/api/v1/import-orders/{$order->id}/transition", [
            'action' => 'arrived_at_warehouse',
            'warehouse_id' => $this->otherWarehouse->id,
        ])
        ->assertStatus(422);
});

it('receive_goods defaults the warehouse_id to the arrived one', function () {
    $order = seedPaidOrder($this);
    $order->update(['status' => ImportOrderStatus::AtWarehouse]);
    $order->update(['arrived_warehouse_id' => $this->warehouse->id]);

    $this->actingAs($this->owner)
        ->postJson("/api/v1/import-orders/{$order->id}/transition", [
            'action' => 'receive_goods',
            'received_qty' => 10,
        ])
        ->assertOk();

    $receipt = GoodsReceipt::where('import_order_id', $order->id)->first();
    expect($receipt)->not->toBeNull();
    expect($receipt->warehouse_id)->toBe($this->warehouse->id);
    expect($order->fresh()->status)->toBe(ImportOrderStatus::Received);
});

it('receive_goods still accepts an explicit warehouse override', function () {
    $other = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Overflow Warehouse',
    ]);

    $order = seedPaidOrder($this);
    $order->update(['status' => ImportOrderStatus::AtWarehouse]);
    $order->update(['arrived_warehouse_id' => $this->warehouse->id]);

    $this->actingAs($this->owner)
        ->postJson("/api/v1/import-orders/{$order->id}/transition", [
            'action' => 'receive_goods',
            'received_qty' => 10,
            'warehouse_id' => $other->id,
        ])
        ->assertOk();

    expect(GoodsReceipt::where('import_order_id', $order->id)->value('warehouse_id'))
        ->toBe($other->id);
});

it('drives the full post-shipment flow: paid -> in_transit -> at_port -> in_transit_to_warehouse -> at_warehouse -> received', function () {
    $order = seedPaidOrder($this);

    $this->actingAs($this->owner)
        ->postJson("/api/v1/import-orders/{$order->id}/transition", ['action' => 'shipment'])
        ->assertOk();
    expect($order->fresh()->status)->toBe(ImportOrderStatus::InTransit);

    $this->actingAs($this->owner)
        ->postJson("/api/v1/import-orders/{$order->id}/transition", ['action' => 'arrive_port'])
        ->assertOk();
    expect($order->fresh()->status)->toBe(ImportOrderStatus::AtPort);

    $this->actingAs($this->owner)
        ->postJson("/api/v1/import-orders/{$order->id}/transition", ['action' => 'transport_warehouse'])
        ->assertOk();
    expect($order->fresh()->status)->toBe(ImportOrderStatus::InTransitToWarehouse);

    $this->actingAs($this->owner)
        ->postJson("/api/v1/import-orders/{$order->id}/transition", [
            'action' => 'arrived_at_warehouse',
            'warehouse_id' => $this->warehouse->id,
        ])
        ->assertOk();
    expect($order->fresh()->status)->toBe(ImportOrderStatus::AtWarehouse);

    $this->actingAs($this->owner)
        ->postJson("/api/v1/import-orders/{$order->id}/transition", [
            'action' => 'receive_goods',
            'received_qty' => 10,
        ])
        ->assertOk();
    expect($order->fresh()->status)->toBe(ImportOrderStatus::Received);
});
