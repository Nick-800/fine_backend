<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\ChartOfAccounts;
use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Audit Test', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'BP', 'workflow_set' => '[]', 'default_role_template' => '[]', 'default_inventory_config' => '[]',
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'Test Unit', 'code' => 'AT-1', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);
    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'WH', 'code' => 'WH-1',
    ]);

    $this->role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    $this->user = User::factory()->create(['must_change_password' => false]);
    UserRole::create([
        'user_id' => $this->user->id, 'role_id' => $this->role->id, 'operating_unit_id' => null,
    ]);

    $this->api = fn () => $this->actingAs($this->user);

    $this->coaId = ChartOfAccounts::first()->id;

    $this->parent = Account::create([
        'chart_of_accounts_id' => $this->coaId,
        'parent_account_id' => null,
        'account_code' => '99001',
        'name' => 'مخزون إضافي',
        'type' => 'asset',
        'section' => 'current_assets',
        'currency' => 'LYD',
    ]);

    $this->leaf = Account::create([
        'chart_of_accounts_id' => $this->coaId,
        'parent_account_id' => $this->parent->id,
        'account_code' => '99002',
        'name' => 'مخزون إضافي - نوع أ',
        'type' => 'asset',
        'section' => 'current_assets',
        'currency' => 'LYD',
    ]);
});

test('store: creates an "account created" audit log entry', function () {
    $coaId = $this->parent->chart_of_accounts_id;
    $before = AuditLog::where('table_name', 'accounts')->count();

    $response = ($this->api)()->postJson('/api/v1/accounts', [
        'account_code' => '99003',
        'name' => 'مخزون إضافي - نوع ب',
        'type' => 'asset',
        'parent_account_id' => $this->parent->id,
        'currency' => 'LYD',
    ]);

    $response->assertStatus(201);
    $newAccountId = $response->json('data.id');

    $entry = AuditLog::where('table_name', 'accounts')
        ->where('record_id', $newAccountId)
        ->where('action', 'created')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->new_values['account_code'])->toBe('99003')
        ->and($entry->new_values['name'])->toBe('مخزون إضافي - نوع ب')
        ->and($entry->new_values['type'])->toBe('asset')
        ->and($entry->new_values['parent_account_id'])->toBe($this->parent->id)
        ->and($entry->user_id)->toBe($this->user->id)
        ->and(AuditLog::where('table_name', 'accounts')->count())->toBe($before + 1);
});

test('update: writes an "account updated" audit log with old and new values', function () {
    $response = ($this->api)()->putJson("/api/v1/accounts/{$this->leaf->id}", [
        'name' => 'مخزون إضافي - نوع أ (معدل)',
        'currency' => 'USD',
    ]);

    $response->assertOk();

    $entry = AuditLog::where('table_name', 'accounts')
        ->where('record_id', $this->leaf->id)
        ->where('action', 'updated')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->old_values['name'])->toBe('مخزون إضافي - نوع أ')
        ->and($entry->new_values['name'])->toBe('مخزون إضافي - نوع أ (معدل)')
        ->and($entry->old_values['currency'])->toBe('LYD')
        ->and($entry->new_values['currency'])->toBe('USD');
});

test('reparent: writes an "account updated" entry capturing the parent_account_id change', function () {
    $sibling = Account::create([
        'chart_of_accounts_id' => $this->coaId,
        'parent_account_id' => null,
        'account_code' => '99004',
        'name' => 'حساب مدينون آخر',
        'type' => 'asset',
        'section' => 'current_assets',
        'currency' => 'LYD',
    ]);

    $response = ($this->api)()->patchJson("/api/v1/accounts/{$this->leaf->id}/parent", [
        'parent_account_id' => $sibling->id,
    ]);

    $response->assertOk();

    $entry = AuditLog::where('table_name', 'accounts')
        ->where('record_id', $this->leaf->id)
        ->where('action', 'updated')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->old_values['parent_account_id'])->toBe($this->parent->id)
        ->and($entry->new_values['parent_account_id'])->toBe($sibling->id);
});

test('destroy: writes an "account deleted" audit log entry', function () {
    $response = ($this->api)()->deleteJson("/api/v1/accounts/{$this->leaf->id}");

    $response->assertOk();

    $entry = AuditLog::where('table_name', 'accounts')
        ->where('record_id', $this->leaf->id)
        ->where('action', 'deleted')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->old_values['account_code'])->toBe('99002')
        ->and($entry->old_values['name'])->toBe('مخزون إضافي - نوع أ')
        ->and($entry->new_values)->toBeNull();
});

test('the account audit log is queryable via GET /api/v1/audit-logs/accounts/{id}', function () {
    ($this->api)()->putJson("/api/v1/accounts/{$this->leaf->id}", ['name' => 'تعديل للاختبار']);
    ($this->api)()->patchJson("/api/v1/accounts/{$this->leaf->id}/parent", ['parent_account_id' => $this->parent->id]);

    $response = ($this->api)()->getJson("/api/v1/audit-logs/accounts/{$this->leaf->id}");

    $response->assertOk();
    $entries = $response->json('data');
    expect($entries)->toBeArray()
        ->and(count($entries))->toBeGreaterThanOrEqual(2);

    $actions = collect($entries)->pluck('action')->all();
    expect($actions)->toContain('updated');
});
