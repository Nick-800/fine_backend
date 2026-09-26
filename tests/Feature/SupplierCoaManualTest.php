<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\ChartOfAccounts;
use App\Models\Company;
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
        'name' => 'Test Corporation',
        'default_currency' => 'LYD',
        'overhead_absorption_enabled' => true,
        'transfer_pricing_mode' => 'cost_plus',
        'timezone' => 'UTC',
    ]);

    $this->coa = ChartOfAccounts::create([
        'company_id' => $this->company->id,
        'name' => 'دليل الحسابات الرئيسي',
    ]);

    $this->parentAccount = Account::create([
        'chart_of_accounts_id' => $this->coa->id,
        'account_code' => '21',
        'name' => 'Accounts Payable',
        'type' => 'liability',
        'section' => 'current_liabilities',
        'currency' => 'LYD',
    ]);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Standard Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->operatingUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Main Operating Unit',
        'unit_type' => 'factory',
        'currency' => 'LYD',
        'status' => 'active',
    ]);

    $this->role = Role::create([
        'name' => 'Admin',
        'slug' => 'admin',
    ]);

    $this->user = User::factory()->create([
        'must_change_password' => false,
    ]);

    UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => $this->role->id,
        'operating_unit_id' => $this->operatingUnit->id,
    ]);
});

test('operator can create supplier and manually provision a new COA sub-account', function () {
    $response = $this->actingAs($this->user)
        ->withHeader('X-Operating-Unit-ID', $this->operatingUnit->id)
        ->postJson('/api/v1/suppliers', [
            'operating_unit_id' => $this->operatingUnit->id,
            'name' => 'Al-Amal Suppliers',
            'contact' => 'info@alamal.ly',
            'default_currency' => 'LYD',
            'address' => 'Tripoli',
            'coa_action' => 'create_new',
            'new_account' => [
                'parent_account_id' => $this->parentAccount->id,
                'account_code' => '210001',
                'name' => 'حساب المورد - الأمل',
                'currency' => 'LYD',
            ],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Al-Amal Suppliers')
        ->assertJsonPath('data.account.account_code', '210001')
        ->assertJsonPath('data.account.name', 'حساب المورد - الأمل');

    $this->assertDatabaseHas('accounts', [
        'account_code' => '210001',
        'name' => 'حساب المورد - الأمل',
        'parent_account_id' => $this->parentAccount->id,
        'type' => 'liability',
    ]);

    $createdSupplier = Supplier::where('name', 'Al-Amal Suppliers')->first();
    expect($createdSupplier->account_id)->not->toBeNull();
});

test('operator can create supplier and link to existing COA account', function () {
    $existing = Account::create([
        'chart_of_accounts_id' => $this->coa->id,
        'account_code' => '210099',
        'name' => 'حساب موردين متنوعين',
        'type' => 'liability',
        'section' => 'current_liabilities',
        'currency' => 'LYD',
    ]);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Operating-Unit-ID', $this->operatingUnit->id)
        ->postJson('/api/v1/suppliers', [
            'operating_unit_id' => $this->operatingUnit->id,
            'name' => 'General Vendor',
            'coa_action' => 'link_existing',
            'account_id' => $existing->id,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.account_id', $existing->id)
        ->assertJsonPath('data.account.account_code', '210099');
});

test('creating supplier with duplicate account code is rejected', function () {
    Account::create([
        'chart_of_accounts_id' => $this->coa->id,
        'account_code' => '210001',
        'name' => 'Already Existing',
        'type' => 'liability',
        'currency' => 'LYD',
    ]);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Operating-Unit-ID', $this->operatingUnit->id)
        ->postJson('/api/v1/suppliers', [
            'operating_unit_id' => $this->operatingUnit->id,
            'name' => 'Duplicate Attempt',
            'coa_action' => 'create_new',
            'new_account' => [
                'parent_account_id' => $this->parentAccount->id,
                'account_code' => '210001',
                'name' => 'Duplicate Code',
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['new_account.account_code']);
});
