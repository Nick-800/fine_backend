<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Store', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Showroom', 'unit_type' => 'store', 'currency' => 'LYD', 'status' => 'active',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $this->makeEmployee = function (string $name): Employee {
        $entity = Entity::create(['name' => $name, 'entity_type' => 'individual']);

        return Employee::create([
            'entity_id' => $entity->id, 'operating_unit_id' => $this->unit->id, 'job_title' => 'Worker',
            'pay_type' => 'monthly', 'monthly_salary' => 1000, 'hire_date' => '2026-01-01', 'status' => 'active',
        ]);
    };
});

test('creating a client is audit-logged', function () {
    $entity = Entity::create(['name' => 'Al-Waha', 'entity_type' => 'organization']);
    $client = Client::create([
        'entity_id' => $entity->id, 'operating_unit_id' => $this->unit->id, 'credit_limit' => 1000, 'current_balance' => 0,
    ]);

    expect(AuditLog::where('table_name', 'clients')->where('record_id', $client->id)->where('action', 'created')->exists())
        ->toBeTrue();
});

test('updating an employee is audit-logged with old and new values', function () {
    $employee = ($this->makeEmployee)('Salem');
    $employee->update(['job_title' => 'Supervisor']);

    $log = AuditLog::where('table_name', 'employees')->where('record_id', $employee->id)->where('action', 'updated')->sole();

    expect($log->old_values['job_title'])->toBe('Worker')
        ->and($log->new_values['job_title'])->toBe('Supervisor');
});

test('restoring a soft-deleted employee is audit-logged', function () {
    $employee = ($this->makeEmployee)('Hadi');
    $employee->delete();
    $employee->restore();

    expect(AuditLog::where('table_name', 'employees')->where('record_id', $employee->id)->where('action', 'restored')->exists())
        ->toBeTrue();
});

test('the audit log index is paginated and filterable', function () {
    ($this->makeEmployee)('One');
    ($this->makeEmployee)('Two');
    ($this->makeEmployee)('Three');

    $page = $this->actingAs($this->owner)->getJson('/api/v1/audit-logs?table_name=employees&action=created&per_page=2')
        ->assertOk()
        ->json();

    expect($page['data'])->toHaveCount(2)
        ->and($page['meta']['total'])->toBe(3)
        ->and(collect($page['data'])->pluck('table_name')->unique()->all())->toBe(['employees']);

    $this->actingAs($this->owner)->getJson('/api/v1/audit-logs?action=bogus')->assertStatus(422);
});
