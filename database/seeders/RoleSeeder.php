<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Support\RolePermissions;
use Illuminate\Database\Seeder;

/**
 * Seeds the permission catalog and the standard roles from
 * config/permissions.php, granting each role its default permissions.
 */
class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        RolePermissions::ensureCatalog();

        foreach (config('permissions.roles', []) as $slug => $meta) {
            $role = Role::firstOrCreate(
                ['slug' => $slug],
                ['name' => $meta['name'], 'description' => $meta['description']],
            );

            // firstOrCreate fires `created` only for new rows; top up existing ones.
            RolePermissions::grantDefaults($role);
        }
    }
}
