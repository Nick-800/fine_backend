<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->blueprint = UnitBlueprint::create([
        'name' => 'Foam Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Test Unit',
        'unit_type' => 'manufactory',
    ]);

    // Seed three audit entries on the unit with different actions + dates.
    AuditLog::create([
        'user_id' => $this->owner->id,
        'operating_unit_id' => null,
        'table_name' => 'operating_units',
        'record_id' => $this->unit->id,
        'action' => 'created',
        'old_values' => null,
        'new_values' => ['name' => 'Test Unit'],
        'created_at' => Carbon::now()->subDays(5),
    ]);
    AuditLog::create([
        'user_id' => $this->owner->id,
        'operating_unit_id' => null,
        'table_name' => 'operating_units',
        'record_id' => $this->unit->id,
        'action' => 'updated',
        'old_values' => ['name' => 'Test Unit'],
        'new_values' => ['name' => 'Renamed'],
        'created_at' => Carbon::now()->subDays(3),
    ]);
    AuditLog::create([
        'user_id' => $this->owner->id,
        'operating_unit_id' => null,
        'table_name' => 'operating_units',
        'record_id' => $this->unit->id,
        'action' => 'deleted',
        'old_values' => ['name' => 'Renamed'],
        'new_values' => null,
        'created_at' => Carbon::now()->subDay(),
    ]);
});

test('audit log endpoint returns all entries for a record by default', function () {
    $rows = $this->actingAs($this->owner)
        ->getJson("/api/v1/audit-logs/operating_units/{$this->unit->id}")
        ->assertOk()
        ->json('data');

    // The OperatingUnit::create in beforeEach already fires a 'created' event,
    // so we have the seed entries (created/updated/deleted) plus the model's
    // own create entry — 4 total.
    expect($rows)->toHaveCount(4);
});

test('audit log endpoint filters by action', function () {
    $rows = $this->actingAs($this->owner)
        ->getJson("/api/v1/audit-logs/operating_units/{$this->unit->id}?action=updated")
        ->assertOk()
        ->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['action'])->toBe('updated');
});

test('audit log endpoint filters by date range', function () {
    $from = Carbon::now()->subDays(4)->toDateString();
    $to = Carbon::now()->subDays(2)->toDateString();

    $rows = $this->actingAs($this->owner)
        ->getJson("/api/v1/audit-logs/operating_units/{$this->unit->id}?from={$from}&to={$to}")
        ->assertOk()
        ->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['action'])->toBe('updated');
});

test('audit log endpoint rejects an unknown action', function () {
    $this->actingAs($this->owner)
        ->getJson("/api/v1/audit-logs/operating_units/{$this->unit->id}?action=lol")
        ->assertStatus(422);
});

test('audit log endpoint rejects to-before-from', function () {
    $from = Carbon::now()->subDays(1)->toDateString();
    $to = Carbon::now()->subDays(5)->toDateString();

    $this->actingAs($this->owner)
        ->getJson("/api/v1/audit-logs/operating_units/{$this->unit->id}?from={$from}&to={$to}")
        ->assertStatus(422);
});
