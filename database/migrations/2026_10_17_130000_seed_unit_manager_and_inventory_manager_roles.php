<?php

declare(strict_types=1);

use App\Models\Role;
use App\Support\RolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The routes have always admitted `unit_manager` and `inventory-manager`, but
 * nothing created them. Create both on existing databases with their default
 * permissions (config/permissions.php); fresh databases get them from
 * RoleSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || Role::query()->doesntExist()) {
            return;
        }

        foreach (['unit_manager', 'inventory-manager'] as $slug) {
            $meta = config("permissions.roles.{$slug}");
            $role = Role::firstOrCreate(['slug' => $slug], ['name' => $meta['name'], 'description' => $meta['description']]);
            RolePermissions::grantDefaults($role);
        }
    }

    public function down(): void
    {
        // Roles may already be assigned to users; leave them in place.
    }
};
