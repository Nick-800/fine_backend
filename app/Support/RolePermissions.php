<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Permission;
use App\Models\Role;

/**
 * Applies config/permissions.php: creates the permission catalog and grants
 * each role its default permissions. Granting only ever adds, so a
 * permission an admin removed later is not put back unless asked.
 */
final class RolePermissions
{
    /**
     * Ensure every configured permission exists.
     *
     * @return array<string, Permission> keyed by slug
     */
    public static function ensureCatalog(): array
    {
        $models = [];

        foreach (config('permissions.permissions', []) as $permission) {
            $models[$permission['slug']] = Permission::firstOrCreate(
                ['slug' => $permission['slug']],
                ['name' => $permission['name'], 'module' => $permission['module'], 'action' => $permission['action']],
            );
        }

        return $models;
    }

    /**
     * Grant a role its configured defaults (no-op for unknown slugs).
     */
    public static function grantDefaults(Role $role): void
    {
        // Slugs are matched like RequireRole does: foam-manager == foam_manager.
        $map = config('permissions.role_permissions', []);
        $defaults = $map[$role->slug]
            ?? $map[str_replace('_', '-', (string) $role->slug)]
            ?? $map[str_replace('-', '_', (string) $role->slug)]
            ?? null;

        if (! is_array($defaults) || $defaults === []) {
            return;
        }

        $catalog = self::ensureCatalog();

        $slugs = $defaults === ['*'] ? array_keys($catalog) : $defaults;
        $ids = collect($slugs)->map(fn (string $slug) => $catalog[$slug]->id ?? null)->filter()->values()->all();

        $role->permissions()->syncWithoutDetaching($ids);
    }
}
