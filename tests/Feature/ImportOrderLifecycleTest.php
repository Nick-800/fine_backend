<?php

declare(strict_types=1);

use App\Enums\ImportOrderStatus;
use App\Enums\PaymentRoute;
use App\Models\Company;
use App\Models\ImportOrder;
use App\Models\OperatingUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ImportOrderStateService;
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

    $this->operatingUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
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
        'operating_unit_id' => $this->operatingUnit->id,
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
    $this->stateService->confirmShipment($order);
    expect($order->fresh()->status)->toBe(ImportOrderStatus::InTransit);

    // 6. Arrive at Port
    $this->stateService->arriveAtPort($order);
    expect($order->fresh()->status)->toBe(ImportOrderStatus::AtPort);

    // 7. Transport to Warehouse
    $this->stateService->transportToWarehouse($order);
    expect($order->fresh()->status)->toBe(ImportOrderStatus::AwaitingReceipt);

    // 8. Receive Goods
    $receipt = $this->stateService->receiveGoods($order, $this->warehouse->id, 100, 'All 100 units in excellent condition');
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
    $this->stateService->completeOrder($order);
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

    $this->stateService->transitionToPendingPayment($order);
    $this->stateService->selectPaymentRoute($order, PaymentRoute::Market, 500);
    $paymentRequest = $order->paymentRequests()->first();
    $this->stateService->executePayment($paymentRequest, 5.0);
    $this->stateService->confirmShipment($order);
    $this->stateService->arriveAtPort($order);
    $this->stateService->transportToWarehouse($order);
    $this->stateService->receiveGoods($order, $this->warehouse->id, 10);

    // Add unconfirmed landed cost line
    $order->landedCostLines()->create([
        'type' => 'customs',
        'amount' => 200,
        'is_confirmed' => false,
    ]);

    expect(fn () => $this->stateService->completeOrder($order))
        ->toThrow(InvalidArgumentException::class);
});
