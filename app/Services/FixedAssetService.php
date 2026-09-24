<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DepreciationMethod;
use App\Enums\FixedAssetStatus;
use App\Enums\OverheadPaymentSource;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Phase 08 §8.6. Acquisition capitalises the asset, monthly runs move value
 * from the asset into depreciation expense (ACC-07, idempotent per period),
 * and disposal settles the remaining book value against proceeds (ACC-08).
 */
final class FixedAssetService
{
    public function __construct(
        private readonly AccountingService $accountingService,
    ) {}

    /**
     * @param array{company_id: string, operating_unit_id?: string|null, name: string,
     *     asset_code: string, acquisition_cost: float, acquisition_date: string,
     *     depreciation_method: string, useful_life_years: int, salvage_value?: float,
     *     payment_source: string} $data
     */
    public function acquire(array $data): FixedAsset
    {
        if (($data['salvage_value'] ?? 0) >= $data['acquisition_cost']) {
            throw new InvalidArgumentException('Salvage value must be below the acquisition cost.');
        }

        return DB::transaction(function () use ($data) {
            $asset = FixedAsset::create([
                'company_id' => $data['company_id'],
                'operating_unit_id' => $data['operating_unit_id'] ?? null,
                'name' => $data['name'],
                'asset_code' => $data['asset_code'],
                'acquisition_cost' => $data['acquisition_cost'],
                'acquisition_date' => $data['acquisition_date'],
                'depreciation_method' => $data['depreciation_method'],
                'useful_life_years' => $data['useful_life_years'],
                'salvage_value' => $data['salvage_value'] ?? 0,
                'status' => FixedAssetStatus::Active,
            ]);

            $source = OverheadPaymentSource::from($data['payment_source']);

            $this->accountingService->postJournal(
                "Asset acquired: {$asset->name} ({$asset->asset_code})",
                [
                    [
                        'account_code' => '14', // Fixed Assets
                        'debit' => (float) $asset->acquisition_cost,
                        'operating_unit_id' => $asset->operating_unit_id,
                        'memo' => $asset->name,
                    ],
                    [
                        'account_code' => $source->accountCode(),
                        'credit' => (float) $asset->acquisition_cost,
                        'operating_unit_id' => $asset->operating_unit_id,
                    ],
                ],
                'FixedAsset',
                $asset->id,
                $asset->company_id,
            );

            return $asset->refresh();
        });
    }

    /**
     * Post one month of depreciation. Returns null when there is nothing to
     * post — already run for this period, or the asset is written down to
     * salvage — so scheduled re-runs are safe (ACC-07).
     */
    public function depreciateForPeriod(FixedAsset $asset, ?string $period = null): ?DepreciationEntry
    {
        $period ??= now()->format('Y-m');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new InvalidArgumentException("Period must be YYYY-MM, got \"{$period}\".");
        }

        return DB::transaction(function () use ($asset, $period) {
            $asset = FixedAsset::withoutGlobalScopes()->lockForUpdate()->findOrFail($asset->id);

            if ($asset->status === FixedAssetStatus::Disposed) {
                throw new InvalidStateTransitionException('A disposed asset cannot depreciate.');
            }

            // §8.6: maintenance pauses depreciation (v1 policy).
            if ($asset->status === FixedAssetStatus::UnderMaintenance) {
                return null;
            }

            if (Carbon::createFromFormat('Y-m', $period)->endOfMonth()->lessThan($asset->acquisition_date)) {
                throw new InvalidArgumentException('Cannot depreciate a period before the asset was acquired.');
            }

            if ($asset->depreciationEntries()->where('period', $period)->exists()) {
                return null;
            }

            $amount = $this->monthlyAmount($asset);

            if ($amount <= 0.0) {
                return null;
            }

            $entry = $asset->depreciationEntries()->create([
                'period' => $period,
                'amount' => $amount,
                'book_value_after' => round($asset->bookValue() - $amount, 4),
            ]);

            $asset->accumulated_depreciation = round((float) $asset->accumulated_depreciation + $amount, 4);
            $asset->save();

            $this->accountingService->postJournal(
                "Depreciation {$period}: {$asset->name}",
                [
                    [
                        'account_code' => '55', // Depreciation Expense
                        'debit' => $amount,
                        'operating_unit_id' => $asset->operating_unit_id,
                        'memo' => $asset->asset_code,
                    ],
                    [
                        'account_code' => '145', // Accumulated Depreciation
                        'credit' => $amount,
                        'operating_unit_id' => $asset->operating_unit_id,
                    ],
                ],
                'DepreciationEntry',
                $entry->id,
                $asset->company_id,
            );

            return $entry;
        });
    }

    /**
     * ACC-08: gain/loss = proceeds - remaining book value.
     */
    public function dispose(FixedAsset $asset, float $proceeds, ?string $disposedAt = null): FixedAsset
    {
        if ($proceeds < 0) {
            throw new InvalidArgumentException('Disposal proceeds cannot be negative.');
        }

        return DB::transaction(function () use ($asset, $proceeds, $disposedAt) {
            $asset = FixedAsset::withoutGlobalScopes()->lockForUpdate()->findOrFail($asset->id);

            if (! in_array(FixedAssetStatus::Disposed, $asset->status->allowedNext(), true)) {
                throw new InvalidStateTransitionException('This asset has already been disposed.');
            }

            $bookValue = $asset->bookValue();
            $result = round($proceeds - $bookValue, 4);

            $lines = [];

            if ($proceeds > 0) {
                $lines[] = [
                    'account_code' => '12', // Cash and Bank
                    'debit' => round($proceeds, 4),
                    'operating_unit_id' => $asset->operating_unit_id,
                    'memo' => 'disposal proceeds',
                ];
            }

            if ((float) $asset->accumulated_depreciation > 0) {
                $lines[] = [
                    'account_code' => '145', // Accumulated Depreciation
                    'debit' => (float) $asset->accumulated_depreciation,
                    'operating_unit_id' => $asset->operating_unit_id,
                ];
            }

            if ($result < 0) {
                $lines[] = [
                    'account_code' => '56', // Loss on Asset Disposal
                    'debit' => -$result,
                    'operating_unit_id' => $asset->operating_unit_id,
                    'memo' => $asset->asset_code,
                ];
            }

            $lines[] = [
                'account_code' => '14', // Fixed Assets (original cost out)
                'credit' => (float) $asset->acquisition_cost,
                'operating_unit_id' => $asset->operating_unit_id,
                'memo' => $asset->name,
            ];

            if ($result > 0) {
                $lines[] = [
                    'account_code' => '43', // Gain on Asset Disposal
                    'credit' => $result,
                    'operating_unit_id' => $asset->operating_unit_id,
                    'memo' => $asset->asset_code,
                ];
            }

            $this->accountingService->postJournal(
                "Asset disposed: {$asset->name} ({$asset->asset_code})",
                $lines,
                'FixedAsset',
                $asset->id,
                $asset->company_id,
            );

            $asset->update([
                'status' => FixedAssetStatus::Disposed,
                'disposal_proceeds' => round($proceeds, 4),
                'disposed_at' => $disposedAt ?? now()->toDateString(),
            ]);

            return $asset->refresh();
        });
    }

    public function transition(FixedAsset $asset, FixedAssetStatus $target): FixedAsset
    {
        if ($target === FixedAssetStatus::Disposed) {
            throw new InvalidArgumentException('Disposal goes through dispose() so the ledger is settled.');
        }

        if (! in_array($target, $asset->status->allowedNext(), true)) {
            throw new InvalidStateTransitionException(
                "Asset cannot move from {$asset->status->value} to {$target->value}."
            );
        }

        $asset->update(['status' => $target]);

        return $asset->refresh();
    }

    /**
     * Projected monthly schedule until the asset reaches salvage value,
     * starting from its current state.
     *
     * @return list<array{period: string, amount: float, book_value_after: float}>
     */
    public function schedule(FixedAsset $asset): array
    {
        $rows = [];
        $bookValue = $asset->bookValue();
        $lastPeriod = $asset->depreciationEntries()->max('period');
        $period = $lastPeriod !== null
            ? Carbon::createFromFormat('Y-m', $lastPeriod)
            : Carbon::createFromFormat('Y-m', $asset->acquisition_date->format('Y-m'))->subMonth();
        $maxMonths = $asset->useful_life_years * 12;

        for ($i = 0; $i < $maxMonths; $i++) {
            $amount = $this->monthlyAmountFor($asset->depreciation_method, (float) $asset->acquisition_cost, (float) $asset->salvage_value, $asset->useful_life_years, $bookValue);

            if ($amount <= 0.0) {
                break;
            }

            $period = $period->copy()->addMonth();
            $bookValue = round($bookValue - $amount, 4);

            $rows[] = [
                'period' => $period->format('Y-m'),
                'amount' => $amount,
                'book_value_after' => $bookValue,
            ];
        }

        return $rows;
    }

    private function monthlyAmount(FixedAsset $asset): float
    {
        return $this->monthlyAmountFor(
            $asset->depreciation_method,
            (float) $asset->acquisition_cost,
            (float) $asset->salvage_value,
            $asset->useful_life_years,
            $asset->bookValue(),
        );
    }

    /**
     * §8.6 formulas, floored so book value never sinks below salvage.
     */
    private function monthlyAmountFor(
        DepreciationMethod $method,
        float $cost,
        float $salvage,
        int $lifeYears,
        float $bookValue,
    ): float {
        $headroom = round($bookValue - $salvage, 4);

        if ($headroom <= 0.0) {
            return 0.0;
        }

        $monthly = match ($method) {
            DepreciationMethod::StraightLine => ($cost - $salvage) / ($lifeYears * 12),
            DepreciationMethod::DecliningBalance => $bookValue * (2 / $lifeYears) / 12,
        };

        return round(min($monthly, $headroom), 4);
    }
}
