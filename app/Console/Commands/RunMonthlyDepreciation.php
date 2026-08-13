<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FixedAssetStatus;
use App\Models\FixedAsset;
use App\Services\FixedAssetService;
use Illuminate\Console\Command;
use Throwable;

/**
 * ACC-07: depreciation runs monthly via the scheduler and can be triggered
 * manually. Re-runs are idempotent — an asset that already posted for the
 * period is skipped, so a failed run can simply be run again.
 */
final class RunMonthlyDepreciation extends Command
{
    protected $signature = 'accounting:run-depreciation {--period= : YYYY-MM, defaults to the current month}';

    protected $description = 'Post monthly depreciation for every active fixed asset';

    public function handle(FixedAssetService $assetService): int
    {
        $period = $this->option('period') ?? now()->format('Y-m');

        $posted = 0;
        $skipped = 0;
        $failed = 0;

        $assets = FixedAsset::withoutGlobalScopes()
            ->where('status', FixedAssetStatus::Active)
            ->get();

        foreach ($assets as $asset) {
            try {
                $entry = $assetService->depreciateForPeriod($asset, $period);
                $entry !== null ? $posted++ : $skipped++;
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$asset->asset_code}: {$e->getMessage()}");
                report($e);
            }
        }

        $this->info("Depreciation {$period}: {$posted} posted, {$skipped} skipped, {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
