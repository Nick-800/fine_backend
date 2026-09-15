<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\ProductionSeeder;
use Illuminate\Console\Command;

/**
 * Production-ready minimal seeder. Seeds only the structural backbone:
 * company, roles, blueprints, operating units, and the owner user.
 *
 *   php artisan db:seed:prod              # production environment only
 *   php artisan db:seed:prod --force      # local / staging override
 *
 * Idempotent — every record uses a natural unique key, so re-running on an
 * already-seeded database is a no-op.
 *
 * The environment guard is inverted from `db:seed:bootstrap`: this command
 * refuses outside `production` (because it writes structural data that may
 * not match the developer's local environment), whereas the bootstrap
 * refuses inside `production` (because it includes demo data).
 */
final class SeedProd extends Command
{
    protected $signature = 'db:seed:prod {--force : Allow running in non-production environments}';

    protected $description = 'Seed the structural backbone (company, roles, units, owner) for production';

    public function handle(): int
    {
        if (! app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to run outside the production environment. Re-run with --force to override.');

            return self::FAILURE;
        }

        $this->info('Running ProductionSeeder…');
        $this->newLine();

        $start = microtime(true);
        $this->callSilent(ProductionSeeder::class);
        $elapsed = round(microtime(true) - $start, 2);

        $this->newLine();
        $this->info("Production seed complete in {$elapsed}s.");

        return self::SUCCESS;
    }
}
