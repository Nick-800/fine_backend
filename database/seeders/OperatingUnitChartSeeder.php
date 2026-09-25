<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Console\Commands\SeedExcelCoa;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Seeder wrapper for the `coa:seed-excel` Artisan command.
 *
 * Seeds per-unit charts of accounts for foam factory and cutter units
 * extracted from Excel source documents under `database/seeders/data/`.
 */
final class OperatingUnitChartSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call(SeedExcelCoa::class, [
            '--unit' => 'all',
            '--force' => true,
        ], $this->command?->getOutput());
    }
}
