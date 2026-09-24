<?php

declare(strict_types=1);

use App\Enums\ImportOrderStatus;
use App\Enums\PaymentRoute;
use App\Models\Company;
use App\Models\ImportOrder;
use App\Models\OperatingUnit;
use App\Models\Supplier;
use App\Models\UnitBlueprint;
use App\Services\ImportOrderStateService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Audit Co', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Blueprint',
        'workflow_set' => '[]',
        'default_role_template' => '[]',
        'default_inventory_config' => '[]',
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Audit Unit',
        'code' => 'AUD-01',
        'unit_type' => 'warehouse',
    ]);
    $this->supplier = Supplier::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Audit Supplier',
        'default_currency' => 'USD',
    ]);

    $this->stateService = app(ImportOrderStateService::class);
});

test('fx:audit-silent-spreads lists paid requests where variance exceeded tolerance without a note', function () {
    $makeOrder = function (float $booked, float $settled, bool $withNote): string {
        $order = ImportOrder::create([
            'operating_unit_id' => $this->unit->id,
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'negotiated_price' => 1000,
            'quantity' => 1,
            'booked_fx_rate' => $booked,
            'status' => ImportOrderStatus::Draft,
        ]);
        $this->stateService->transitionToPendingPayment($order);
        $this->stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Bank, 1000, 6000.00);

        $pr = $order->paymentRequests()->sole();
        $this->stateService->executePayment(
            $pr,
            null,
            exactAmountUsedLyd: $settled,
            extraAllocationNote: $withNote ? 'spread justified' : null,
        );

        return $pr->id;
    };

    // Silent spread: variance 150 LYD, no note. Should appear in audit.
    $silentId = $makeOrder(5.0, 5150.0, withNote: false);

    // Justified spread: variance 150 LYD, note present. Should NOT appear.
    $justifiedId = $makeOrder(5.0, 5150.0, withNote: true);

    // Within tolerance: variance 0.005 LYD, no note. Should NOT appear.
    $tinyId = $makeOrder(5.0, 5000.005, withNote: false);

    $this->artisan('fx:audit-silent-spreads')
        ->expectsOutputToContain('Found 1 silent FX spread')
        ->assertExitCode(0);

    // JSON mode returns the same set.
    Artisan::call('fx:audit-silent-spreads', ['--json' => true]);
    $output = Artisan::output();
    $payload = json_decode(trim($output), true);

    expect($payload)->toBeArray();
    expect(collect($payload)->pluck('payment_request_id'))->toContain($silentId)
        ->and(collect($payload)->pluck('payment_request_id'))->not->toContain($justifiedId)
        ->and(collect($payload)->pluck('payment_request_id'))->not->toContain($tinyId);
});

test('fx:audit-silent-spreads emits nothing when all variances are within tolerance', function () {
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
    $this->stateService->executePayment($pr, null, exactAmountUsedLyd: 5000.005);

    $this->artisan('fx:audit-silent-spreads')
        ->expectsOutputToContain('No silent FX spreads found')
        ->assertExitCode(0);
});
