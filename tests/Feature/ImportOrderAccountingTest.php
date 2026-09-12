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
use App\Models\PaymentRequest;
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
        $this->stateService->arriveAtWarehouse($order->fresh(), $this->warehouse->id);
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
        ->and((float) $byCode('1500')->sole()['credit'])->toBe(5000.0)
        ->and($byCode('2300')->pluck('credit')->map(fn ($c) => (float) $c)->sort()->values()->all())->toBe([150.0, 300.0]);
});

test('an order settled below the booked rate posts an FX gain', function () {
    // 1000 USD booked at 5.5 (5500), settled at 5.0 (5000) → 500 FX gain.
    $order = ($this->orderReadyToComplete)(bookedRate: 5.5, realizedRate: 5.0);

    $this->stateService->completeOrder($order->fresh());

    $lines = collect(($this->journalFor)($order)[0]['lines']);
    $byCode = fn (string $code) => $lines->filter(fn ($l) => $l['account']['account_code'] === $code);

    expect((float) $byCode('1110')->sole()['debit'])->toBe(5500.0)
        ->and((float) $byCode('1500')->sole()['credit'])->toBe(5000.0)
        ->and((float) $byCode('4200')->sole()['credit'])->toBe(500.0)
        ->and($byCode('5300'))->toBeEmpty();
});

test('without a booked estimate the purchase posts at the realized rate with no FX line', function () {
    $order = ($this->orderReadyToComplete)(bookedRate: null, realizedRate: 5.0);

    $this->stateService->completeOrder($order->fresh());

    $lines = collect(($this->journalFor)($order)[0]['lines']);

    expect($lines)->toHaveCount(2)
        ->and((float) $lines->firstWhere(fn ($l) => $l['account']['account_code'] === '1110')['debit'])->toBe(5000.0)
        ->and((float) $lines->firstWhere(fn ($l) => $l['account']['account_code'] === '1500')['credit'])->toBe(5000.0);
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

    // The payment-time advance exists; the refused completion wrote nothing.
    expect(JournalEntry::where('source_document_type', 'ImportOrder')->count())->toBe(0)
        ->and($order->fresh()->status)->toBe(ImportOrderStatus::Received);
});

test('a domestic-currency order posts one for one with no FX involvement', function () {
    $order = ($this->orderReadyToComplete)(realizedRate: 1.0, currency: 'LYD');

    $this->stateService->completeOrder($order->fresh());

    $lines = collect(($this->journalFor)($order)[0]['lines']);

    expect($lines)->toHaveCount(2)
        ->and((float) $lines->firstWhere(fn ($l) => $l['account']['account_code'] === '1110')['debit'])->toBe(1000.0)
        ->and((float) $lines->firstWhere(fn ($l) => $l['account']['account_code'] === '1500')['credit'])->toBe(1000.0);
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

test('the payment requests endpoint returns only the addressed order\'s requests', function () {
    $orderA = ($this->orderReadyToComplete)();
    $orderB = ($this->orderReadyToComplete)();

    $listed = ($this->api)()->getJson("/api/v1/import-orders/{$orderA->id}/payment-requests")
        ->assertStatus(200)
        ->json('data');

    expect($listed)->toHaveCount(1)
        ->and($listed[0]['id'])->toBe($orderA->paymentRequests()->sole()->id)
        ->and($listed[0]['id'])->not->toBe($orderB->paymentRequests()->sole()->id);
});

test('a wrong-state transition over the API is a 422 with a code, not a 500', function () {
    $order = ($this->orderReadyToComplete)();

    // Received cannot go back to shipment.
    ($this->api)()->postJson("/api/v1/import-orders/{$order->id}/transition", [
        'action' => 'shipment',
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});

test('an input-guard refusal over the API is a 422 with a code, not a 500', function () {
    $order = ($this->orderReadyToComplete)();
    $order->landedCostLines()->create(['type' => 'customs', 'amount' => 100, 'is_confirmed' => false]);

    ($this->api)()->postJson("/api/v1/import-orders/{$order->id}/transition", [
        'action' => 'complete',
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_IMPORT_ORDER_OPERATION');
});

test('payments execute over both routes and refuse to execute twice', function () {
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 100,
        'quantity' => 10,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $requestId = $order->paymentRequests()->sole()->id;

    // The flat route the desktop treasury screen calls.
    ($this->api)()->postJson("/api/v1/payment-requests/{$requestId}/execute", [
        'fx_rate_used' => 5.0,
    ])->assertStatus(200);

    expect($order->fresh()->status->value)->toBe('paid');

    // Executing an already-paid request is a state refusal.
    ($this->api)()->postJson("/api/v1/import-orders/{$order->id}/payment-requests/{$requestId}/process", [
        'fx_rate_used' => 5.0,
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    // And the nested route rejects a request id that belongs to another order.
    $other = ($this->orderReadyToComplete)();
    ($this->api)()->postJson("/api/v1/import-orders/{$other->id}/payment-requests/{$requestId}/process", [
        'fx_rate_used' => 5.0,
    ])->assertStatus(404);
});

test('the completion transition is reachable over the API', function () {
    $order = ($this->orderReadyToComplete)(bookedRate: 5.0, realizedRate: 5.0);

    ($this->api)()->postJson("/api/v1/import-orders/{$order->id}/transition", [
        'action' => 'complete',
    ])->assertStatus(200);

    // One advance journal at payment, one completion journal.
    expect($order->fresh()->status)->toBe(ImportOrderStatus::Complete)
        ->and(JournalEntry::count())->toBe(2);
});

test('executing a payment posts the advance against cash and completion clears it', function () {
    $order = ($this->orderReadyToComplete)(bookedRate: 5.0, realizedRate: 5.0);

    $advance = JournalEntry::where('source_document_type', 'PaymentRequest')->sole();
    $lines = $advance->lines()->with('account')->get();

    // 1000 USD at 5.0: money left the bank, sitting on the supplier.
    expect((float) $lines->firstWhere(fn ($l) => $l->account->account_code === '1500')->debit)->toBe(5000.0)
        ->and((float) $lines->firstWhere(fn ($l) => $l->account->account_code === '1200')->credit)->toBe(5000.0);

    $this->stateService->completeOrder($order->fresh());

    // The completion credit clears the advance to exactly zero.
    $tb = ($this->api)()->getJson('/api/v1/reports/trial-balance')->assertStatus(200)->json();
    $advances = collect($tb['rows'])->firstWhere('account_code', '1500');

    expect((float) $advances['balance'])->toBe(0.0)
        ->and($tb['balanced'])->toBeTrue();
});

test('a bank hold settled above the computed rate books the spread as FX loss', function () {
    // 1000 USD, booked at 5.0. The bank actually consumed 5,150 LYD.
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 100,
        'quantity' => 10,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Bank, 1000, 6000.00, 'INV-1');
    $this->stateService->executePayment($order->paymentRequests()->first(), 5.0, 5150.00, 'BNK-1');
    $this->stateService->confirmShipment($order->fresh());
    $this->stateService->arriveAtPort($order->fresh());
    $this->stateService->transportToWarehouse($order->fresh());
    $this->stateService->arriveAtWarehouse($order->fresh(), $this->warehouse->id);
    $this->stateService->receiveGoods($order->fresh(), $this->warehouse->id, 10);

    // The advance carries what the bank actually took.
    $advance = JournalEntry::where('source_document_type', 'PaymentRequest')->sole();
    expect((float) $advance->lines()->with('account')->get()
        ->firstWhere(fn ($l) => $l->account->account_code === '1200')->credit)->toBe(5150.0);

    $this->stateService->completeOrder($order->fresh());

    $completion = collect(($this->journalFor)($order)[0]['lines']);

    // Inventory at booked 5,000; the 150 bank spread is FX loss; 1500 nets 0.
    expect((float) $completion->firstWhere(fn ($l) => $l['account']['account_code'] === '1110')['debit'])->toBe(5000.0)
        ->and((float) $completion->firstWhere(fn ($l) => $l['account']['account_code'] === '5300')['debit'])->toBe(150.0)
        ->and((float) $completion->firstWhere(fn ($l) => $l['account']['account_code'] === '1500')['credit'])->toBe(5150.0);

    $tb = ($this->api)()->getJson('/api/v1/reports/trial-balance')->assertStatus(200)->json();
    expect((float) collect($tb['rows'])->firstWhere('account_code', '1500')['balance'])->toBe(0.0)
        ->and($tb['balanced'])->toBeTrue();
});

test('executing a market payment with non-zero extra allocation requires a note', function () {
    // Booked 5.0, settled 5.2 → 200 LYD extra allocation on a 1000 USD order.
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 100,
        'quantity' => 10,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Market, 1000);
    $requestId = $order->fresh()->paymentRequests()->sole()->id;

    ($this->api)()->postJson("/api/v1/payment-requests/{$requestId}/execute", [
        'fx_rate_used' => 5.2,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['extra_allocation_note']);

    ($this->api)()->postJson("/api/v1/payment-requests/{$requestId}/execute", [
        'fx_rate_used' => 5.2,
        'extra_allocation_note' => 'تم الشراء من الصرّاف بسبب تأخر الاعتماد البنكي.',
    ])->assertStatus(200);

    $pr = PaymentRequest::find($requestId);
    expect($pr->extra_allocation_note)->toBe('تم الشراء من الصرّاف بسبب تأخر الاعتماد البنكي.')
        ->and((float) $pr->fx_rate_used)->toBe(5.2);
});

test('executing a payment with zero extra allocation accepts an empty note', function () {
    // Booked 5.0, settled 5.0 → 0 LYD extra allocation; note is optional.
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 100,
        'quantity' => 10,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Market, 1000);
    $requestId = $order->fresh()->paymentRequests()->sole()->id;

    ($this->api)()->postJson("/api/v1/payment-requests/{$requestId}/execute", [
        'fx_rate_used' => 5.0,
    ])->assertStatus(200);

    $pr = PaymentRequest::find($requestId);
    expect($pr->extra_allocation_note)->toBeNull()
        ->and((float) $pr->fx_rate_used)->toBe(5.0);
});

test('creating an fx_spread landed cost line with a non-zero amount requires a note', function () {
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 100,
        'quantity' => 10,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);

    ($this->api)()->postJson("/api/v1/import-orders/{$order->id}/landed-cost-lines", [
        'type' => 'fx_spread',
        'amount' => 150,
        'currency' => 'LYD',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['note']);

    ($this->api)()->postJson("/api/v1/import-orders/{$order->id}/landed-cost-lines", [
        'type' => 'fx_spread',
        'amount' => 150,
        'currency' => 'LYD',
        'note' => 'فرق سعر بسبب الصرّاف الموازي.',
    ])->assertStatus(201)
        ->assertJsonPath('data.note', 'فرق سعر بسبب الصرّاف الموازي.');
});

test('creating an fx_spread landed cost line with zero amount accepts an empty note', function () {
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 100,
        'quantity' => 10,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);

    ($this->api)()->postJson("/api/v1/import-orders/{$order->id}/landed-cost-lines", [
        'type' => 'fx_spread',
        'amount' => 0,
        'currency' => 'LYD',
    ])->assertStatus(201);

    expect($order->landedCostLines()->count())->toBe(1);
});

test('non-fx_spread landed cost lines accept an empty note regardless of amount', function () {
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 100,
        'quantity' => 10,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);

    ($this->api)()->postJson("/api/v1/import-orders/{$order->id}/landed-cost-lines", [
        'type' => 'customs',
        'amount' => 500,
        'currency' => 'LYD',
    ])->assertStatus(201);

    expect($order->landedCostLines()->sole()->note)->toBeNull();
});

test('payment-requests index filters by route=market', function () {
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 100,
        'quantity' => 10,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Market, 1000);

    $bankOrder = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 100,
        'quantity' => 10,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($bankOrder);
    $this->stateService->selectPaymentRoute($bankOrder->fresh(), PaymentRoute::Bank, 1000, 5500.00, 'BNK-1');

    $market = ($this->api)()->getJson('/api/v1/payment-requests?route=market')->assertStatus(200)->json();
    $bank = ($this->api)()->getJson('/api/v1/payment-requests?route=bank')->assertStatus(200)->json();

    expect(collect($market['data'])->pluck('route')->unique()->values()->all())->toBe(['market'])
        ->and(collect($bank['data'])->pluck('route')->unique()->values()->all())->toBe(['bank']);
});
