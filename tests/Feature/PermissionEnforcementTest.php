<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Foam', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant', 'unit_type' => 'manufactory', 'currency' => 'LYD', 'status' => 'active',
    ]);

    $this->foamManagerRole = Role::create(['name' => 'Foam Manager', 'slug' => 'foam-manager']);
    $this->foamManager = User::factory()->create(['must_change_password' => false]);
    UserRole::create(['user_id' => $this->foamManager->id, 'role_id' => $this->foamManagerRole->id, 'operating_unit_id' => $this->unit->id]);

    $this->api = fn () => $this->actingAs($this->foamManager)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);
});

test('a role created under a known slug starts with its default permissions', function () {
    $slugs = $this->foamManagerRole->permissions()->pluck('slug');

    expect($slugs)->toContain('view-foam', 'edit-foam', 'view-inventory', 'edit-inventory')
        ->not->toContain('manage-accounting');
});

test('revoking the write permission blocks writes but keeps reads', function () {
    $this->foamManagerRole->permissions()->detach(Permission::where('slug', 'edit-foam')->value('id'));

    ($this->api)()->getJson('/api/v1/production-batches')->assertOk();

    ($this->api)()->postJson('/api/v1/production-batches', [
        'operation_number' => 1, 'bun_width_m' => 2.4,
    ])->assertStatus(403)
        ->assertJsonPath('code', 'ACCESS_DENIED')
        ->assertJsonPath('required_permissions.0', 'edit-foam');
});

test('revoking the view permission blocks reads', function () {
    $this->foamManagerRole->permissions()->detach(Permission::where('slug', 'view-foam')->value('id'));

    ($this->api)()->getJson('/api/v1/production-batches')
        ->assertStatus(403)
        ->assertJsonPath('required_permissions.0', 'view-foam');
});

test('the role check still bounds who reaches a module', function () {
    // Holding a permission does not open a module whose role list excludes you.
    $this->foamManagerRole->permissions()->syncWithoutDetaching(Permission::where('slug', 'manage-accounting')->value('id'));

    ($this->api)()->postJson('/api/v1/journal-entries', ['description' => 'x', 'lines' => []])
        ->assertStatus(403);
});

test('the seeder creates the manager, unit manager and inventory manager roles', function () {
    $this->seed(RoleSeeder::class);

    expect(Role::where('slug', 'unit_manager')->sole()->permissions()->pluck('slug'))->toContain('view-sales', 'manage-hr')
        ->and(Role::where('slug', 'inventory-manager')->sole()->permissions()->pluck('slug'))->toContain('view-inventory', 'edit-inventory');

    $manager = Role::where('slug', 'manager')->sole();

    expect($manager->permissions()->pluck('slug'))
        ->toContain('view-sales', 'create-sales', 'approve-credit', 'view-hr', 'manage-hr', 'settle-payables');
    expect(Permission::count())->toBe(count(config('permissions.permissions')));
});

test('the migration tops up roles in an existing database', function () {
    $this->foamManagerRole->permissions()->detach();

    $migration = require database_path('migrations/2026_10_17_120000_seed_module_permissions_and_manager_role.php');
    $migration->up();

    expect($this->foamManagerRole->permissions()->pluck('slug'))->toContain('view-foam', 'edit-foam')
        ->and(Role::where('slug', 'manager')->exists())->toBeTrue();
});
