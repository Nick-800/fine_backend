<?php

declare(strict_types=1);

namespace Database\Seeders\Dummy;

use App\Enums\DepreciationMethod;
use App\Enums\FixedAssetStatus;
use App\Models\Company;
use App\Models\FixedAsset;
use App\Models\OperatingUnit;
use App\Services\FixedAssetService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Three fixed assets spread across units and depreciation methods, with
 * three months of depreciation entries driven through FixedAssetService
 * so ACC-07 accounting and idempotency are exercised.
 */
class FixedAssetsSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();
        if ($company === null) {
            return;
        }

        $units = OperatingUnit::orderBy('name')->get();
        $service = app(FixedAssetService::class);

        $specs = [
            ['name' => 'Foam Line Conveyor B', 'code' => 'FA-CONV-B', 'cost' => 180_000, 'method' => DepreciationMethod::StraightLine, 'years' => 10, 'unit_key' => 'foam'],
            ['name' => 'Cutter CNC Plasma', 'code' => 'FA-CNC-PLASMA', 'cost' => 320_000, 'method' => DepreciationMethod::StraightLine, 'years' => 7, 'unit_key' => 'cutter'],
            ['name' => 'Delivery Truck', 'code' => 'FA-TRUCK-01', 'cost' => 95_000, 'method' => DepreciationMethod::StraightLine, 'years' => 5, 'unit_key' => 'showroom'],
            ['name' => 'Forklift', 'code' => 'FA-FORK-01', 'cost' => 65_000, 'method' => DepreciationMethod::StraightLine, 'years' => 7, 'unit_key' => 'furniture'],
            ['name' => 'Office Generator', 'code' => 'FA-GEN-01', 'cost' => 22_000, 'method' => DepreciationMethod::StraightLine, 'years' => 5, 'unit_key' => 'procurement'],
        ];

        foreach ($specs as $spec) {
            $unit = $units->firstWhere('name', 'like', '%'.ucfirst($spec['unit_key']).'%')
                ?? $units->firstWhere('name', 'like', '%'.ucfirst(substr($spec['unit_key'], 0, 5)).'%')
                ?? $units->first();
            if ($unit === null) {
                continue;
            }

            $asset = FixedAsset::firstOrCreate(
                ['asset_code' => $spec['code']],
                [
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'operating_unit_id' => $unit->id,
                    'name' => $spec['name'],
                    'acquisition_cost' => $spec['cost'],
                    'acquisition_date' => now()->subYears(2)->subMonths(2)->toDateString(),
                    'depreciation_method' => $spec['method'],
                    'useful_life_years' => $spec['years'],
                    'salvage_value' => $spec['cost'] * 0.05,
                    'status' => FixedAssetStatus::Active,
                ],
            );

            // Drive depreciation for the last 3 months through the service so
            // both the asset's denormalised running total and the journal
            // entry are produced (ACC-07 idempotent re-runs).
            $months = [
                now()->subMonths(3)->format('Y-m'),
                now()->subMonths(2)->format('Y-m'),
                now()->subMonths(1)->format('Y-m'),
            ];

            foreach ($months as $period) {
                try {
                    $service->depreciateForPeriod($asset, $period);
                } catch (\Throwable $e) {
                    // idempotent: skip if already posted for the period
                }
            }
        }

        // Suppress unused variable (Str kept for symmetry with other seeders)
        unset($service);
    }
}
