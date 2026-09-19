<?php

declare(strict_types=1);

use App\Enums\ImportOrderStatus;
use App\Enums\PaymentRoute;
use App\Models\Company;
use App\Models\ImportOrder;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\ImportOrderStateService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Import Global Co',
        'default_currency' => 'LYD',
        'overhead_absorption_enabled' => true,
        'transfer_pricing_mode' => 'cost_plus',
        'timezone' => 'UTC',
    ]);

    // Completing an order now posts to the ledger, and a missing account is
    // fatal by design (ACC-02).
    $this->seed(ChartOfAccountsSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Standard Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->operatingUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Tripoli Imports Hub',
        'unit_type' => 'warehouse',
        'currency' => 'LYD',
        'status' => 'active',
    ]);

    $this->supplier = Supplier::create([
        'operating_unit_id' => $this->operatingUnit->id,
        'name' => 'Mediterranean Steel Corp',
        'default_currency' => 'USD',
    ]);

    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->operatingUnit->id,
        'name' => 'Central Port Depot',
        'code' => 'CPD-01',
    ]);

    $this->user = User::factory()->create([
        'must_change_password' => false,
    ]);

    $this->stateService = app(ImportOrderStateService::class);
});

test('full import order state machine lifecycle transition', function () {
    // 1. Create Draft Order
    $order = ImportOrder::create([
        'operating_unit_id' => $this->operatingUnit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 120.50,
        'quantity' => 100,
        'status' => ImportOrderStatus::Draft,
    ]);

    expect($order->status)->toBe(ImportOrderStatus::Draft);

    // 2. Transition to Pending Payment
    $this->stateService->transitionToPendingPayment($order);
    expect($order->fresh()->status)->toBe(ImportOrderStatus::PendingPayment);
    expect($order->paymentRequests()->count())->toBe(1);

    // 3. Select Payment Route (Bank Route with 65,000 LYD hold)
    $this->stateService->selectPaymentRoute(
        $order,
        PaymentRoute::Bank,
        12050.00,
        65000.00,
        'INV-2026-901'
    );
    expect($order->fresh()->status)->toBe(ImportOrderStatus::AwaitingBankApproval);
    $paymentRequest = $order->paymentRequests()->first();
    expect($paymentRequest->bankHold)->not()->toBeNull();
    expect((float) $paymentRequest->bankHold->held_amount_lyd)->toBe(65000.00);

    // 4. Execute Payment (Realized FX Rate 5.20 LYD/USD)
    $this->stateService->executePayment($paymentRequest, 5.20, 62660.00, 'BNK-REF-7711');
    expect($order->fresh()->status)->toBe(ImportOrderStatus::Paid);
    expect((float) $paymentRequest->fresh()->bankHold->released_amount)->toBe(2340.00);

    // 5. Confirm Shipment
    $this->stateService->confirmShipment($order->fresh());
    expect($order->fresh()->status)->toBe(ImportOrderStatus::InTransit);

    // 6. Arrive at Port
    $this->stateService->arriveAtPort($order->fresh());
    expect($order->fresh()->status)->toBe(ImportOrderStatus::AtPort);

    // 7. Transport to Warehouse
    $this->stateService->transportToWarehouse($order->fresh());
    expect($order->fresh()->status)->toBe(ImportOrderStatus::InTransitToWarehouse);

    // 7b. Arrive at Warehouse
    $this->stateService->arriveAtWarehouse($order->fresh(), $this->warehouse->id);
    expect($order->fresh()->status)->toBe(ImportOrderStatus::AtWarehouse);

    // 8. Receive Goods
    $receipt = $this->stateService->receiveGoods($order->fresh(), $this->warehouse->id, 100, 'All 100 units in excellent condition');
    expect($order->fresh()->status)->toBe(ImportOrderStatus::Received);
    expect((float) $receipt->received_qty)->toBe(100.00);

    // Add confirmed landed cost line
    $order->landedCostLines()->create([
        'type' => 'freight',
        'amount' => 1500.00,
        'currency' => 'LYD',
        'is_confirmed' => true,
    ]);

    // 9. Complete Order
    $this->stateService->completeOrder($order->fresh());
    expect($order->fresh()->status)->toBe(ImportOrderStatus::Complete);
});

test('cannot complete import order if unconfirmed landed cost lines exist', function () {
    $order = ImportOrder::create([
        'operating_unit_id' => $this->operatingUnit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 50,
        'quantity' => 10,
        'status' => ImportOrderStatus::Draft,
    ]);

    $this->stateService->transitionToPendingPayment($order->fresh());
    $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Market, 500);
    $paymentRequest = $order->paymentRequests()->first();
    $this->stateService->executePayment($paymentRequest, 5.0);
    $this->stateService->confirmShipment($order->fresh());
    $this->stateService->arriveAtPort($order->fresh());
    $this->stateService->transportToWarehouse($order->fresh());
    $this->stateService->arriveAtWarehouse($order->fresh(), $this->warehouse->id);
    $this->stateService->receiveGoods($order->fresh(), $this->warehouse->id, 10);

    // Add unconfirmed landed cost line
    $order->landedCostLines()->create([
        'type' => 'customs',
        'amount' => 200,
        'is_confirmed' => false,
    ]);

    expect(fn () => $this->stateService->completeOrder($order->fresh()))
        ->toThrow(InvalidArgumentException::class);
});

test('non-manager user cannot select payment route in step 2', function () {
    $operatorUser = User::factory()->create(['must_change_password' => false]);
    $operatorRole = Role::firstOrCreate(['slug' => 'foam-operator'], ['name' => 'Foam Operator']);
    UserRole::create([
        'user_id' => $operatorUser->id,
        'role_id' => $operatorRole->id,
        'operating_unit_id' => $this->operatingUnit->id,
    ]);

    $order = ImportOrder::create([
        'operating_unit_id' => $this->operatingUnit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 100,
        'quantity' => 10,
        'status' => ImportOrderStatus::PendingPayment,
    ]);

    $this->actingAs($operatorUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->operatingUnit->id])
        ->postJson("/api/v1/import-orders/{$order->id}/transition", [
            'action' => 'select_route',
            'route' => 'market',
            'amount_requested' => 1000,
        ])
        ->assertStatus(403);
});

test('finance user can select payment route in step 2', function () {
    $accountant = User::factory()->create(['must_change_password' => false]);
    $accountantRole = Role::firstOrCreate(['slug' => 'accounting-manager'], ['name' => 'Accounting Manager']);
    UserRole::create([
        'user_id' => $accountant->id,
        'role_id' => $accountantRole->id,
        'operating_unit_id' => $this->operatingUnit->id,
    ]);

    $order = ImportOrder::create([
        'operating_unit_id' => $this->operatingUnit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 100,
        'quantity' => 10,
        'status' => ImportOrderStatus::PendingPayment,
    ]);

    $this->actingAs($accountant)
        ->withHeaders(['X-Operating-Unit-ID' => $this->operatingUnit->id])
        ->postJson("/api/v1/import-orders/{$order->id}/transition", [
            'action' => 'select_route',
            'route' => 'market',
            'amount_requested' => 1000,
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'awaiting_transfer');
});

test('non-manager user cannot execute payment in step 3', function () {
    $operatorUser = User::factory()->create(['must_change_password' => false]);
    $operatorRole = Role::firstOrCreate(['slug' => 'foam-operator'], ['name' => 'Foam Operator']);
    UserRole::create([
        'user_id' => $operatorUser->id,
        'role_id' => $operatorRole->id,
        'operating_unit_id' => $this->operatingUnit->id,
    ]);

    $order = ImportOrder::create([
        'operating_unit_id' => $this->operatingUnit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 100,
        'quantity' => 10,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Market, 1000);
    $paymentRequest = $order->paymentRequests()->first();

    $this->actingAs($operatorUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->operatingUnit->id])
        ->postJson("/api/v1/payment-requests/{$paymentRequest->id}/execute", [
            'fx_rate_used' => 5.20,
        ])
        ->assertStatus(403);
});

test('managers can execute payment in step 3 directly via payment request or transition and view suppliers', function () {
    $managerUser = User::factory()->create(['must_change_password' => false]);
    $managerRole = Role::firstOrCreate(['slug' => 'store-manager'], ['name' => 'Store Manager']);
    UserRole::create([
        'user_id' => $managerUser->id,
        'role_id' => $managerRole->id,
        'operating_unit_id' => $this->operatingUnit->id,
    ]);

    // Manager can view suppliers
    $this->actingAs($managerUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->operatingUnit->id])
        ->getJson('/api/v1/suppliers')
        ->assertStatus(200);

    $order = ImportOrder::create([
        'operating_unit_id' => $this->operatingUnit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 100,
        'quantity' => 10,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Market, 1000);
    $paymentRequest = $order->paymentRequests()->first();

    // Manager can view payment requests and receive supplier details
    $this->actingAs($managerUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->operatingUnit->id])
        ->getJson('/api/v1/payment-requests')
        ->assertStatus(200)
        ->assertJsonPath('data.0.import_order.supplier.name', 'Mediterranean Steel Corp');

    // Manager can execute payment
    $this->actingAs($managerUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->operatingUnit->id])
        ->postJson("/api/v1/payment-requests/{$paymentRequest->id}/execute", [
            'fx_rate_used' => 5.20,
            'bank_reference' => 'TXN-STORE-01',
            'extra_allocation_note' => 'Approved by store manager',
        ])
        ->assertStatus(200);

    expect($order->fresh()->status)->toBe(ImportOrderStatus::Paid);
});

test('finance user can execute payment in step 3 directly via payment request or transition', function () {
    $treasury = User::factory()->create(['must_change_password' => false]);
    $treasuryRole = Role::firstOrCreate(['slug' => 'treasury-officer'], ['name' => 'Treasury Officer']);
    UserRole::create([
        'user_id' => $treasury->id,
        'role_id' => $treasuryRole->id,
        'operating_unit_id' => $this->operatingUnit->id,
    ]);

    $order = ImportOrder::create([
        'operating_unit_id' => $this->operatingUnit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 100,
        'quantity' => 10,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Market, 1000);

    $this->actingAs($treasury)
        ->withHeaders(['X-Operating-Unit-ID' => $this->operatingUnit->id])
        ->postJson("/api/v1/import-orders/{$order->id}/transition", [
            'action' => 'execute_payment',
            'fx_rate_used' => 5.20,
            'bank_reference' => 'TXN-9988',
            'extra_allocation_note' => 'Market rate variation',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'paid');
});

