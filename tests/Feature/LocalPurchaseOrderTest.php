<?php

declare(strict_types=1);

use App\Enums\PurchaseOrderKind;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Account;
use App\Models\ChartOfAccounts;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Role;
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
    $this->company = Company::create(['name' => 'Fine Local PO Test', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'BP', 'workflow_set' => '[]', 'default_role_template' => '[]', 'default_inventory_config' => '[]',
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'Test Unit', 'code' => 'LPO-1', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);
    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'WH', 'code' => 'WH-1',
    ]);
    $this->supplier = Supplier::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'Local Vendor', 'default_currency' => 'LYD',
    ]);

    // Create a procurement-eligible inventory category + items so the
    // purchase_order_items FK to inventory_items is satisfied.
    $this->category = ItemCategory::create([
        'name' => 'Raw Materials', 'code' => 'RM', 'parent_id' => null,
        'item_type' => 'raw_material',
    ]);
    $this->itemA = InventoryItem::create([
        'name' => 'Steel Rod', 'code' => 'SR-1', 'item_type' => 'raw_material',
        'unit_of_measure' => 'piece', 'category_id' => $this->category->id,
    ]);
    $this->itemB = InventoryItem::create([
        'name' => 'Wooden Plank', 'code' => 'WP-1', 'item_type' => 'raw_material',
        'unit_of_measure' => 'piece', 'category_id' => $this->category->id,
    ]);

    $this->globalRole = Role::create(['name' => 'AM', 'slug' => 'accounting-manager']);
    $this->globalUser = User::factory()->create(['must_change_password' => false]);
    UserRole::create([
        'user_id' => $this->globalUser->id,
        'role_id' => $this->globalRole->id,
        'operating_unit_id' => null,
    ]);

    $this->stateService = app(PurchaseOrderStateService::class);
});

function makeLocalOrder(): PurchaseOrder
{
    /** @var TestCase $test */
    $test = test();

    return PurchaseOrder::create([
        'operating_unit_id' => $test->unit->id,
        'supplier_id' => $test->supplier->id,
        'currency' => 'LYD',
        'kind' => 'local',
        'status' => 'draft',
        'negotiated_price' => 1000,
        'quantity' => 10,
        'destination_warehouse_id' => $test->warehouse->id,
    ]);
}

function makeLocalItem(PurchaseOrder $order, int $qty = 10, int $received = 0): PurchaseOrderItem
{
    /** @var TestCase $test */
    $test = test();

    return PurchaseOrderItem::create([
        'purchase_order_id' => $order->id,
        'inventory_item_id' => $qty === 10 ? $test->itemA->id : $test->itemB->id,
        'quantity' => $qty,
        'unit_price' => 50,
        'currency' => 'LYD',
        'received_quantity' => $received,
    ]);
}

test('approve: draft transitions to approved for local POs', function () {
    $order = makeLocalOrder();
    expect($order->status)->toBe(PurchaseOrderStatus::Draft);

    $result = $this->stateService->approve($order);

    expect($result->status)->toBe(PurchaseOrderStatus::Approved);
});

test('approve: refuses non-local POs', function () {
    $order = PurchaseOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        // No kind => defaults to foreign
        'negotiated_price' => 500,
        'quantity' => 5,
    ]);
    expect($order->kind)->toBe(PurchaseOrderKind::Foreign);

    $this->expectException(InvalidArgumentException::class);
    $this->stateService->approve($order);
});

test('approve: refuses non-draft states', function () {
    $order = makeLocalOrder();
    $order->status = PurchaseOrderStatus::Approved;
    $order->save();

    $this->expectException(InvalidStateTransitionException::class);
    $this->stateService->approve($order);
});

test('receiveItems: atomic per-line batch updates received_quantity', function () {
    $order = makeLocalOrder();
    $order->status = PurchaseOrderStatus::Approved;
    $order->save();

    $i1 = makeLocalItem($order, 10);
    $i2 = makeLocalItem($order, 5);

    $this->stateService->receiveItems($order, [
        ['id' => $i1->id, 'received_quantity' => 6],
        ['id' => $i2->id, 'received_quantity' => 5],
    ]);

    expect((float) $i1->fresh()->received_quantity)->toEqual(6.0)
        ->and((float) $i2->fresh()->received_quantity)->toEqual(5.0);
    // Partial: status should still be Approved
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Approved);
});

test('receiveItems: full receipt transitions approved → received', function () {
    $order = makeLocalOrder();
    $order->status = PurchaseOrderStatus::Approved;
    $order->save();

    $i1 = makeLocalItem($order, 10);

    $result = $this->stateService->receiveItems($order, [
        ['id' => $i1->id, 'received_quantity' => 10],
    ]);

    expect($result->status)->toBe(PurchaseOrderStatus::Received);
});

test('receiveItems: refuses non-local POs', function () {
    $order = PurchaseOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 500, 'quantity' => 5,
    ]);
    expect($order->kind)->toBe(PurchaseOrderKind::Foreign);

    $this->expectException(InvalidArgumentException::class);
    $this->stateService->receiveItems($order, []);
});

test('receiveItems: refuses rolling back received_quantity', function () {
    $order = makeLocalOrder();
    $order->status = PurchaseOrderStatus::Approved;
    $order->save();
    $i1 = makeLocalItem($order, 10);
    $this->stateService->receiveItems($order, [['id' => $i1->id, 'received_quantity' => 5]]);

    $this->expectException(InvalidArgumentException::class);
    $this->stateService->receiveItems($order, [['id' => $i1->id, 'received_quantity' => 3]]);
});

test('receiveItems: refuses exceeding quantity', function () {
    $order = makeLocalOrder();
    $order->status = PurchaseOrderStatus::Approved;
    $order->save();
    $i1 = makeLocalItem($order, 10);

    $this->expectException(InvalidArgumentException::class);
    $this->stateService->receiveItems($order, [['id' => $i1->id, 'received_quantity' => 11]]);
});

test('receiveItems: refuses line item that does not belong to this order', function () {
    $order = makeLocalOrder();
    $order->status = PurchaseOrderStatus::Approved;
    $order->save();

    $this->expectException(InvalidArgumentException::class);
    $this->stateService->receiveItems($order, [
        ['id' => '99999999-9999-9999-9999-999999999999', 'received_quantity' => 5],
    ]);
});

test('receiveItems: refuses draft state', function () {
    $order = makeLocalOrder();
    expect($order->status)->toBe(PurchaseOrderStatus::Draft);

    $i1 = makeLocalItem($order, 10);

    $this->expectException(InvalidStateTransitionException::class);
    $this->stateService->receiveItems($order, [['id' => $i1->id, 'received_quantity' => 10]]);
});

test('payLocal: received → paid → closed (auto-close on full receipt + paid)', function () {
    $order = makeLocalOrder();
    $order->status = PurchaseOrderStatus::Received;
    $order->save();
    makeLocalItem($order, 10, 10);

    $source = Account::where('account_code', '1211')->firstOrFail();
    $result = $this->stateService->payLocal($order, $source->id);

    expect($result->status)->toBe(PurchaseOrderStatus::Closed);
});

test('payLocal: does not auto-close when items not fully received', function () {
    $order = makeLocalOrder();
    $order->status = PurchaseOrderStatus::Received;
    $order->save();
    makeLocalItem($order, 10, 5);

    $source = Account::where('account_code', '1211')->firstOrFail();
    $result = $this->stateService->payLocal($order, $source->id);

    expect($result->status)->toBe(PurchaseOrderStatus::Paid);
});

test('payLocal: refuses non-local POs', function () {
    $order = PurchaseOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 500, 'quantity' => 5,
    ]);
    expect($order->kind)->toBe(PurchaseOrderKind::Foreign);

    $source = Account::where('account_code', '1211')->firstOrFail();
    $this->expectException(InvalidArgumentException::class);
    $this->stateService->payLocal($order, $source->id);
});

test('payLocal: refuses non-received state', function () {
    $order = makeLocalOrder();
    // status is Draft, not Received

    $source = Account::where('account_code', '1211')->firstOrFail();
    $this->expectException(InvalidStateTransitionException::class);
    $this->stateService->payLocal($order, $source->id);
});

test('isFullyReceived: true for empty order', function () {
    $order = makeLocalOrder();
    expect($order->isFullyReceived())->toBeTrue();
});

test('isFullyReceived: false when some items not received', function () {
    $order = makeLocalOrder();
    makeLocalItem($order, 10, 5);
    expect($order->isFullyReceived())->toBeFalse();
});

test('payableAccountCode: returns supplier account_id when set', function () {
    $coa = ChartOfAccounts::first();
    $subAccount = Account::create([
        'chart_of_accounts_id' => $coa->id,
        'account_code' => '2101', 'name' => 'Supplier A/P',
        'type' => 'liability', 'currency' => 'LYD',
    ]);
    $this->supplier->update(['account_id' => $subAccount->id]);

    $order = makeLocalOrder();
    expect($order->payableAccountCode())->toBe('2101');
});

test('payableAccountCode: falls back to 21 when no supplier account_id', function () {
    $order = makeLocalOrder();
    expect($this->supplier->account_id)->toBeNull();
    expect($order->payableAccountCode())->toBe('21');
});

test('?kind=local filter returns only local POs (index)', function () {
    // Make one local and one foreign PO
    $local = makeLocalOrder();
    PurchaseOrder::create([
        'operating_unit_id' => $this->unit->id, 'supplier_id' => $this->supplier->id,
        'currency' => 'USD', 'kind' => 'foreign',
        'negotiated_price' => 500, 'quantity' => 5,
    ]);

    $response = $this->actingAs($this->globalUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/purchase-orders?kind=local');

    $response->assertStatus(200);
    expect(collect($response->json('data'))->pluck('id'))->toContain($local->id)
        ->and(collect($response->json('data'))->count())->toBe(1);
});

test('?kind=foreign filter returns only foreign POs', function () {
    $local = makeLocalOrder();
    $foreign = PurchaseOrder::create([
        'operating_unit_id' => $this->unit->id, 'supplier_id' => $this->supplier->id,
        'currency' => 'USD', 'kind' => 'foreign',
        'negotiated_price' => 500, 'quantity' => 5,
    ]);

    $response = $this->actingAs($this->globalUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/purchase-orders?kind=foreign');

    $response->assertStatus(200);
    expect(collect($response->json('data'))->pluck('id'))->toContain($foreign->id)
        ->and(collect($response->json('data'))->count())->toBe(1);
});

test('create PO with kind=local locks currency to LYD', function () {
    $response = $this->actingAs($this->globalUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/purchase-orders', [
            'operating_unit_id' => $this->unit->id,
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD', // trying to set USD for a local PO
            'kind' => 'local',
            'negotiated_price' => 1000,
            'quantity' => 10,
        ]);

    $response->assertStatus(201);
    expect($response->json('data.currency'))->toBe('LYD');
    expect($response->json('data.kind'))->toBe('local');
});

test('receive endpoint: atomic per-line batch on a local PO', function () {
    $order = makeLocalOrder();
    $order->status = PurchaseOrderStatus::Approved;
    $order->save();
    $i1 = makeLocalItem($order, 10);
    $i2 = makeLocalItem($order, 5);

    $response = $this->actingAs($this->globalUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/purchase-orders/{$order->id}/receive", [
            'items' => [
                ['id' => $i1->id, 'received_quantity' => 6],
                ['id' => $i2->id, 'received_quantity' => 5],
            ],
        ]);

    $response->assertStatus(200);
    expect((float) $i1->fresh()->received_quantity)->toEqual(6.0)
        ->and((float) $i2->fresh()->received_quantity)->toEqual(5.0);
    // Partial receive keeps status at Approved (only full receipt transitions to Received)
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Approved);
});

test('receive endpoint: refuses foreign POs', function () {
    $order = PurchaseOrder::create([
        'operating_unit_id' => $this->unit->id, 'supplier_id' => $this->supplier->id,
        'currency' => 'USD', 'kind' => 'foreign',
        'negotiated_price' => 500, 'quantity' => 5,
    ]);

    $response = $this->actingAs($this->globalUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/purchase-orders/{$order->id}/receive", [
            'items' => [['id' => '11111111-1111-1111-1111-111111111111', 'received_quantity' => 1]],
        ]);

    $response->assertStatus(422);
    expect($response->json('code'))->toBe('INVALID_PURCHASE_ORDER_OPERATION');
});

test('payLocal endpoint: transitions received → paid → closed (full receipt)', function () {
    $order = makeLocalOrder();
    $order->status = PurchaseOrderStatus::Received;
    $order->save();
    makeLocalItem($order, 10, 10);
    $source = Account::where('account_code', '1211')->firstOrFail();

    $response = $this->actingAs($this->globalUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/purchase-orders/{$order->id}/pay-local", [
            'payment_source_account_id' => $source->id,
        ]);

    $response->assertStatus(200);
    $order->refresh();
    expect($order->status)->toBe(PurchaseOrderStatus::Closed)
        ->and($order->payment_source_account_id)->toBe($source->id);
});

test('payLocal endpoint: requires payment_source_account_id', function () {
    $order = makeLocalOrder();
    $order->status = PurchaseOrderStatus::Received;
    $order->save();
    makeLocalItem($order, 10, 10);

    $response = $this->actingAs($this->globalUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/purchase-orders/{$order->id}/pay-local", []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['payment_source_account_id']);
});

test('payLocal: posts DR supplier advance / CR chosen source journal and persists the source on the order', function () {
    $order = makeLocalOrder();
    $order->status = PurchaseOrderStatus::Received;
    $order->save();
    makeLocalItem($order, 10, 10);
    $source = Account::where('account_code', '1211')->firstOrFail();

    $updated = $this->stateService->payLocal($order, $source->id);

    expect($updated->payment_source_account_id)->toBe($source->id)
        ->and($updated->status)->toBe(PurchaseOrderStatus::Closed);

    $entry = JournalEntry::where('source_document_type', 'PurchaseOrder')
        ->where('source_document_id', $updated->id)
        ->latest()->first();
    expect($entry)->not->toBeNull();

    $lines = $entry->lines()->with('account')->get();
    $debit = $lines->firstWhere(fn ($l) => (float) $l->debit > 0);
    $credit = $lines->firstWhere(fn ($l) => (float) $l->credit > 0);

    expect($debit->account->account_code)->toBe('15')
        ->and($credit->account_id)->toBe($source->id);
});

test('approve endpoint: draft → approved', function () {
    $order = makeLocalOrder();

    $response = $this->actingAs($this->globalUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/purchase-orders/{$order->id}/approve");

    $response->assertStatus(200);
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Approved);
});
