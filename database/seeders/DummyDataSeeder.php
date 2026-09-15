<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Dummy\EntitiesSeeder;
use Database\Seeders\Dummy\FixedAssetsSeeder;
use Database\Seeders\Dummy\HrSeeder;
use Database\Seeders\Dummy\InventoryAndBomSeeder;
use Database\Seeders\Dummy\OverheadSeeder;
use Database\Seeders\Dummy\PayrollSeeder;
use Database\Seeders\Dummy\ProcurementTreasurySeeder;
use Database\Seeders\Dummy\ProductionSeeder;
use Database\Seeders\Dummy\SalesSeeder;
use Illuminate\Database\Seeder;

/**
 * Seeds moderate-volume dummy data across every module. Assumes the system
 * bootstrap has already run (company, roles, units, master data).
 *
 * Modules run in an order that respects their foreign-key dependencies:
 *   entities → inventory/BOM → production → sales → procurement → overhead
 *   → fixed assets → payroll → HR.
 */
class DummyDataSeeder extends Seeder
{
    /**
     * Map of available scopes to their seeder classes.
     *
     * @var array<string, class-string<Seeder>>
     */
    public const SCOPES = [
        'entities' => EntitiesSeeder::class,
        'inventory' => InventoryAndBomSeeder::class,
        'production' => ProductionSeeder::class,
        'sales' => SalesSeeder::class,
        'procurement' => ProcurementTreasurySeeder::class,
        'overhead' => OverheadSeeder::class,
        'assets' => FixedAssetsSeeder::class,
        'payroll' => PayrollSeeder::class,
        'hr' => HrSeeder::class,
    ];

    /**
     * Run all dummy sub-seeders in dependency order.
     *
     * @param  string[]|null  $scopes  When non-empty, run only these scopes.
     */
    public function run(?array $scopes = null): void
    {
        $order = ['entities', 'inventory', 'production', 'sales', 'procurement', 'overhead', 'assets', 'payroll', 'hr'];

        if ($scopes !== null && $scopes !== []) {
            $invalid = array_diff($scopes, array_keys(self::SCOPES));
            if ($invalid !== []) {
                throw new \InvalidArgumentException(
                    'Unknown dummy seeder scopes: '.implode(', ', $invalid)
                    .'. Valid scopes: '.implode(', ', array_keys(self::SCOPES))
                );
            }

            $order = array_values(array_intersect($order, $scopes));
        }

        foreach ($order as $scope) {
            $seederClass = self::SCOPES[$scope];
            $this->command?->info("  → {$scope}: ".class_basename($seederClass));
            $this->call($seederClass);
        }
    }
}
