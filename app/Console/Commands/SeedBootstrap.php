<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\SystemBootstrapSeeder;
use Illuminate\Console\Command;

/**
 * Idempotent: every record uses a natural unique key (slug, sku, email,
 * order_number) so re-running is a no-op. Use --force when running outside
 * `production` to skip the environment guard.
 */
final class SeedBootstrap extends Command
{
    protected $signature = 'db:seed:bootstrap {--force : Skip the production environment guard}';

    protected $description = 'Seed the minimum data the system needs to function in a semi-production way';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to bootstrap in production. Re-run with --force to override.');

            return self::FAILURE;
        }

        $this->info('Running SystemBootstrapSeeder…');
        $this->newLine();

        $start = microtime(true);
        $this->callSilent(SystemBootstrapSeeder::class);
        $elapsed = round(microtime(true) - $start, 2);

        $this->newLine();
        $this->info("Bootstrap complete in {$elapsed}s.");

        return self::SUCCESS;
    }
}
