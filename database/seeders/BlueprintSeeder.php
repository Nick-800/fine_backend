<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\UnitBlueprint;
use Illuminate\Database\Seeder;

class BlueprintSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
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

        foreach ($blueprints as $bp) {
            UnitBlueprint::firstOrCreate(
                ['name' => $bp['name']],
                $bp
            );
        }
    }
}
