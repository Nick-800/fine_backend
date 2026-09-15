<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\Role;
use Database\Seeders\DummyDataSeeder;
use Database\Seeders\SystemBootstrapSeeder;
use Illuminate\Console\Command;

/**
 * Runs DummyDataSeeder on top of an already-bootstrapped database.
 *
 *   --reset      runs `migrate:fresh` first
 *   --scope=X,Y  runs only those sub-seeders (see DummyDataSeeder::SCOPES)
 *
 * The seeder does not delete existing data, so it can be re-run after the
 * bootstrap to top up rows without losing previously seeded records.
 */
final class SeedDummy extends Command
{
    protected $signature = 'db:seed:dummy
        {--reset : Run migrate:fresh before seeding (drops all tables first)}
        {--scope= : Comma-separated list of scopes to seed (default: all)}';

    protected $description = 'Seed moderate-volume dummy data across every module (requires bootstrap first)';

    public function handle(): int
    {
        if ($this->option('reset')) {
            $this->warn('Resetting database via migrate:fresh…');
            $this->call('migrate:fresh', ['--seed' => false, '--force' => true]);
            $this->newLine();
            $this->info('Re-running bootstrap on the fresh database…');
            $this->callSilent(SystemBootstrapSeeder::class);
        }

        $this->guardBootstrapPresent();

        $scopes = $this->parseScopes();

        $this->info('Running DummyDataSeeder'.($scopes === null ? '' : ' (scope: '.implode(', ', $scopes).')').'…');
        $this->newLine();

        $start = microtime(true);

        // We can't pass arguments to a Seeder invoked via $this->call(),
        // so instantiate and run directly when scopes are restricted.
        $seeder = app(DummyDataSeeder::class);
        $seeder->setCommand($this);
        $seeder->run($scopes);

        $elapsed = round(microtime(true) - $start, 2);
        $this->newLine();
        $this->info("Dummy seed complete in {$elapsed}s.");

        return self::SUCCESS;
    }

    /**
     * Refuse to run when the most basic bootstrap records are missing.
     */
    private function guardBootstrapPresent(): void
    {
        $missing = [];

        if (Company::count() === 0) {
            $missing[] = 'Company';
        }
        if (OperatingUnit::count() === 0) {
            $missing[] = 'OperatingUnit';
        }
        if (Role::count() === 0) {
            $missing[] = 'Role';
        }
        if (Account::count() === 0) {
            $missing[] = 'ChartOfAccounts';
        }

        if ($missing !== []) {
            $this->error('Bootstrap data is missing: '.implode(', ', $missing));
            $this->line('Run `php artisan db:seed:bootstrap` first (or use --reset).');

            exit(self::FAILURE);
        }
    }

    /**
     * @return list<string>|null
     */
    private function parseScopes(): ?array
    {
        $raw = $this->option('scope');
        if ($raw === null || $raw === '') {
            return null;
        }

        $scopes = array_values(array_filter(array_map('trim', explode(',', (string) $raw))));

        return $scopes === [] ? null : $scopes;
    }
}
