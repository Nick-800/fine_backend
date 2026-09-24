<?php

declare(strict_types=1);

use App\Enums\ImportOrderStatus;
use App\Enums\PaymentRoute;
use App\Models\Account;
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
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->company = Company::first();
    $this->blueprint = UnitBlueprint::create([
        'name' => 'Test Blueprint',
        'workflow_set' => '[]',
        'default_role_template' => '[]',
        'default_inventory_config' => '[]',
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
    $role = Role::create(['name' => 'AM', 'slug' => 'accounting-manager']);
    UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => $role->id,
        'operating_unit_id' => $this->unit->id,
    ]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $this->main = Account::where('account_code', '1')->sole();
    $this->sub = Account::where('account_code', '111')->sole();
});

test('a main account can be renamed', function () {
    ($this->api)()->putJson("/api/v1/accounts/{$this->main->id}", [
        'name' => 'الأصول — اسم جديد',
    ])->assertStatus(200);

    expect((string) $this->main->fresh()->name)->toBe('الأصول — اسم جديد');
});

test('a main account cannot have its code changed', function () {
    ($this->api)()->putJson("/api/v1/accounts/{$this->main->id}", [
        'name' => 'New Name',
        'account_code' => '9999',
    ])->assertStatus(422)->assertJsonPath('code', 'MAIN_ACCOUNT_CODE_LOCKED');

    expect($this->main->fresh()->account_code)->toBe('1');
});

test('a sub-account can be renamed', function () {
    ($this->api)()->putJson("/api/v1/accounts/{$this->sub->id}", [
        'name' => 'مخزون المواد الخام — اسم جديد',
    ])->assertStatus(200);

    expect((string) $this->sub->fresh()->name)->toBe('مخزون المواد الخام — اسم جديد');
});

test('a sub-account can have its code changed', function () {
    ($this->api)()->putJson("/api/v1/accounts/{$this->sub->id}", [
        'name' => 'Raw Material Inventory',
        'account_code' => '1111',
    ])->assertStatus(200);

    $fresh = $this->sub->fresh();
    expect($fresh->account_code)->toBe('1111')
        ->and((string) $fresh->name)->toBe('Raw Material Inventory');
});

test('a sub-account code change rejects a duplicate code', function () {
    $other = Account::where('account_code', '112')->sole();
    ($this->api)()->putJson("/api/v1/accounts/{$this->sub->id}", [
        'name' => 'Whatever',
        'account_code' => '112',
    ])->assertStatus(422)->assertJsonValidationErrors(['account_code']);
});

test('a sub-account currency can be edited', function () {
    ($this->api)()->putJson("/api/v1/accounts/{$this->sub->id}", [
        'name' => 'Raw Material Inventory',
        'currency' => 'usd',
    ])->assertStatus(200);

    expect($this->sub->fresh()->currency)->toBe('USD');
});

test('a main account cannot be deleted', function () {
    ($this->api)()->deleteJson("/api/v1/accounts/{$this->main->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'MAIN_ACCOUNT_NOT_DELETABLE');

    expect(Account::find($this->main->id))->not->toBeNull();
});

test('a sub-account with journal lines cannot be deleted', function () {
    // Drive an import order to completion so 1500 (a sub-account) has lines.
    $order = ImportOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $this->supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 1000,
        'quantity' => 1,
        'booked_fx_rate' => 5.0,
        'status' => ImportOrderStatus::Draft,
    ]);
    $svc = app(ImportOrderStateService::class);
    $svc->transitionToPendingPayment($order);
    $svc->selectPaymentRoute($order->fresh(), PaymentRoute::Bank, 1000, 5500.00);
    $pr = $order->paymentRequests()->sole();
    $svc->executePayment($pr, null, exactAmountUsedLyd: 5000.0);

    $advance = Account::where('account_code', '15')->sole();
    expect($advance->journalLines()->exists())->toBeTrue();

    ($this->api)()->deleteJson("/api/v1/accounts/{$advance->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'ACCOUNT_HAS_TRANSACTIONS');

    expect(Account::find($advance->id))->not->toBeNull();
});

test('a sub-account with children cannot be deleted', function () {
    // 1100 (Inventory) has 1110, 1120, 1130, 1200, 1300, 1400, 1450, 1500
    // as direct children in the standard seed.
    $parent = Account::where('account_code', '11')->sole();
    expect($parent->children()->exists())->toBeTrue();

    ($this->api)()->deleteJson("/api/v1/accounts/{$parent->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'ACCOUNT_HAS_CHILDREN');

    expect(Account::find($parent->id))->not->toBeNull();
});

test('an empty sub-account can be deleted', function () {
    // 1110 has no children and no journal lines — clean delete target.
    expect($this->sub->children()->exists())->toBeFalse();
    expect($this->sub->journalLines()->exists())->toBeFalse();

    ($this->api)()->deleteJson("/api/v1/accounts/{$this->sub->id}")
        ->assertStatus(200);

    expect(Account::find($this->sub->id))->toBeNull();
});

test('a new main account can be created via the store endpoint', function () {
    $countBefore = Account::where('parent_account_id', null)->count();

    ($this->api)()->postJson('/api/v1/accounts', [
        'account_code' => '6000',
        'name' => 'حساب رئيسي جديد',
        'type' => 'expense',
        'currency' => 'LYD',
    ])->assertStatus(201);

    expect(Account::where('parent_account_id', null)->count())->toBe($countBefore + 1)
        ->and(Account::where('account_code', '6000')->first())->not->toBeNull();
});
