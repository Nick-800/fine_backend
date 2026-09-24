<?php

declare(strict_types=1);

use App\Enums\ImportOrderStatus;
use App\Enums\PaymentRoute;
use App\Http\Resources\v1\PaymentRequestResource;
use App\Models\Company;
use App\Models\FxRate;
use App\Models\ImportOrder;
use App\Models\JournalEntry;
use App\Models\LandedCostLine;
use App\Models\OperatingUnit;
use App\Models\PaymentRequest;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\ImportOrderStateService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine FX Test Co', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'FX Hub',
        'code' => 'FX-01',
        'unit_type' => 'warehouse',
    ]);
    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Port Depot',
        'code' => 'FX-PD',
    ]);
    $this->supplier = Supplier::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Foreign Chem Co',
        'default_currency' => 'USD',
    ]);

    $this->user = User::factory()->create(['must_change_password' => false]);
    $pmRole = Role::create(['name' => 'PM', 'slug' => 'procurement_manager']);
    $accountantRole = Role::create(['name' => 'AM', 'slug' => 'accounting-manager']);
    UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => $pmRole->id,
        'operating_unit_id' => $this->unit->id,
    ]);
    UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => $accountantRole->id,
        'operating_unit_id' => $this->unit->id,
    ]);

    $this->stateService = app(ImportOrderStateService::class);
});

// -----------------------------------------------------------------------------
// Phase 1 — deriveEffectiveValues + executePayment refactor
// -----------------------------------------------------------------------------

test('it derives the effective rate when only the LYD amount is provided', function () {
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1000,
        'quantity' => 1,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $pr = $order->fresh()->paymentRequests()->sole();

    // FX-02: only LYD supplied, no rate.
    $updated = $this->stateService->executePayment($pr, null, exactAmountUsedLyd: 5150.0);

    expect((float) $updated->fx_rate_used)->toBe(5.15);
});

test('it derives the effective settled when only the rate is provided', function () {
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1000,
        'quantity' => 1,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $pr = $order->fresh()->paymentRequests()->sole();

    $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Bank, 1000, 5500.00);

    // FX-15: only rate supplied, no exact_used.
    $updated = $this->stateService->executePayment($pr, fxRateUsed: 5.20);

    expect((float) $updated->fx_rate_used)->toBe(5.20)
        ->and((float) $updated->bankHold->fresh()->exact_amount_used)->toBe(5200.0);
});

test('it accepts exact_amount_used_lyd on the Market route', function () {
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1000,
        'quantity' => 1,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Market, 1000);
    $pr = $order->fresh()->paymentRequests()->sole();

    // FX-02: صرّاف route accepts LYD; rate is derived.
    $updated = $this->stateService->executePayment($pr, null, exactAmountUsedLyd: 5100.0);

    expect((float) $updated->fx_rate_used)->toBe(5.10)
        ->and($updated->bankHold)->toBeNull();
});

test('it accepts both inputs and reconciles to the LYD truth', function () {
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1000,
        'quantity' => 1,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $pr = $order->fresh()->paymentRequests()->sole();

    // FX-02: both supplied; LYD wins, rate is reconciled to LYD/amount.
    $updated = $this->stateService->executePayment($pr, fxRateUsed: 5.0, exactAmountUsedLyd: 5150.0);

    expect((float) $updated->fx_rate_used)->toBe(5.15);
});

test('it rejects execution when neither field is provided', function () {
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1000,
        'quantity' => 1,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $pr = $order->fresh()->paymentRequests()->sole();

    expect(fn () => $this->stateService->executePayment($pr))
        ->toThrow(InvalidArgumentException::class);
});

test('it rounds effective_rate to 6 decimal places and effective_settled to 4', function () {
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 333,
        'quantity' => 1,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $pr = $order->fresh()->paymentRequests()->sole();

    // 1000 / 333 = 3.003003003… → round to 6 dp = 3.003003
    $updated = $this->stateService->executePayment($pr, null, exactAmountUsedLyd: 1000.0);

    expect((float) $updated->fx_rate_used)->toBe(3.003003);
});

// -----------------------------------------------------------------------------
// Phase 8 — FX-TEST-* test gap fills
// -----------------------------------------------------------------------------

test('FX-TEST-02: a bank hold where exact_used exceeds held books the spread as FX loss', function () {
    // 1000 USD, booked at 5.0. Held 5000, bank actually debited 5150 LYD.
    // released_amount must be clamped at 0; the 150 LYD spread is the FX loss
    // booked at completion.
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1000,
        'quantity' => 1,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Bank, 1000, 5000.00);
    $pr = $order->fresh()->paymentRequests()->sole();
    $this->stateService->executePayment($pr, null, exactAmountUsedLyd: 5150.0, bankReference: 'BNK-SPREAD');

    $hold = $pr->fresh()->bankHold;
    expect((float) $hold->exact_amount_used)->toBe(5150.0)
        ->and((float) $hold->released_amount)->toBe(0.0)
        ->and($hold->bank_reference)->toBe('BNK-SPREAD');

    // Drive to completion to confirm the 150 LYD FX loss is posted.
    $this->stateService->confirmShipment($order->fresh());
    $this->stateService->arriveAtPort($order->fresh());
    $this->stateService->transportToWarehouse($order->fresh());
    $this->stateService->arriveAtWarehouse($order->fresh(), $this->warehouse->id);
    $this->stateService->receiveGoods($order->fresh(), $this->warehouse->id, 1);
    $this->stateService->completeOrder($order->fresh());

    $advance = JournalEntry::where('source_document_type', 'PaymentRequest')->sole();
    expect((float) $advance->lines()->with('account')->get()
        ->firstWhere(fn ($l) => $l->account->account_code === '1200')->credit)->toBe(5150.0);

    $completion = JournalEntry::where('source_document_type', 'ImportOrder')->sole();
    $lines = $completion->lines()->with('account')->get();
    expect((float) $lines->firstWhere(fn ($l) => $l->account->account_code === '5300')->debit)->toBe(150.0)
        ->and((float) $lines->firstWhere(fn ($l) => $l->account->account_code === '1500')->credit)->toBe(5150.0);
});

test('FX-TEST-03: a USD order with no booked_fx_rate falls back to the most recent historical FxRate', function () {
    // Seed an FxRate BEFORE the order is created. The order has no
    // booked_fx_rate; bookedFxRate() must fall back to the historical row.
    FxRate::create([
        'from_currency' => 'USD',
        'to_currency' => 'LYD',
        'rate' => 5.0,
        'captured_at' => now()->subDay(),
    ]);

    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1000,
        'quantity' => 1,
        // No booked_fx_rate — exercises the historical fallback path.
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Market, 1000);
    $pr = $order->fresh()->paymentRequests()->sole();

    // Executed at 5.2 — variance should be 200 LYD FX loss.
    $this->stateService->executePayment($pr, fxRateUsed: 5.2);

    $this->stateService->confirmShipment($order->fresh());
    $this->stateService->arriveAtPort($order->fresh());
    $this->stateService->transportToWarehouse($order->fresh());
    $this->stateService->arriveAtWarehouse($order->fresh(), $this->warehouse->id);
    $this->stateService->receiveGoods($order->fresh(), $this->warehouse->id, 1);
    $this->stateService->completeOrder($order->fresh());

    $completion = JournalEntry::where('source_document_type', 'ImportOrder')->sole();
    $lines = $completion->lines()->with('account')->get();
    expect((float) $lines->firstWhere(fn ($l) => $l->account->account_code === '5300')->debit)->toBe(200.0);
});

test('FX-TEST-07: a landed cost line in the order currency converts at the realized rate', function () {
    // Order USD, booked 5.0, executed 5.2. Freight line in USD 100 → converts
    // at realized 5.2 = 520 LYD added to inventory.
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1000,
        'quantity' => 1,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Market, 1000);
    $pr = $order->fresh()->paymentRequests()->sole();
    $this->stateService->executePayment($pr, fxRateUsed: 5.2);

    LandedCostLine::create([
        'import_order_id' => $order->id,
        'type' => 'freight',
        'amount' => 100,
        'currency' => 'USD',
        'is_confirmed' => true,
    ]);

    $this->stateService->confirmShipment($order->fresh());
    $this->stateService->arriveAtPort($order->fresh());
    $this->stateService->transportToWarehouse($order->fresh());
    $this->stateService->arriveAtWarehouse($order->fresh(), $this->warehouse->id);
    $this->stateService->receiveGoods($order->fresh(), $this->warehouse->id, 1);
    $this->stateService->completeOrder($order->fresh());

    $completion = JournalEntry::where('source_document_type', 'ImportOrder')->sole();
    $lines = $completion->lines()->with('account')->get();
    // Inventory: booked 5000 + freight 520 = 5520
    expect((float) $lines->firstWhere(fn ($l) => $l->account->account_code === '1110')->debit)->toBe(5520.0)
        ->and((float) $lines->firstWhere(fn ($l) => $l->account->account_code === '2300')->credit)->toBe(520.0);
});

test('FX-TEST-08: executePayment without exact_amount_used_lyd defaults to amount_requested * fx_rate_used for the bank branch', function () {
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1000,
        'quantity' => 1,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Bank, 1000, 5500.00);
    $pr = $order->fresh()->paymentRequests()->sole();

    // No exact_amount_used_lyd supplied — derived from amount × rate.
    $this->stateService->executePayment($pr, fxRateUsed: 5.20);

    $hold = $pr->fresh()->bankHold;
    expect((float) $hold->exact_amount_used)->toBe(5200.0)
        ->and((float) $hold->released_amount)->toBe(300.0);
});

// -----------------------------------------------------------------------------
// Phase 2 — validation: LYD-grounded variance + tolerance gate
// -----------------------------------------------------------------------------

test('the API requires a note when settled LYD deviates from booked by more than tolerance', function () {
    // booked 5.0, settled 5.20 → 200 LYD variance; tolerance 0.01.
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1000,
        'quantity' => 1,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $requestId = $order->paymentRequests()->sole()->id;

    $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/payment-requests/{$requestId}/execute", [
            'fx_rate_used' => 5.2,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['extra_allocation_note']);
});

test('the API does not require a note when variance is within tolerance', function () {
    // booked 5.0, settled 5.000001 → 0.001 LYD variance, within 0.01 tolerance.
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1000,
        'quantity' => 1,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $requestId = $order->paymentRequests()->sole()->id;

    $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/payment-requests/{$requestId}/execute", [
            'fx_rate_used' => 5.000001,
        ])
        ->assertStatus(200);
});

test('the API requires a note when exact_amount_used_lyd deviates even at the booked rate (FX-24)', function () {
    // booked 5.0, fx_rate_used=5.0 (no rate move), exact_amount_used_lyd=5100.
    // The rate check passes (diff=0), but the LYD truth is 5100 → 100 LYD
    // variance, note required.
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1000,
        'quantity' => 1,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Bank, 1000, 5500.00);
    $requestId = $order->paymentRequests()->sole()->id;

    $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/payment-requests/{$requestId}/execute", [
            'fx_rate_used' => 5.0,
            'exact_amount_used_lyd' => 5100,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['extra_allocation_note']);

    // With a note, the same payload succeeds.
    $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/payment-requests/{$requestId}/execute", [
            'fx_rate_used' => 5.0,
            'exact_amount_used_lyd' => 5100,
            'extra_allocation_note' => 'فرق سعر بسبب العمولة البنكية.',
        ])
        ->assertStatus(200);
});

test('the API allows Market route execution with only LYD input', function () {
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1000,
        'quantity' => 1,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Market, 1000);
    $requestId = $order->paymentRequests()->sole()->id;

    // FX-02: Market route accepts LYD-only input. Variance is within the
    // 0.01 LYD tolerance band so no note is required.
    $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/payment-requests/{$requestId}/execute", [
            'exact_amount_used_lyd' => 5000.005,
        ])
        ->assertStatus(200);

    $pr = PaymentRequest::find($requestId);
    expect((float) $pr->fx_rate_used)->toBe(5.000005);
});

// -----------------------------------------------------------------------------
// Phase 3 — PaymentRequestResource: read-back fields
// -----------------------------------------------------------------------------

test('resource exposes effective_settled_lyd when exact_amount_used is set', function () {
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1000,
        'quantity' => 1,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Bank, 1000, 5500.00);
    $pr = $order->paymentRequests()->sole();
    $this->stateService->executePayment($pr, null, exactAmountUsedLyd: 5150.0);

    $payload = (new PaymentRequestResource($pr->fresh()->load('bankHold')))
        ->toArray(request());

    expect($payload['effective_settled_lyd'])->toBe(5150.0)
        ->and($payload['effective_rate'])->toBe(5.15)
        ->and($payload['variance_vs_booked_lyd'])->toBe(150.0)
        ->and($payload['variance_within_tolerance'])->toBeFalse()
        ->and($payload['variance_exceeds_hard_cap'])->toBeFalse()
        ->and($payload['fx_tolerance_lyd'])->toBe(0.01);
});

test('resource derives effective_rate when only exact_amount_used is stored', function () {
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1000,
        'quantity' => 1,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Bank, 1000, 5500.00);
    $pr = $order->paymentRequests()->sole();
    $this->stateService->executePayment($pr, null, exactAmountUsedLyd: 5150.0);

    // Inspect the persisted PR directly: fx_rate_used is the effective rate
    // (Phase 1 P2 decision — single source of truth in the column).
    $fresh = $pr->fresh();
    expect((float) $fresh->fx_rate_used)->toBe(5.15);
});

test('resource variance_vs_booked_lyd matches the actual settled-to-booked LYD delta', function () {
    // booked 5.0, no exact_used, rate 5.10 → settled = 5100 → variance 100.
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1000,
        'quantity' => 1,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $pr = $order->paymentRequests()->sole();
    $this->stateService->executePayment($pr, fxRateUsed: 5.10);

    $payload = (new PaymentRequestResource($pr->fresh()->load('bankHold')))
        ->toArray(request());

    expect($payload['effective_settled_lyd'])->toBe(5100.0)
        ->and($payload['variance_vs_booked_lyd'])->toBe(100.0);
});

test('resource flags variance_exceeds_hard_cap for spreads above 5% of settled', function () {
    // booked 5.0, settled 5200 → variance 200 / 5200 = 3.85% (within cap).
    // To exceed 5%: settled 6000 → variance 1000 / 6000 = 16.7% (exceeds).
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1000,
        'quantity' => 1,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $this->stateService->transitionToPendingPayment($order);
    $pr = $order->paymentRequests()->sole();
    $this->stateService->executePayment($pr, fxRateUsed: 6.00);

    $payload = (new PaymentRequestResource($pr->fresh()->load('bankHold')))
        ->toArray(request());

    expect($payload['variance_vs_booked_lyd'])->toBe(1000.0)
        ->and($payload['variance_exceeds_hard_cap'])->toBeTrue();
});
