<?php

declare(strict_types=1);

use App\Enums\AllocationMethod;
use App\Enums\AllocationPaymentStatus;
use App\Enums\ImportOrderStatus;
use App\Enums\PaymentRoute;
use App\Models\Company;
use App\Models\ImportOrder;
use App\Models\OperatingUnit;
use App\Models\OverheadAllocation;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\ImportOrderStateService;
use App\Services\OverheadService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Fine Foam Mfg', 'default_currency' => 'LYD',
    ]);
    $this->seed(ChartOfAccountsSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Foam', 'workflow_set' => ['production_batch' => []],
        'default_role_template' => [], 'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);

    $this->manager = User::factory()->create(['must_change_password' => false]);
    $managerRole = Role::create(['name' => 'Manager', 'slug' => 'manager']);
    UserRole::create([
        'user_id' => $this->manager->id, 'role_id' => $managerRole->id,
        'operating_unit_id' => $this->unit->id,
    ]);

    $this->outsider = User::factory()->create(['must_change_password' => false]);
    $outsiderRole = Role::create(['name' => 'Outsider', 'slug' => 'outsider']);
    UserRole::create([
        'user_id' => $this->outsider->id, 'role_id' => $outsiderRole->id,
        'operating_unit_id' => $this->unit->id,
    ]);

    $this->unit->update(['manager_user_id' => $this->manager->id]);

    $this->asManager = fn () => $this->actingAs($this->manager)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);
    $this->asOutsider = fn () => $this->actingAs($this->outsider)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $this->makeReceivedOrder = function (): ImportOrder {
        $supplier = Supplier::create([
            'operating_unit_id' => $this->unit->id,
            'name' => 'Test Supplier',
            'default_currency' => 'USD',
        ]);

        $warehouse = Warehouse::create([
            'operating_unit_id' => $this->unit->id,
            'name' => 'Main',
            'is_internal_unit' => true,
        ]);

        $order = ImportOrder::create([
            'operating_unit_id' => $this->unit->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'negotiated_price' => 50,
            'quantity' => 10,
            'status' => ImportOrderStatus::Draft,
        ]);

        $state = app(ImportOrderStateService::class);
        $state->transitionToPendingPayment($order->fresh());
        $state->selectPaymentRoute($order->fresh(), PaymentRoute::Market, 500);
        $paymentRequest = $order->paymentRequests()->first();
        $state->executePayment($paymentRequest, 5.0);
        $state->confirmShipment($order->fresh());
        $state->arriveAtPort($order->fresh());
        $state->transportToWarehouse($order->fresh());
        $state->arriveAtWarehouse($order->fresh(), $warehouse->id);
        $state->receiveGoods($order->fresh(), $warehouse->id, 10);

        return $order->fresh();
    };
});

test('a fresh overhead allocation starts pending and can be approved only by the unit manager', function () {
    $service = app(OverheadService::class);

    $expense = $service->recordExpense([
        'company_id' => $this->company->id,
        'operating_unit_id' => null,
        'category' => 'electricity',
        'amount' => 1000.0,
        'expense_date' => now()->toDateString(),
        'payment_source' => 'payable',
    ]);
    $expense = $service->allocate($expense, AllocationMethod::EvenSplit);

    $allocation = OverheadAllocation::where('operating_unit_id', $this->unit->id)->firstOrFail();
    expect($allocation->status)->toBe(AllocationPaymentStatus::Pending);

    ($this->asOutsider)()->postJson("/api/v1/overhead-allocations/{$allocation->id}/approve")
        ->assertStatus(403)
        ->assertJsonPath('code', 'ALLOCATION_NOT_RESPONSIBLE');

    ($this->asManager)()->postJson("/api/v1/overhead-allocations/{$allocation->id}/approve", [
        'note' => 'Looks right',
    ])->assertStatus(200)->assertJsonPath('status', 'approved');

    expect($allocation->fresh()->status)->toBe(AllocationPaymentStatus::Approved);
    expect($allocation->fresh()->approved_by_user_id)->toBe($this->manager->id);
    expect($allocation->fresh()->approved_at)->not->toBeNull();
    expect($allocation->fresh()->confirmation_note)->toBe('Looks right');
});

test('a non-manager cannot skip straight from pending to paid on an overhead allocation', function () {
    $service = app(OverheadService::class);
    $expense = $service->recordExpense([
        'company_id' => $this->company->id,
        'operating_unit_id' => null,
        'category' => 'water',
        'amount' => 500.0,
        'expense_date' => now()->toDateString(),
        'payment_source' => 'cash',
    ]);
    $expense = $service->allocate($expense, AllocationMethod::EvenSplit);
    $allocation = OverheadAllocation::where('operating_unit_id', $this->unit->id)->firstOrFail();

    ($this->asManager)()->postJson("/api/v1/overhead-allocations/{$allocation->id}/mark-paid")
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_ALLOCATION_TRANSITION');

    expect($allocation->fresh()->status)->toBe(AllocationPaymentStatus::Pending);
});

test('manager must approve before mark-paid; mark-paid records payer', function () {
    $service = app(OverheadService::class);
    $expense = $service->recordExpense([
        'company_id' => $this->company->id,
        'operating_unit_id' => null,
        'category' => 'rent',
        'amount' => 2000.0,
        'expense_date' => now()->toDateString(),
        'payment_source' => 'payable',
    ]);
    $expense = $service->allocate($expense, AllocationMethod::EvenSplit);
    $allocation = OverheadAllocation::where('operating_unit_id', $this->unit->id)->firstOrFail();

    ($this->asManager)()->postJson("/api/v1/overhead-allocations/{$allocation->id}/approve")->assertStatus(200);
    ($this->asManager)()->postJson("/api/v1/overhead-allocations/{$allocation->id}/mark-paid", [
        'note' => 'Settled from cashbox 1200',
    ])->assertStatus(200)->assertJsonPath('status', 'paid');

    $fresh = $allocation->fresh();
    expect($fresh->status)->toBe(AllocationPaymentStatus::Paid);
    expect($fresh->paid_by_user_id)->toBe($this->manager->id);
    expect($fresh->paid_at)->not->toBeNull();
    expect($fresh->confirmation_note)->toBe('Settled from cashbox 1200');
});

test('overhead allocation approval fails when the unit has no manager assigned', function () {
    $this->unit->update(['manager_user_id' => null]);

    $service = app(OverheadService::class);
    $expense = $service->recordExpense([
        'company_id' => $this->company->id,
        'operating_unit_id' => null,
        'category' => 'other',
        'amount' => 100.0,
        'expense_date' => now()->toDateString(),
        'payment_source' => 'cash',
    ]);
    $expense = $service->allocate($expense, AllocationMethod::EvenSplit);
    $allocation = OverheadAllocation::where('operating_unit_id', $this->unit->id)->firstOrFail();

    ($this->asManager)()->postJson("/api/v1/overhead-allocations/{$allocation->id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('code', 'ALLOCATION_NO_RESPONSIBLE_USER');
});

test('a landed cost line can only be approved by its import order unit manager', function () {
    $order = ($this->makeReceivedOrder)();

    $line = $order->landedCostLines()->create([
        'type' => 'freight', 'amount' => 250.0, 'currency' => 'LYD',
    ]);
    expect($line->fresh()->status)->toBe(AllocationPaymentStatus::Pending);

    ($this->asOutsider)()->postJson("/api/v1/import-orders/{$order->id}/landed-cost-lines/{$line->id}/approve")
        ->assertStatus(403)
        ->assertJsonPath('code', 'ALLOCATION_NOT_RESPONSIBLE');

    ($this->asManager)()->postJson("/api/v1/import-orders/{$order->id}/landed-cost-lines/{$line->id}/approve", [
        'note' => 'OK',
    ])->assertStatus(200)->assertJsonPath('data.status', 'approved');

    ($this->asManager)()->postJson("/api/v1/import-orders/{$order->id}/landed-cost-lines/{$line->id}/mark-paid", [
        'note' => 'Bank settled',
    ])->assertStatus(200)->assertJsonPath('data.status', 'paid');

    $fresh = $line->fresh();
    expect($fresh->is_confirmed)->toBeTrue();
    expect($fresh->status)->toBe(AllocationPaymentStatus::Paid);
    expect($fresh->paid_by_user_id)->toBe($this->manager->id);
});

test('an unconfirmed landed cost line blocks import order completion even after approval', function () {
    $order = ($this->makeReceivedOrder)();

    $line = $order->landedCostLines()->create([
        'type' => 'customs', 'amount' => 100.0, 'currency' => 'LYD',
    ]);

    ($this->asManager)()->postJson("/api/v1/import-orders/{$order->id}/landed-cost-lines/{$line->id}/approve")
        ->assertStatus(200);

    expect(fn () => app(ImportOrderStateService::class)->completeOrder($order->fresh()))
        ->toThrow(InvalidArgumentException::class, 'All landed cost lines must be confirmed');

    ($this->asManager)()->postJson("/api/v1/import-orders/{$order->id}/landed-cost-lines/{$line->id}/mark-paid")
        ->assertStatus(200);

    app(ImportOrderStateService::class)->completeOrder($order->fresh());
    expect($order->fresh()->status)->toBe(ImportOrderStatus::Complete);
});

test('my-allocation-approvals only lists items for units I manage', function () {
    $otherBlueprint = UnitBlueprint::create([
        'name' => 'Other', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $otherBlueprint->id,
        'name' => 'Other', 'code' => 'OTH-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);

    $service = app(OverheadService::class);
    $e1 = $service->recordExpense([
        'company_id' => $this->company->id, 'operating_unit_id' => null,
        'category' => 'electricity', 'amount' => 800.0,
        'expense_date' => now()->toDateString(), 'payment_source' => 'cash',
    ]);
    $service->allocate($e1, AllocationMethod::ManualPercentage, [], [
        $this->unit->id => 60.0, $otherUnit->id => 40.0,
    ]);

    $mine = ($this->asManager)()->getJson('/api/v1/dashboard/my-allocation-approvals')
        ->assertStatus(200)->json();

    $unitIds = collect($mine['overhead_allocations'])->pluck('operating_unit_id')->all();

    expect($unitIds)->toContain($this->unit->id)
        ->not->toContain($otherUnit->id);
});

test('a fresh landed cost line is no longer created as pre-confirmed by the controller', function () {
    $order = ($this->makeReceivedOrder)();

    $order->landedCostLines()->create([
        'type' => 'fx_spread', 'amount' => 50.0, 'note' => 'parallel rate',
    ]);

    $line = $order->landedCostLines()->first();
    expect($line->status)->toBe(AllocationPaymentStatus::Pending);
    expect($line->is_confirmed)->toBeFalse();
});
