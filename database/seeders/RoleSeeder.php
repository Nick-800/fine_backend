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
            ['slug' => 'manage-users', 'name' => 'إدارة المستخدمين', 'module' => 'users', 'action' => 'manage'],
            ['slug' => 'view-users', 'name' => 'عرض المستخدمين', 'module' => 'users', 'action' => 'view'],
            ['slug' => 'create-users', 'name' => 'إنشاء مستخدمين', 'module' => 'users', 'action' => 'create'],
            ['slug' => 'edit-users', 'name' => 'تعديل المستخدمين', 'module' => 'users', 'action' => 'edit'],
            ['slug' => 'delete-users', 'name' => 'حذف المستخدمين', 'module' => 'users', 'action' => 'delete'],

            // Procurement
            ['slug' => 'create-procurement', 'name' => 'إنشاء مشتريات', 'module' => 'procurement', 'action' => 'create'],
            ['slug' => 'view-procurement', 'name' => 'عرض المشتريات', 'module' => 'procurement', 'action' => 'view'],
            ['slug' => 'edit-procurement', 'name' => 'تعديل المشتريات', 'module' => 'procurement', 'action' => 'edit'],

            // Treasury
            ['slug' => 'create-treasury', 'name' => 'إنشاء عمليات خزانة', 'module' => 'treasury', 'action' => 'create'],
            ['slug' => 'view-treasury', 'name' => 'عرض الخزانة', 'module' => 'treasury', 'action' => 'view'],
            ['slug' => 'approve-treasury', 'name' => 'اعتماد عمليات الخزانة', 'module' => 'treasury', 'action' => 'approve'],
            ['slug' => 'settle-payables', 'name' => 'تسوية الذمم الدائنة', 'module' => 'treasury', 'action' => 'settle'],

            // Inventory
            ['slug' => 'view-inventory', 'name' => 'عرض المخزون', 'module' => 'inventory', 'action' => 'view'],

            // Foam Production
            ['slug' => 'create-foam', 'name' => 'إنشاء إنتاج إسفنج', 'module' => 'foam', 'action' => 'create'],
            ['slug' => 'view-foam', 'name' => 'عرض الإسفنج', 'module' => 'foam', 'action' => 'view'],
            ['slug' => 'edit-foam', 'name' => 'تعديل الإسفنج', 'module' => 'foam', 'action' => 'edit'],
            ['slug' => 'grade-foam', 'name' => 'تصنيف الإسفنج', 'module' => 'foam', 'action' => 'grade'],

            // Cutting
            ['slug' => 'create-cutting', 'name' => 'إنشاء عمليات قص', 'module' => 'cutting', 'action' => 'create'],
            ['slug' => 'view-cutting', 'name' => 'عرض القص', 'module' => 'cutting', 'action' => 'view'],
            ['slug' => 'edit-cutting', 'name' => 'تعديل القص', 'module' => 'cutting', 'action' => 'edit'],

            // Furniture
            ['slug' => 'create-furniture', 'name' => 'إنشاء أثاث', 'module' => 'furniture', 'action' => 'create'],
            ['slug' => 'view-furniture', 'name' => 'عرض الأثاث', 'module' => 'furniture', 'action' => 'view'],
            ['slug' => 'edit-furniture', 'name' => 'تعديل الأثاث', 'module' => 'furniture', 'action' => 'edit'],

            // Sales & POS
            ['slug' => 'create-sales', 'name' => 'إنشاء مبيعات', 'module' => 'sales', 'action' => 'create'],
            ['slug' => 'view-sales', 'name' => 'عرض المبيعات', 'module' => 'sales', 'action' => 'view'],
            ['slug' => 'edit-sales', 'name' => 'تعديل المبيعات', 'module' => 'sales', 'action' => 'edit'],
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
                'name' => 'المالك',
                'slug' => 'owner',
                'description' => 'مالك النظام العام بتحكم كامل.',
                'permissions' => ['*'],
            ],
            [
                'name' => 'المدير العام',
                'slug' => 'admin',
                'description' => 'مدير عام بوصول إداري كامل.',
                'permissions' => ['*'],
            ],
            [
                'name' => 'مدير المحاسبة',
                'slug' => 'accounting-manager',
                'description' => 'إدارة الشؤون المالية والمحاسبية.',
                'permissions' => ['settle-payables', 'view-inventory', 'view-procurement', 'view-treasury'],
            ],
            [
                'name' => 'مدير الموارد البشرية',
                'slug' => 'hr-manager',
                'description' => 'إدارة الموارد البشرية وكشوف الرواتب.',
                'permissions' => ['manage-users', 'view-users', 'create-users', 'edit-users'],
            ],
            [
                'name' => 'مدير المشتريات',
                'slug' => 'procurement-manager',
                'description' => 'إدارة المشتريات وعمليات الشراء.',
                'permissions' => ['create-procurement', 'view-procurement', 'edit-procurement', 'view-inventory'],
            ],
            [
                'name' => 'مسؤول الخزانة',
                'slug' => 'treasury-officer',
                'description' => 'إدارة الخزانة والتدفقات النقدية.',
                'permissions' => ['create-treasury', 'view-treasury', 'approve-treasury', 'view-inventory', 'settle-payables'],
            ],
            [
                'name' => 'مدير مصنع الإسفنج',
                'slug' => 'foam-manager',
                'description' => 'إدارة مصنع إنتاج الإسفنج.',
                'permissions' => ['create-foam', 'view-foam', 'edit-foam', 'grade-foam', 'view-inventory'],
            ],
            [
                'name' => 'مشغل الإسفنج',
                'slug' => 'foam-operator',
                'description' => 'مشغل آلات مصنع الإسفنج.',
                'permissions' => ['view-foam', 'edit-foam', 'view-inventory'],
            ],
            [
                'name' => 'مدير قسم القص',
                'slug' => 'cutter-manager',
                'description' => 'إدارة قسم القص.',
                'permissions' => ['create-cutting', 'view-cutting', 'edit-cutting', 'view-inventory'],
            ],
            [
                'name' => 'مشغل القص',
                'slug' => 'cutter-operator',
                'description' => 'مشغل آلة القص.',
                'permissions' => ['view-cutting', 'edit-cutting', 'view-inventory'],
            ],
            [
                'name' => 'مدير قسم الأثاث',
                'slug' => 'furniture-manager',
                'description' => 'إدارة قسم تجميع الأثاث.',
                'permissions' => ['create-furniture', 'view-furniture', 'edit-furniture', 'view-inventory'],
            ],
            [
                'name' => 'مجمع الأثاث',
                'slug' => 'assembler',
                'description' => 'عامل في تجميع الأثاث.',
                'permissions' => ['view-furniture', 'edit-furniture', 'view-inventory'],
            ],
            [
                'name' => 'مدير صالة العرض',
                'slug' => 'store-manager',
                'description' => 'إدارة صالة العرض والمبيعات.',
                'permissions' => ['create-sales', 'view-sales', 'edit-sales', 'view-inventory'],
            ],
            [
                'name' => 'كاشير نقطة البيع',
                'slug' => 'pos-cashier',
                'description' => 'كاشير نقطة البيع.',
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
