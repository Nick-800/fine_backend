<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\OperatingUnitService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create Company
        $company = Company::create([
            'id' => (string) Str::uuid(),
            'name' => 'Al-Amana Foam & Furniture Co.',
            'default_currency' => 'LYD',
            'overhead_absorption_enabled' => false,
            'transfer_pricing_mode' => 'at_cost',
            'timezone' => 'Africa/Tripoli',
        ]);

        // 2. Create blueprints
        $blueprints = [
            [
                'name' => 'Procurement & Treasury Blueprint',
                'workflow_set' => [
                    'import_order' => ['draft', 'pending_payment', 'awaiting_bank_approval', 'awaiting_transfer', 'paid', 'in_transit', 'at_port', 'awaiting_receipt', 'received', 'complete'],
                ],
                'default_role_template' => [
                    'procurement-manager' => ['create-procurement', 'view-procurement', 'edit-procurement'],
                    'treasury-officer' => ['create-treasury', 'view-treasury', 'approve-treasury', 'view-inventory'],
                ],
                'default_inventory_config' => [
                    'warehouse_name' => 'Central Import Depot',
                ],
            ],
            [
                'name' => 'Foam Manufactory Blueprint',
                'workflow_set' => [
                    'production_batch' => ['planned', 'configured', 'running', 'consumed', 'curing', 'ready_for_grading', 'graded', 'closed'],
                ],
                'default_role_template' => [
                    'foam-manager' => ['create-foam', 'view-foam', 'edit-foam', 'grade-foam', 'view-inventory'],
                    'foam-operator' => ['view-foam', 'edit-foam', 'view-inventory'],
                ],
                'default_inventory_config' => [
                    'warehouse_name' => 'Foam Block Yard',
                ],
            ],
            [
                'name' => 'Cutter Manufactory Blueprint',
                'workflow_set' => [
                    'cutter_work_order' => ['requested', 'confirmed', 'in_production', 'awaiting_byproduct_weigh_in', 'quality_check', 'completed', 'invoiced'],
                ],
                'default_role_template' => [
                    'cutter-manager' => ['create-cutting', 'view-cutting', 'edit-cutting', 'view-inventory'],
                    'cutter-operator' => ['view-cutting', 'edit-cutting', 'view-inventory'],
                ],
                'default_inventory_config' => [
                    'warehouse_name' => 'Cuting Materials Warehouse',
                ],
            ],
            [
                'name' => 'Furniture Manufactory Blueprint',
                'workflow_set' => [
                    'production_order' => ['requested', 'bom_confirmed', 'in_production', 'quality_check', 'ready_for_collection', 'completed'],
                ],
                'default_role_template' => [
                    'furniture-manager' => ['create-furniture', 'view-furniture', 'edit-furniture', 'view-inventory'],
                    'assembler' => ['view-furniture', 'edit-furniture', 'view-inventory'],
                ],
                'default_inventory_config' => [
                    'warehouse_name' => 'Furniture Assembly Warehouse',
                ],
            ],
            [
                'name' => 'Store Blueprint',
                'workflow_set' => [
                    'sales_order' => ['draft', 'credit_check', 'pending_approval', 'confirmed', 'fulfilled', 'paid', 'partially_paid', 'rejected'],
                    'pos_sale' => ['draft', 'completed', 'refunded'],
                ],
                'default_role_template' => [
                    'store-manager' => ['create-sales', 'view-sales', 'edit-sales', 'view-inventory'],
                    'pos-cashier' => ['create-sales', 'view-sales', 'view-inventory'],
                ],
                'default_inventory_config' => [
                    'warehouse_name' => 'Showroom Warehouse',
                ],
            ],
        ];

        $blueprintModels = [];
        foreach ($blueprints as $bp) {
            $blueprintModels[$bp['name']] = UnitBlueprint::create($bp);
        }

        // 3. Provision units
        $service = new OperatingUnitService;

        $procurementUnit = $service->provision($blueprintModels['Procurement & Treasury Blueprint'], 'Central Procurement & Treasury');
        $foamUnit = $service->provision($blueprintModels['Foam Manufactory Blueprint'], 'Tajoura Foam Manufactory');
        $cutterUnit = $service->provision($blueprintModels['Cutter Manufactory Blueprint'], 'Cutter Plant A');
        $furnitureUnit = $service->provision($blueprintModels['Furniture Manufactory Blueprint'], 'Furniture Assembly Unit B');
        $showroomUnit = $service->provision($blueprintModels['Store Blueprint'], 'Tripoli Main Showroom');

        // 4. Create Owner Role & Owner User (Company-wide)
        $ownerRole = Role::create([
            'id' => (string) Str::uuid(),
            'name' => 'Owner',
            'slug' => 'owner',
            'description' => 'Global system owner with full control.',
        ]);

        // Map a sample global permission
        $allPermissions = Permission::all();
        foreach ($allPermissions as $perm) {
            $ownerRole->permissions()->syncWithoutDetaching([$perm->id]);
        }

        $owner = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Nick Owner',
            'email' => 'owner@erp.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        UserRole::create([
            'id' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'role_id' => $ownerRole->id,
            'operating_unit_id' => null, // Company-wide
        ]);

        // 5. Create Managers for operating units
        $managers = [
            [
                'name' => 'Procurement Manager',
                'email' => 'procurement@erp.com',
                'role_slug' => 'procurement-manager',
                'unit_id' => $procurementUnit->id,
            ],
            [
                'name' => 'Foam Plant Manager',
                'email' => 'foam@erp.com',
                'role_slug' => 'foam-manager',
                'unit_id' => $foamUnit->id,
            ],
            [
                'name' => 'Cutter Manager',
                'email' => 'cutter@erp.com',
                'role_slug' => 'cutter-manager',
                'unit_id' => $cutterUnit->id,
            ],
            [
                'name' => 'Furniture Manager',
                'email' => 'furniture@erp.com',
                'role_slug' => 'furniture-manager',
                'unit_id' => $furnitureUnit->id,
            ],
            [
                'name' => 'Showroom Manager',
                'email' => 'showroom@erp.com',
                'role_slug' => 'store-manager',
                'unit_id' => $showroomUnit->id,
            ],
        ];

        foreach ($managers as $m) {
            $user = User::create([
                'id' => (string) Str::uuid(),
                'name' => $m['name'],
                'email' => $m['email'],
                'password' => Hash::make('password'),
                'is_active' => true,
                'must_change_password' => true,
            ]);

            $role = Role::where('slug', $m['role_slug'])->first();

            UserRole::create([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $role->id,
                'operating_unit_id' => $m['unit_id'], // Scoped to unit
            ]);
        }

        // 6. Execute Domain Seeders
        $this->call([
            EntitySeeder::class,
            DomainModelsSeeder::class,
            ProcurementTreasurySeeder::class,
            WorkOrderInventorySeeder::class,
        ]);
    }
}
