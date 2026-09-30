<?php

declare(strict_types=1);

use App\Enums\PurchaseOrderStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\FxRate;
use App\Models\GoodsReceipt;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\OperatingUnit;
use App\Models\PaymentRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\PurchaseOrderStateService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Routing Test', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'BP', 'workflow_set' => '[]', 'default_role_template' => '[]', 'default_inventory_config' => '[]',
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'Routing Unit', 'code' => 'RT-1', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);
    $this->destinationWarehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'Destination WH', 'code' => 'WH-DST',
    ]);
    $this->overrideWarehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'Override WH', 'code' => 'WH-OVR',
    ]);
    $this->otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'Other Unit', 'code' => 'RT-2', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);
    $this->foreignWarehouse = Warehouse::create([
        'operating_unit_id' => $this->otherUnit->id, 'name' => 'Foreign WH', 'code' => 'WH-FOR',
    ]);

    seedUnitAccounts([$this->unit, $this->otherUnit]);
    $this->supplier = Supplier::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'Routing Vendor', 'default_currency' => 'LYD',
    ]);

    $this->category = ItemCategory::create([
        'name' => 'Raw', 'code' => 'RAW', 'parent_id' => null, 'item_type' => 'raw_material',
    ]);
    $this->itemA = InventoryItem::create([
        'name' => 'Item A', 'code' => 'RA-1', 'item_type' => 'raw_material',
        'unit_of_measure' => 'piece', 'category_id' => $this->category->id,
    ]);
    $this->itemB = InventoryItem::create([
        'name' => 'Item B', 'code' => 'RB-1', 'item_type' => 'raw_material',
        'unit_of_measure' => 'piece', 'category_id' => $this->category->id,
    ]);

    $role = Role::create(['name' => 'Buyer', 'slug' => 'buyer']);
    $this->user = User::factory()->create(['must_change_password' => false]);
    UserRole::create([
        'user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unit->id,
    ]);

    $this->stateService = app(PurchaseOrderStateService::class);
});

function makeLocalRoutingOrder(TestCase $test, ?string $destinationWarehouseId = null): PurchaseOrder
{
    return PurchaseOrder::create([
        'operating_unit_id' => $test->unit->id,
        'supplier_id' => $test->supplier->id,
        'currency' => 'LYD',
        'kind' => 'local',
        'status' => 'received',
        'negotiated_price' => 1000,
        'quantity' => 10,
        'destination_warehouse_id' => $destinationWarehouseId,
    ]);
}

function addItem(PurchaseOrder $order, InventoryItem $item, float $qty = 10, float $unitPrice = 50.0, float $received = 0.0): PurchaseOrderItem
{
    return PurchaseOrderItem::create([
        'purchase_order_id' => $order->id,
        'inventory_item_id' => $item->id,
        'quantity' => $qty,
        'unit_price' => $unitPrice,
        'currency' => 'LYD',
        'received_quantity' => $received,
    ]);
}

test('local: payLocal creates one StockLot per line item in the destination warehouse', function () {
    $order = makeLocalRoutingOrder($this, $this->destinationWarehouse->id);
    $i1 = addItem($order, $this->itemA, qty: 10, unitPrice: 50, received: 10);
    $i2 = addItem($order, $this->itemB, qty: 6, unitPrice: 30, received: 6);

    $source = Account::where('account_code', '1211')->firstOrFail();
    $this->stateService->payLocal($order, $source->id);

    $lots = StockLot::query()->whereIn('inventory_item_id', [$this->itemA->id, $this->itemB->id])->get();
    expect($lots)->toHaveCount(2)
        ->and((float) $lots->where('inventory_item_id', $this->itemA->id)->first()->quantity)->toBe(10.0)
        ->and((float) $lots->where('inventory_item_id', $this->itemB->id)->first()->quantity)->toBe(6.0)
        ->and($lots->where('inventory_item_id', $this->itemA->id)->first()->warehouse_id)->toBe($this->destinationWarehouse->id)
        ->and((float) $lots->where('inventory_item_id', $this->itemA->id)->first()->unit_cost)->toBe(50.0)
        ->and((float) $lots->where('inventory_item_id', $this->itemB->id)->first()->unit_cost)->toBe(30.0);
});

test('local: partial receipt only stocks the received quantity, not the ordered quantity', function () {
    $order = makeLocalRoutingOrder($this, $this->destinationWarehouse->id);
    addItem($order, $this->itemA, qty: 10, unitPrice: 50, received: 4);

    $source = Account::where('account_code', '1211')->firstOrFail();
    $this->stateService->payLocal($order, $source->id);

    $lot = StockLot::where('inventory_item_id', $this->itemA->id)->sole();
    expect((float) $lot->quantity)->toBe(4.0);
});

test('local: payLocal throws when destination_warehouse_id is not set', function () {
    $order = makeLocalRoutingOrder($this, null);
    addItem($order, $this->itemA, qty: 10, unitPrice: 50, received: 10);

    $source = Account::where('account_code', '1211')->firstOrFail();

    expect(fn () => $this->stateService->payLocal($order, $source->id))
        ->toThrow(InvalidArgumentException::class, 'Destination warehouse must be set');
});

test('local: payLocal throws when destination warehouse belongs to a different operating unit', function () {
    $order = makeLocalRoutingOrder($this, $this->foreignWarehouse->id);
    addItem($order, $this->itemA, qty: 10, unitPrice: 50, received: 10);

    $source = Account::where('account_code', '1211')->firstOrFail();

    expect(fn () => $this->stateService->payLocal($order, $source->id))
        ->toThrow(InvalidArgumentException::class, 'does not belong');
});

test('local: payLocal rolls back if no line items have received_quantity > 0', function () {
    $order = makeLocalRoutingOrder($this, $this->destinationWarehouse->id);
    addItem($order, $this->itemA, qty: 10, unitPrice: 50, received: 0);

    $source = Account::where('account_code', '1211')->firstOrFail();
    $this->stateService->payLocal($order, $source->id);

    expect(StockLot::query()->count())->toBe(0)
        ->and($order->fresh()->status)->toBe(PurchaseOrderStatus::Paid);
});

test('foreign: completeOrder creates StockLots in arrived_warehouse_id when set (override wins)', function () {
    FxRate::create([
        'from_currency' => 'USD', 'to_currency' => 'LYD',
        'rate' => 4.8, 'captured_at' => now(),
    ]);

    $order = PurchaseOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'kind' => 'foreign',
        'status' => 'received',
        'negotiated_price' => 1000,
        'quantity' => 10,
        'booked_fx_rate' => 4.8,
        'destination_warehouse_id' => $this->destinationWarehouse->id,
        'arrived_warehouse_id' => $this->overrideWarehouse->id,
    ]);
    addItem($order, $this->itemA, qty: 10, unitPrice: 100, received: 0);
    GoodsReceipt::create([
        'purchase_order_id' => $order->id,
        'warehouse_id' => $this->overrideWarehouse->id,
        'received_qty' => 10,
    ]);
    PaymentRequest::create([
        'operating_unit_id' => $this->unit->id,
        'purchase_order_id' => $order->id,
        'route' => 'market',
        'amount_requested' => 1000,
        'fx_rate_used' => 4.8,
        'status' => 'paid',
    ]);

    $this->stateService->completeOrder($order);

    $lot = StockLot::where('inventory_item_id', $this->itemA->id)->sole();
    expect((string) $lot->warehouse_id)->toBe($this->overrideWarehouse->id);
});

test('foreign: completeOrder falls back to destination_warehouse_id when arrived_warehouse_id is null', function () {
    FxRate::create([
        'from_currency' => 'USD', 'to_currency' => 'LYD',
        'rate' => 4.8, 'captured_at' => now(),
    ]);

    $order = PurchaseOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'kind' => 'foreign',
        'status' => 'received',
        'negotiated_price' => 1000,
        'quantity' => 10,
        'booked_fx_rate' => 4.8,
        'destination_warehouse_id' => $this->destinationWarehouse->id,
    ]);
    addItem($order, $this->itemA, qty: 10, unitPrice: 100, received: 0);
    GoodsReceipt::create([
        'purchase_order_id' => $order->id,
        'warehouse_id' => $this->destinationWarehouse->id,
        'received_qty' => 10,
    ]);
    PaymentRequest::create([
        'operating_unit_id' => $this->unit->id,
        'purchase_order_id' => $order->id,
        'route' => 'market',
        'amount_requested' => 1000,
        'fx_rate_used' => 4.8,
        'status' => 'paid',
    ]);

    $this->stateService->completeOrder($order);

    $lot = StockLot::where('inventory_item_id', $this->itemA->id)->sole();
    expect((string) $lot->warehouse_id)->toBe($this->destinationWarehouse->id);
});

test('foreign: completeOrder throws when no destination warehouse is resolvable', function () {
    FxRate::create([
        'from_currency' => 'USD', 'to_currency' => 'LYD',
        'rate' => 4.8, 'captured_at' => now(),
    ]);

    $order = PurchaseOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'kind' => 'foreign',
        'status' => 'received',
        'negotiated_price' => 1000,
        'quantity' => 10,
        'booked_fx_rate' => 4.8,
    ]);
    addItem($order, $this->itemA, qty: 10, unitPrice: 100, received: 0);
    GoodsReceipt::create([
        'purchase_order_id' => $order->id,
        'warehouse_id' => $this->destinationWarehouse->id,
        'received_qty' => 10,
    ]);
    PaymentRequest::create([
        'operating_unit_id' => $this->unit->id,
        'purchase_order_id' => $order->id,
        'route' => 'market',
        'amount_requested' => 1000,
        'fx_rate_used' => 4.8,
        'status' => 'paid',
    ]);

    expect(fn () => $this->stateService->completeOrder($order))
        ->toThrow(InvalidArgumentException::class, 'Destination warehouse must be set');
});
