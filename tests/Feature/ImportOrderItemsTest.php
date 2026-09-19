<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\ImportOrder;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Fine Import Test',
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
        'sku' => 'RAW-POL-001',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'kg',
    ]);

    $this->packaging = InventoryItem::create([
        'name' => 'Cardboard Box',
        'sku' => 'PKG-BOX-001',
        'item_type' => 'packaging',
        'unit_of_measure' => 'each',
    ]);

    $this->foamBlock = InventoryItem::create([
        'name' => 'Foam Block A',
        'sku' => 'FOAM-A-001',
        'item_type' => 'foam_block',
        'unit_of_measure' => 'm3',
    ]);
});

it('lets an owner create an order with multiple line items', function () {
    $payload = [
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'items' => [
            ['inventory_item_id' => $this->rawMaterial->id, 'quantity' => 1000, 'unit_price' => 1.5],
            ['inventory_item_id' => $this->packaging->id, 'quantity' => 50, 'unit_price' => 2.5],
        ],
    ];

    $response = $this->actingAs($this->owner)->postJson('/api/v1/import-orders', $payload);

    $response->assertStatus(201);

    $body = $response->json();
    expect((float) $body['data']['items']['items_total'])->toBe(1625.0);
    expect((float) $body['data']['quantity'])->toBe(1050.0);
    expect((float) $body['data']['negotiated_price'])->toBe(1625.0);

    $this->assertDatabaseCount('import_order_items', 2);
});

it('returns items in index and show responses', function () {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/import-orders', [
            'operating_unit_id' => $this->unit->id,
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'items' => [
                ['inventory_item_id' => $this->rawMaterial->id, 'quantity' => 100, 'unit_price' => 1.0],
            ],
        ])
        ->assertStatus(201);

    $list = $this->actingAs($this->owner)->getJson('/api/v1/import-orders')->json();
    expect($list['data'][0]['items']['data'][0]['inventory_item']['name'])->toBe('Polyol Resin');
    expect((float) $list['data'][0]['items']['data'][0]['line_total'])->toBe(100.0);

    $orderId = \App\Models\ImportOrder::first()->id;

    $show = $this->actingAs($this->owner)->getJson("/api/v1/import-orders/{$orderId}")->json();
    expect((float) $show['data']['items']['data'][0]['quantity'])->toBe(100.0);
    expect((float) $show['data']['items']['data'][0]['unit_price'])->toBe(1.0);
    expect($show['data']['items']['data'][0]['currency'])->toBe('USD');
});

it('rejects a line that references a non-procurement item type', function () {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/import-orders', [
            'operating_unit_id' => $this->unit->id,
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'items' => [
                ['inventory_item_id' => $this->foamBlock->id, 'quantity' => 10, 'unit_price' => 5.0],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_ITEM_TYPE');
});

it('rejects a line with an unknown inventory item', function () {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/import-orders', [
            'operating_unit_id' => $this->unit->id,
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'items' => [
                ['inventory_item_id' => '00000000-0000-0000-0000-000000000000', 'quantity' => 10, 'unit_price' => 5.0],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.inventory_item_id']);
});

it('rejects a line with zero or negative quantity', function () {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/import-orders', [
            'operating_unit_id' => $this->unit->id,
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'items' => [
                ['inventory_item_id' => $this->rawMaterial->id, 'quantity' => 0, 'unit_price' => 5.0],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.quantity']);
});

it('still allows the legacy header-only order creation for backward compat', function () {
    // Old callers that send header-level quantity + negotiated_price only
    // (no items array) must keep working. The FormRequest uses
    // `required_without:items` so the legacy fields stay mandatory when
    // no items are supplied.
    $response = $this->actingAs($this->owner)
        ->postJson('/api/v1/import-orders', [
            'operating_unit_id' => $this->unit->id,
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'quantity' => 500,
            'negotiated_price' => 250,
        ]);

    $response->assertStatus(201);

    $body = $response->json();
    expect((float) $body['data']['quantity'])->toBe(500.0);
    expect((float) $body['data']['negotiated_price'])->toBe(250.0);
    expect($body['data']['items']['data'])->toBe([]);
    expect((float) $body['data']['items']['items_total'])->toBe(0.0);
    expect((float) $body['data']['total_amount'])->toBe(125000.0);
});

it('calculates total cost correctly for multi-item orders and payment requests', function () {
    $payload = [
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'items' => [
            ['inventory_item_id' => $this->rawMaterial->id, 'quantity' => 1000, 'unit_price' => 1.5],
            ['inventory_item_id' => $this->packaging->id, 'quantity' => 50, 'unit_price' => 2.5],
        ],
    ];

    $response = $this->actingAs($this->owner)->postJson('/api/v1/import-orders', $payload);
    $response->assertStatus(201);

    $body = $response->json();
    expect((float) $body['data']['total_amount'])->toBe(1625.0);

    $order = ImportOrder::first();
    expect($order->totalCost())->toBe(1625.0);

    // Transition to pending payment should request exactly totalCost ($1,625), NOT $1,706,250
    $stateService = app(\App\Services\ImportOrderStateService::class);
    $order = $stateService->transitionToPendingPayment($order);

    $paymentRequest = $order->paymentRequests()->first();
    expect((float) $paymentRequest->amount_requested)->toBe(1625.0);
});

it('lets an owner update line items of a draft import order', function () {
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1500.0,
        'quantity' => 1000.0,
        'status' => \App\Enums\ImportOrderStatus::Draft,
    ]);

    \App\Models\ImportOrderItem::create([
        'import_order_id' => $order->id,
        'inventory_item_id' => $this->rawMaterial->id,
        'quantity' => 1000,
        'unit_price' => 1.5,
        'currency' => 'USD',
    ]);

    $updatePayload = [
        'items' => [
            ['inventory_item_id' => $this->rawMaterial->id, 'quantity' => 500, 'unit_price' => 2.0],
            ['inventory_item_id' => $this->packaging->id, 'quantity' => 100, 'unit_price' => 3.0],
        ],
    ];

    $response = $this->actingAs($this->owner)->putJson("/api/v1/import-orders/{$order->id}", $updatePayload);

    $response->assertStatus(200);
    $data = $response->json('data');

    // 500*2 + 100*3 = 1000 + 300 = 1300
    expect((float) $data['total_amount'])->toBe(1300.0);
    expect((float) $data['quantity'])->toBe(600.0);
    expect((float) $data['items']['items_total'])->toBe(1300.0);
    expect(count($data['items']['data']))->toBe(2);

    $this->assertDatabaseCount('import_order_items', 2);
    $order->refresh();
    expect($order->totalCost())->toBe(1300.0);
});

it('rejects editing items if the import order is not in draft status', function () {
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1500.0,
        'quantity' => 1000.0,
        'status' => \App\Enums\ImportOrderStatus::PendingPayment,
    ]);

    $updatePayload = [
        'items' => [
            ['inventory_item_id' => $this->rawMaterial->id, 'quantity' => 200, 'unit_price' => 1.5],
        ],
    ];

    $response = $this->actingAs($this->owner)->putJson("/api/v1/import-orders/{$order->id}", $updatePayload);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'ORDER_NOT_IN_DRAFT');
});

it('rejects updating items with a non-procurement item type', function () {
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1500.0,
        'quantity' => 1000.0,
        'status' => \App\Enums\ImportOrderStatus::Draft,
    ]);

    $updatePayload = [
        'items' => [
            ['inventory_item_id' => $this->foamBlock->id, 'quantity' => 50, 'unit_price' => 10.0],
        ],
    ];

    $response = $this->actingAs($this->owner)->putJson("/api/v1/import-orders/{$order->id}", $updatePayload);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_ITEM_TYPE');
});
