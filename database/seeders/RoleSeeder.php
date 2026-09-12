<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // Users & Admin
            ['slug' => 'manage-users', 'name' => 'Manage Users', 'module' => 'users', 'action' => 'manage'],
            ['slug' => 'view-users', 'name' => 'View Users', 'module' => 'users', 'action' => 'view'],
            ['slug' => 'create-users', 'name' => 'Create Users', 'module' => 'users', 'action' => 'create'],
            ['slug' => 'edit-users', 'name' => 'Edit Users', 'module' => 'users', 'action' => 'edit'],
            ['slug' => 'delete-users', 'name' => 'Delete Users', 'module' => 'users', 'action' => 'delete'],

            // Procurement
            ['slug' => 'create-procurement', 'name' => 'Create Procurement', 'module' => 'procurement', 'action' => 'create'],
            ['slug' => 'view-procurement', 'name' => 'View Procurement', 'module' => 'procurement', 'action' => 'view'],
            ['slug' => 'edit-procurement', 'name' => 'Edit Procurement', 'module' => 'procurement', 'action' => 'edit'],

            // Treasury
            ['slug' => 'create-treasury', 'name' => 'Create Treasury', 'module' => 'treasury', 'action' => 'create'],
            ['slug' => 'view-treasury', 'name' => 'View Treasury', 'module' => 'treasury', 'action' => 'view'],
            ['slug' => 'approve-treasury', 'name' => 'Approve Treasury', 'module' => 'treasury', 'action' => 'approve'],
            ['slug' => 'settle-payables', 'name' => 'Settle Payables', 'module' => 'treasury', 'action' => 'settle'],

            // Inventory
            ['slug' => 'view-inventory', 'name' => 'View Inventory', 'module' => 'inventory', 'action' => 'view'],

            // Foam Production
            ['slug' => 'create-foam', 'name' => 'Create Foam', 'module' => 'foam', 'action' => 'create'],
            ['slug' => 'view-foam', 'name' => 'View Foam', 'module' => 'foam', 'action' => 'view'],
            ['slug' => 'edit-foam', 'name' => 'Edit Foam', 'module' => 'foam', 'action' => 'edit'],
            ['slug' => 'grade-foam', 'name' => 'Grade Foam', 'module' => 'foam', 'action' => 'grade'],

            // Cutting
            ['slug' => 'create-cutting', 'name' => 'Create Cutting', 'module' => 'cutting', 'action' => 'create'],
            ['slug' => 'view-cutting', 'name' => 'View Cutting', 'module' => 'cutting', 'action' => 'view'],
            ['slug' => 'edit-cutting', 'name' => 'Edit Cutting', 'module' => 'cutting', 'action' => 'edit'],

            // Furniture
            ['slug' => 'create-furniture', 'name' => 'Create Furniture', 'module' => 'furniture', 'action' => 'create'],
            ['slug' => 'view-furniture', 'name' => 'View Furniture', 'module' => 'furniture', 'action' => 'view'],
            ['slug' => 'edit-furniture', 'name' => 'Edit Furniture', 'module' => 'furniture', 'action' => 'edit'],

            // Sales & POS
            ['slug' => 'create-sales', 'name' => 'Create Sales', 'module' => 'sales', 'action' => 'create'],
            ['slug' => 'view-sales', 'name' => 'View Sales', 'module' => 'sales', 'action' => 'view'],
            ['slug' => 'edit-sales', 'name' => 'Edit Sales', 'module' => 'sales', 'action' => 'edit'],
        ];

        $permissionModels = [];
        foreach ($permissions as $permData) {
            $permissionModels[$permData['slug']] = Permission::firstOrCreate(
                ['slug' => $permData['slug']],
                [
                    'name' => $permData['name'],
                    'module' => $permData['module'],
                    'action' => $permData['action'],
                ]
            );
        }

        $roles = [
            [
                'name' => 'Owner',
                'slug' => 'owner',
                'description' => 'Global system owner with full control.',
                'permissions' => ['*'],
            ],
            [
                'name' => 'Admin',
                'slug' => 'admin',
                'description' => 'Global administrator with full administrative access.',
                'permissions' => ['*'],
            ],
            [
                'name' => 'Accounting Manager',
                'slug' => 'accounting-manager',
                'description' => 'Financial and accounting management.',
                'permissions' => ['settle-payables', 'view-inventory', 'view-procurement', 'view-treasury'],
            ],
            [
                'name' => 'HR Manager',
                'slug' => 'hr-manager',
                'description' => 'Human resources and payroll management.',
                'permissions' => ['manage-users', 'view-users', 'create-users', 'edit-users'],
            ],
            [
                'name' => 'Procurement Manager',
                'slug' => 'procurement-manager',
                'description' => 'Procurement and purchasing management.',
                'permissions' => ['create-procurement', 'view-procurement', 'edit-procurement', 'view-inventory'],
            ],
            [
                'name' => 'Treasury Officer',
                'slug' => 'treasury-officer',
                'description' => 'Treasury and cash flow management.',
                'permissions' => ['create-treasury', 'view-treasury', 'approve-treasury', 'view-inventory', 'settle-payables'],
            ],
            [
                'name' => 'Foam Plant Manager',
                'slug' => 'foam-manager',
                'description' => 'Foam production plant management.',
                'permissions' => ['create-foam', 'view-foam', 'edit-foam', 'grade-foam', 'view-inventory'],
            ],
            [
                'name' => 'Foam Operator',
                'slug' => 'foam-operator',
                'description' => 'Foam manufactory machine operator.',
                'permissions' => ['view-foam', 'edit-foam', 'view-inventory'],
            ],
            [
                'name' => 'Cutter Plant Manager',
                'slug' => 'cutter-manager',
                'description' => 'Cutter production plant management.',
                'permissions' => ['create-cutting', 'view-cutting', 'edit-cutting', 'view-inventory'],
            ],
            [
                'name' => 'Cutter Operator',
                'slug' => 'cutter-operator',
                'description' => 'Cutter machine operator.',
                'permissions' => ['view-cutting', 'edit-cutting', 'view-inventory'],
            ],
            [
                'name' => 'Furniture Manager',
                'slug' => 'furniture-manager',
                'description' => 'Furniture assembly management.',
                'permissions' => ['create-furniture', 'view-furniture', 'edit-furniture', 'view-inventory'],
            ],
            [
                'name' => 'Furniture Assembler',
                'slug' => 'assembler',
                'description' => 'Furniture assembly worker.',
                'permissions' => ['view-furniture', 'edit-furniture', 'view-inventory'],
            ],
            [
                'name' => 'Showroom Manager',
                'slug' => 'store-manager',
                'description' => 'Showroom and sales management.',
                'permissions' => ['create-sales', 'view-sales', 'edit-sales', 'view-inventory'],
            ],
            [
                'name' => 'POS Cashier',
                'slug' => 'pos-cashier',
                'description' => 'Point of sale cashier.',
                'permissions' => ['create-sales', 'view-sales', 'view-inventory'],
            ],
        ];

        foreach ($roles as $roleData) {
            $role = Role::firstOrCreate(
                ['slug' => $roleData['slug']],
                [
                    'name' => $roleData['name'],
                    'description' => $roleData['description'],
                ]
            );

            if ($roleData['permissions'] === ['*']) {
                $role->permissions()->syncWithoutDetaching(
                    collect($permissionModels)->pluck('id')->all()
                );
            } else {
                $permissionIds = collect($roleData['permissions'])
                    ->map(fn (string $slug) => $permissionModels[$slug]->id ?? null)
                    ->filter()
                    ->values()
                    ->all();

                if (! empty($permissionIds)) {
                    $role->permissions()->syncWithoutDetaching($permissionIds);
                }
            }
        }
    }
}
