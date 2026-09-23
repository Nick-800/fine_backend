<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class EnforceDesktopVersion extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:enforce-version
                            {version? : The minimum desktop app version to enforce (e.g. 1.0.25)}
                            {--clear : Clear runtime enforced version and revert to config}
                            {--status : Show currently enforced minimum and latest desktop versions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set or check the minimum desktop application version required for API access';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('clear')) {
            Cache::forget('app:min_desktop_version');
            Cache::forget('app:latest_desktop_version');
            $this->info('Runtime enforced desktop version cleared. Defaulting to configuration.');
            return self::SUCCESS;
        }

        $currentMin = Cache::get('app:min_desktop_version', config('app.min_desktop_version', 'none'));
        $currentLatest = Cache::get('app:latest_desktop_version', config('app.latest_desktop_version', 'none'));

        if ($this->option('status') || ! $this->argument('version')) {
            $this->table(
                ['Setting', 'Version'],
                [
                    ['Enforced Minimum Desktop Version', $currentMin ?: 'none'],
                    ['Latest Desktop Version', $currentLatest ?: 'none'],
                    ['Auto-enforce Latest Build', config('app.auto_enforce_latest_build') ? 'true' : 'false'],
                ]
            );
            return self::SUCCESS;
        }

        $version = trim((string) $this->argument('version'));

        Cache::forever('app:min_desktop_version', $version);
        Cache::forever('app:latest_desktop_version', $version);

        $this->info("Minimum desktop version successfully enforced: {$version}");
        $this->warn('All connected clients running an older version will immediately stop working and receive a forced update prompt.');

        return self::SUCCESS;
    }
}
