<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OperatingUnitService
{
    /**
     * Provision a new operating unit dynamically from a blueprint.
     */
    public function provision(UnitBlueprint $blueprint, string $name): OperatingUnit
    {
        return DB::transaction(function () use ($blueprint, $name) {
            // Ensure at least one company exists
            $company = Company::first();
            if (! $company) {
                $company = Company::create([
                    'id' => (string) Str::uuid(),
                    'name' => 'Foam to Furniture Inc.',
                    'default_currency' => 'LYD',
                    'transfer_pricing_mode' => 'at_cost',
                    'timezone' => 'Africa/Tripoli',
                ]);
            }

            // Determine unit type from blueprint name/config
            $unitType = 'manufactory';
            $blueprintNameLower = strtolower($blueprint->name);
            if (str_contains($blueprintNameLower, 'store') || str_contains($blueprintNameLower, 'showroom')) {
                $unitType = 'store';
            } elseif (str_contains($blueprintNameLower, 'office') || str_contains($blueprintNameLower, 'treasury')) {
                $unitType = 'office';
            }

            // Create Operating Unit
            $unit = OperatingUnit::create([
                'company_id' => $company->id,
                'blueprint_id' => $blueprint->id,
                'name' => $name,
                'unit_type' => $unitType,
                'currency' => 'LYD',
                'status' => 'provisioning',
            ]);

            // Create default Warehouse
            $inventoryConfig = $blueprint->default_inventory_config ?? [];
            $warehouseName = $name.' Warehouse';
            if (! empty($inventoryConfig['warehouse_name'])) {
                $warehouseName = $name.' '.$inventoryConfig['warehouse_name'];
            }

            Warehouse::create([
                'operating_unit_id' => $unit->id,
                'name' => $warehouseName,
                'is_internal_unit' => $unitType !== 'store',
            ]);

            // Build roles and permissions from template
            $roleTemplate = $blueprint->default_role_template ?? [];
            foreach ($roleTemplate as $roleSlug => $permissions) {
                $role = Role::firstOrCreate(
                    ['slug' => $roleSlug],
                    [
                        'name' => ucwords(str_replace('-', ' ', $roleSlug)),
                        'description' => 'Automatically generated role for '.$roleSlug,
                    ]
                );

                foreach ($permissions as $permissionSlug) {
                    $parts = explode('-', $permissionSlug);
                    $action = $parts[0] ?? 'view';
                    $module = $parts[1] ?? 'general';

                    $permission = Permission::firstOrCreate(
                        ['slug' => $permissionSlug],
                        [
                            'name' => ucwords(str_replace('-', ' ', $permissionSlug)),
                            'module' => $module,
                            'action' => $action,
                        ]
                    );

                    $role->permissions()->syncWithoutDetaching([$permission->id]);
                }
            }

            // Mark provisioning complete
            $unit->update(['status' => 'active']);

            return $unit;
        });
    }
}
