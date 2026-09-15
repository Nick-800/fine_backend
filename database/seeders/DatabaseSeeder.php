<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Thin dispatcher. The default `migrate:fresh --seed` flow now boots straight
 * into a semi-production-ready system.
 *
 *   php artisan migrate:fresh --seed
 *     → SystemBootstrapSeeder (idempotent semi-production data)
 *
 * For richer data on top:
 *
 *   php artisan db:seed:dummy
 *   php artisan db:seed:dummy --reset
 *   php artisan db:seed:dummy --scope=production,sales
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SystemBootstrapSeeder::class);
    }
}
