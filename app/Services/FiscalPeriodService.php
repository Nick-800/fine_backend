<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FiscalPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Exceptions\LedgerRuleException;
use App\Models\Company;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\CompanyClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Locking and reopening accounting months (ACC-11).
 *
 * The accounting manager locks a month once it has ended — never the current
 * one, so live selling is never blocked. Months lock in order. Only the owner
 * reopens a month, with a reason that lands in the audit log, and reopening
 * touches that month alone.
 */
final class FiscalPeriodService
{
    public function __construct(private readonly FiscalCalendar $calendar) {}

    /**
     * @return list<FiscalPeriod> the months locked
     *
     * @throws LedgerRuleException PERIOD_NOT_ENDED, PERIOD_ALREADY_LOCKED, PERIOD_LOCK_OUT_OF_ORDER, YEAR_CLOSED
     */
    public function lock(FiscalPeriod $period, User $user, bool $includeEarlier = false): array
    {
        return DB::transaction(function () use ($period, $user, $includeEarlier): array {
            $period = FiscalPeriod::with('fiscalYear')->lockForUpdate()->findOrFail($period->id);

            if ($period->fiscalYear->status === FiscalYearStatus::Closed) {
                throw new LedgerRuleException("Fiscal year {$period->fiscalYear->name} is closed.", 'YEAR_CLOSED');
            }

            if ($period->status === FiscalPeriodStatus::Locked) {
                throw new LedgerRuleException("{$period->label()} is already locked.", 'PERIOD_ALREADY_LOCKED');
            }

            if ($period->end_date->toDateString() >= CompanyClock::today()) {
                throw new LedgerRuleException(
                    "{$period->label()} has not ended yet; a month can be locked from the day after it ends.",
                    'PERIOD_NOT_ENDED',
                    ['period' => $period->label()],
                );
            }

            $earlierOpen = FiscalPeriod::query()
                ->where('company_id', $period->company_id)
                ->where('status', FiscalPeriodStatus::Open->value)
                ->where('start_date', '<', $period->start_date->toDateString())
                ->whereHas('fiscalYear', fn ($q) => $q->where('status', FiscalYearStatus::Open->value))
                ->orderBy('start_date')
                ->lockForUpdate()
                ->get();

            if ($earlierOpen->isNotEmpty() && ! $includeEarlier) {
                throw new LedgerRuleException(
                    'Earlier months are still open; lock them first or lock up to this month.',
                    'PERIOD_LOCK_OUT_OF_ORDER',
                    ['open_periods' => $earlierOpen->map->label()->all()],
                );
            }

            $locked = [];
            foreach ([...$earlierOpen->all(), $period] as $month) {
                $month->update([
                    'status' => FiscalPeriodStatus::Locked,
                    'locked_at' => now(),
                    'locked_by_user_id' => $user->id,
                ]);
                $locked[] = $month;
            }

            return $locked;
        });
    }

    /**
     * @throws LedgerRuleException YEAR_CLOSED, PERIOD_NOT_LOCKED
     */
    public function reopen(FiscalPeriod $period, User $user, string $reason): FiscalPeriod
    {
        return DB::transaction(function () use ($period, $user, $reason): FiscalPeriod {
            $period = FiscalPeriod::with('fiscalYear')->lockForUpdate()->findOrFail($period->id);

            if ($period->fiscalYear->status === FiscalYearStatus::Closed) {
                throw new LedgerRuleException(
                    "Fiscal year {$period->fiscalYear->name} is closed; reopen the year first.",
                    'YEAR_CLOSED',
                );
            }

            if ($period->status !== FiscalPeriodStatus::Locked) {
                throw new LedgerRuleException("{$period->label()} is not locked.", 'PERIOD_NOT_LOCKED');
            }

            $period->update([
                'status' => FiscalPeriodStatus::Open,
                'reopened_at' => now(),
                'reopened_by_user_id' => $user->id,
                'reopen_reason' => $reason,
            ]);

            return $period->refresh();
        });
    }

    /**
     * Ensure the fiscal year starting in $startYear exists (years are otherwise
     * created on demand when something is first dated inside them).
     */
    public function ensureYear(string $companyId, int $startYear): FiscalYear
    {
        $startMonth = (int) (Company::query()->whereKey($companyId)->value('fiscal_year_start_month') ?: 1);

        return DB::transaction(fn (): FiscalYear => $this->calendar->createYear(
            CarbonImmutable::create($startYear, $startMonth, 1),
            $companyId,
        ));
    }

    /**
     * Change the month fiscal years start in. Only while nothing is locked or
     * closed; the open years are rebuilt on the new boundaries.
     *
     * @throws LedgerRuleException FISCAL_CALENDAR_IN_USE
     */
    public function updateStartMonth(Company $company, int $startMonth): Company
    {
        return DB::transaction(function () use ($company, $startMonth): Company {
            $inUse = FiscalPeriod::query()->where('company_id', $company->id)->where('status', FiscalPeriodStatus::Locked->value)->exists()
                || FiscalYear::query()->where('company_id', $company->id)->where('status', FiscalYearStatus::Closed->value)->exists();

            if ($inUse) {
                throw new LedgerRuleException(
                    'The fiscal start month cannot change once a month is locked or a year is closed.',
                    'FISCAL_CALENDAR_IN_USE',
                );
            }

            $company->update(['fiscal_year_start_month' => $startMonth]);

            // Years are plain containers until something is locked: rebuild them.
            FiscalPeriod::query()->where('company_id', $company->id)->delete();
            FiscalYear::query()->where('company_id', $company->id)->delete();

            return $company->refresh();
        });
    }
}
