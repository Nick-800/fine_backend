<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FiscalPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Exceptions\LedgerRuleException;
use App\Models\FiscalPeriod;

/**
 * ACC-11: nothing posts into a locked month or a closed fiscal year — manual
 * entries, automatic postings and reversals alike. Called from the ledger's
 * single write path before any row is written, inside the caller's
 * transaction, so a refusal rolls back the whole business operation.
 */
final class PeriodGuard
{
    public function __construct(private readonly FiscalCalendar $calendar) {}

    /**
     * @param  string|null  $closingYearId  the year a closing entry (or its reversal) belongs to; that
     *                                      entry alone may post past the year's own locks (ACC-12)
     *
     * @throws LedgerRuleException PERIOD_LOCKED, YEAR_CLOSED, ENTRY_DATE_OUT_OF_RANGE
     */
    public function ensurePostable(string $entryDate, string $companyId, ?string $closingYearId = null): FiscalPeriod
    {
        $period = $this->calendar->periodFor($entryDate, $companyId);
        $year = $period->fiscalYear;

        if ($closingYearId !== null && $closingYearId === $year->id) {
            return $period;
        }

        if ($year->status === FiscalYearStatus::Closed) {
            throw new LedgerRuleException(
                "Fiscal year {$year->name} is closed; nothing can be posted on {$entryDate}.",
                'YEAR_CLOSED',
                ['fiscal_year' => $year->name, 'entry_date' => $entryDate],
            );
        }

        if ($period->status === FiscalPeriodStatus::Locked) {
            throw new LedgerRuleException(
                "The accounting month {$period->label()} is locked; nothing can be posted on {$entryDate}.",
                'PERIOD_LOCKED',
                ['period' => $period->label(), 'entry_date' => $entryDate],
            );
        }

        return $period;
    }
}
