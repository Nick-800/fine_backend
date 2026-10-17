<?php

declare(strict_types=1);

use App\Models\Role;
use App\Support\RolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * W0-10: routes now check module permissions (require.module). Live
 * databases only had the old 26 permissions, so create the full catalog,
 * create the `manager` role the routes have always referenced, and grant
 * every existing role its defaults — nobody loses access on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fresh databases get roles and permissions from RoleSeeder.
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || Role::query()->doesntExist()) {
            return;
        }

        RolePermissions::ensureCatalog();

        $manager = config('permissions.roles.manager');
        Role::firstOrCreate(['slug' => 'manager'], ['name' => $manager['name'], 'description' => $manager['description']]);

        Role::query()->each(fn (Role $role) => RolePermissions::grantDefaults($role));
    }

    public function down(): void
    {
        // Granted permissions are left in place; removing them could lock users out.
    }
};
