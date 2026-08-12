<?php

declare(strict_types=1);

use App\Enums\ImportOrderStatus;
use App\Enums\PaymentRoute;
use App\Models\Company;
use App\Models\FxRate;
use App\Models\GoodsReceipt;
use App\Models\ImportOrder;
use App\Models\JournalEntry;
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
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'Procurement Hub', 'code' => 'PROC-01', 'unit_type' => 'warehouse',
    ]);
    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'Port Depot', 'code' => 'PD-1',
    ]);
    $this->supplier = Supplier::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'Foreign Chem Co', 'default_currency' => 'USD',
    ]);

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Procurement Manager', 'slug' => 'procurement_manager']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unit->id]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $this->stateService = app(ImportOrderStateService::class);

    // Drives an order from Draft to Received: market-route payment executed at
    // the given realized rate, full quantity received.
    $this->orderReadyToComplete = function (
        ?float $bookedRate = null,
        float $realizedRate = 5.0,
        float $price = 100.0,
        float $qty = 10.0,
        string $currency = 'USD',
    ): ImportOrder {
        $order = ImportOrder::create([
            'operating_unit_id' => $this->unit->id,
            'supplier_id' => $this->supplier->id,
            'currency' => $currency,
            'negotiated_price' => $price,
            'quantity' => $qty,
            'booked_fx_rate' => $bookedRate,
            'status' => ImportOrderStatus::Draft,
        ]);

        $this->stateService->transitionToPendingPayment($order);
        $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Market, $price * $qty);
        $this->stateService->executePayment($order->paymentRequests()->first(), $realizedRate);
        $this->stateService->confirmShipment($order->fresh());
        $this->stateService->arriveAtPort($order->fresh());
        $this->stateService->transportToWarehouse($order->fresh());
        $this->stateService->receiveGoods($order->fresh(), $this->warehouse->id, $qty);

        return $order->fresh();
    };

    $this->journalFor = fn (ImportOrder $order) => ($this->api)()
        ->getJson("/api/v1/journal-entries/for-document/ImportOrder/{$order->id}")
        ->assertStatus(200)
        ->json();
});

test('completing an import order posts inventory, payable, landed cost and FX loss', function () {
    // 1000 USD booked at 4.8 (4800), settled at 5.0 (5000) → 200 FX loss.
    $order = ($this->orderReadyToComplete)(bookedRate: 4.8, realizedRate: 5.0);

    $order->landedCostLines()->create(['type' => 'freight', 'amount' => 300, 'currency' => 'LYD', 'is_confirmed' => true]);
    $order->landedCostLines()->create(['type' => 'customs', 'amount' => 150, 'currency' => 'LYD', 'is_confirmed' => true]);

    $this->stateService->completeOrder($order->fresh());

    $entries = ($this->journalFor)($order);
    expect($entries)->toHaveCount(1);

    $lines = collect($entries[0]['lines']);
    $byCode = fn (string $code) => $lines->filter(fn ($l) => $l['account']['account_code'] === $code);

    // Inventory carries booked supplier cost + all landed costs: 4800 + 450.
    expect((float) $byCode('1110')->sole()['debit'])->toBe(5250.0)
        ->and((float) $byCode('5300')->sole()['debit'])->toBe(200.0)
        ->and((float) $byCode('2100')->sole()['credit'])->toBe(5000.0)
        ->and($byCode('2300')->pluck('credit')->map(fn ($c) => (float) $c)->sort()->values()->all())->toBe([150.0, 300.0]);
});

test('an order settled below the booked rate posts an FX gain', function () {
    // 1000 USD booked at 5.5 (5500), settled at 5.0 (5000) → 500 FX gain.
    $order = ($this->orderReadyToComplete)(bookedRate: 5.5, realizedRate: 5.0);

    $this->stateService->completeOrder($order->fresh());

    $lines = collect(($this->journalFor)($order)[0]['lines']);
    $byCode = fn (string $code) => $lines->filter(fn ($l) => $l['account']['account_code'] === $code);

    expect((float) $byCode('1110')->sole()['debit'])->toBe(5500.0)
        ->and((float) $byCode('2100')->sole()['credit'])->toBe(5000.0)
        ->and((float) $byCode('4200')->sole()['credit'])->toBe(500.0)
        ->and($byCode('5300'))->toBeEmpty();
});

test('without a booked estimate the purchase posts at the realized rate with no FX line', function () {
    $order = ($this->orderReadyToComplete)(bookedRate: null, realizedRate: 5.0);

    $this->stateService->completeOrder($order->fresh());

    $lines = collect(($this->journalFor)($order)[0]['lines']);

    expect($lines)->toHaveCount(2)
        ->and((float) $lines->firstWhere(fn ($l) => $l['account']['account_code'] === '1110')['debit'])->toBe(5000.0)
        ->and((float) $lines->firstWhere(fn ($l) => $l['account']['account_code'] === '2100')['credit'])->toBe(5000.0);
});

test('a supplier_price landed cost line does not double the inventory value', function () {
    $order = ($this->orderReadyToComplete)(bookedRate: 5.0, realizedRate: 5.0);

    // A mirror of the order's own price — must be ignored, the order is the
    // supplier-price component.
    $order->landedCostLines()->create(['type' => 'supplier_price', 'amount' => 5000, 'currency' => 'LYD', 'is_confirmed' => true]);

    $this->stateService->completeOrder($order->fresh());

    $lines = collect(($this->journalFor)($order)[0]['lines']);

    expect((float) $lines->firstWhere(fn ($l) => $l['account']['account_code'] === '1110')['debit'])->toBe(5000.0)
        ->and($lines->filter(fn ($l) => $l['account']['account_code'] === '2300'))->toBeEmpty();
});

test('a landed cost line in the order currency converts at the realized rate', function () {
    $order = ($this->orderReadyToComplete)(bookedRate: 5.0, realizedRate: 5.0);

    $order->landedCostLines()->create(['type' => 'freight', 'amount' => 100, 'currency' => 'USD', 'is_confirmed' => true]);

    $this->stateService->completeOrder($order->fresh());

    $lines = collect(($this->journalFor)($order)[0]['lines']);

    expect((float) $lines->firstWhere(fn ($l) => $l['account']['account_code'] === '2300')['credit'])->toBe(500.0)
        ->and((float) $lines->firstWhere(fn ($l) => $l['account']['account_code'] === '1110')['debit'])->toBe(5500.0);
});

test('a landed cost line in a third currency is refused rather than guessed', function () {
    $order = ($this->orderReadyToComplete)(bookedRate: 5.0, realizedRate: 5.0);

    $order->landedCostLines()->create(['type' => 'freight', 'amount' => 100, 'currency' => 'EUR', 'is_confirmed' => true]);

    expect(fn () => $this->stateService->completeOrder($order->fresh()))
        ->toThrow(InvalidArgumentException::class);

    expect(JournalEntry::count())->toBe(0)
        ->and($order->fresh()->status)->toBe(ImportOrderStatus::Received);
});

test('a domestic-currency order posts one for one with no FX involvement', function () {
    $order = ($this->orderReadyToComplete)(realizedRate: 1.0, currency: 'LYD');

    $this->stateService->completeOrder($order->fresh());

    $lines = collect(($this->journalFor)($order)[0]['lines']);

    expect($lines)->toHaveCount(2)
        ->and((float) $lines->firstWhere(fn ($l) => $l['account']['account_code'] === '1110')['debit'])->toBe(1000.0)
        ->and((float) $lines->firstWhere(fn ($l) => $l['account']['account_code'] === '2100')['credit'])->toBe(1000.0);
});

test('completion is refused when no executed payment carries an FX rate', function () {
    // Bypasses the state machine to simulate a corrupted order: Received with
    // no payment trail. Valuing it would be a guess, so posting must refuse.
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 100,
        'quantity' => 10,
        'status' => ImportOrderStatus::Received,
    ]);
    GoodsReceipt::create([
        'import_order_id' => $order->id,
        'warehouse_id' => $this->warehouse->id,
        'received_qty' => 10,
    ]);

    expect(fn () => $this->stateService->completeOrder($order->fresh()))
        ->toThrow(InvalidArgumentException::class);

    expect(JournalEntry::count())->toBe(0)
        ->and($order->fresh()->status)->toBe(ImportOrderStatus::Received);
});

test('the trial balance stays balanced after an import order completes', function () {
    $order = ($this->orderReadyToComplete)(bookedRate: 4.8, realizedRate: 5.2);
    $order->landedCostLines()->create(['type' => 'local_transport', 'amount' => 75.5, 'currency' => 'LYD', 'is_confirmed' => true]);

    $this->stateService->completeOrder($order->fresh());

    $tb = ($this->api)()->getJson('/api/v1/reports/trial-balance')->assertStatus(200)->json();

    expect($tb['balanced'])->toBeTrue()
        ->and($tb['total_debit'])->toBe($tb['total_credit']);
});

test('creating an order over the API snapshots the booked FX rate', function () {
    FxRate::create([
        'from_currency' => 'USD', 'to_currency' => 'LYD',
        'rate' => 5.123456, 'captured_at' => now(),
    ]);

    $response = ($this->api)()->postJson('/api/v1/import-orders', [
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 100,
        'quantity' => 10,
    ])->assertStatus(201);

    expect($response->json('data.booked_fx_rate'))->toBe(5.123456);
});

test('an explicitly provided booked FX rate wins over the snapshot lookup', function () {
    FxRate::create([
        'from_currency' => 'USD', 'to_currency' => 'LYD',
        'rate' => 5.123456, 'captured_at' => now(),
    ]);

    $response = ($this->api)()->postJson('/api/v1/import-orders', [
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 100,
        'quantity' => 10,
        'booked_fx_rate' => 4.9,
    ])->assertStatus(201);

    expect($response->json('data.booked_fx_rate'))->toBe(4.9);
});

test('the completion transition is reachable over the API', function () {
    $order = ($this->orderReadyToComplete)(bookedRate: 5.0, realizedRate: 5.0);

    ($this->api)()->postJson("/api/v1/import-orders/{$order->id}/transition", [
        'action' => 'complete',
    ])->assertStatus(200);

    expect($order->fresh()->status)->toBe(ImportOrderStatus::Complete)
        ->and(JournalEntry::count())->toBe(1);
});
