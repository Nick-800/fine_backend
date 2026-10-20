<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FiscalPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Exceptions\LedgerRuleException;
use App\Models\Company;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Support\CompanyClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fiscal years of twelve calendar months starting on the company's fiscal
 * start month (FR-GL-044). A year and its months are created the first time
 * anything is dated inside it, so the calendar never has to be prepared by
 * hand and a fresh install posts straight away.
 */
final class FiscalCalendar
{
    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public static function yearBounds(CarbonImmutable $date, int $startMonth): array
    {
        $startYear = $date->month >= $startMonth ? $date->year : $date->year - 1;
        $start = CarbonImmutable::create($startYear, $startMonth, 1)->startOfDay();

        return ['start' => $start, 'end' => $start->addYear()->subDay()];
    }

    /**
     * The month the date falls in, creating its fiscal year if needed.
     *
     * @throws LedgerRuleException ENTRY_DATE_OUT_OF_RANGE, YEAR_CLOSED
     */
    public function periodFor(string $date, string $companyId): FiscalPeriod
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        $period = $this->findPeriod($day, $companyId);
        if ($period !== null) {
            return $period;
        }

        if ($day->gt(CompanyClock::now()->startOfDay()->addYear())) {
            throw new LedgerRuleException(
                "{$day->toDateString()} is more than a year ahead; check the date.",
                'ENTRY_DATE_OUT_OF_RANGE',
                ['entry_date' => $day->toDateString()],
            );
        }

        $this->createYear($day, $companyId);

        return $this->findPeriod($day, $companyId)
            ?? throw new LedgerRuleException('The fiscal calendar could not be prepared for this date.', 'FISCAL_CALENDAR_ERROR');
    }

    /**
     * Create the open fiscal year containing $day with its twelve months. Safe
     * under a race: the unique keys make a second insert a no-op.
     *
     * @throws LedgerRuleException YEAR_CLOSED when the year would sit before a closed one
     */
    public function createYear(CarbonImmutable $day, string $companyId): FiscalYear
    {
        $startMonth = (int) (Company::query()->whereKey($companyId)->value('fiscal_year_start_month') ?: 1);
        ['start' => $start, 'end' => $end] = self::yearBounds($day, $startMonth);

        // Years close in order, so anything before a closed year is closed too.
        $laterClosed = FiscalYear::query()
            ->where('company_id', $companyId)
            ->where('status', FiscalYearStatus::Closed->value)
            ->where('start_date', '>', $start->toDateString())
            ->exists();

        if ($laterClosed) {
            throw new LedgerRuleException(
                "The fiscal year containing {$day->toDateString()} is closed.",
                'YEAR_CLOSED',
                ['entry_date' => $day->toDateString()],
            );
        }

        $code = $startMonth === 1 ? "FY{$start->year}" : "FY{$start->year}-".($start->year + 1);
        $name = $startMonth === 1 ? (string) $start->year : $start->year.'/'.($start->year + 1);
        $now = now();

        DB::table('fiscal_years')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'code' => $code,
            'name' => $name,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'status' => FiscalYearStatus::Open->value,
            'record_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $year = FiscalYear::query()->where('company_id', $companyId)->where('code', $code)->firstOrFail();

        $rows = [];
        for ($n = 0; $n < 12; $n++) {
            $monthStart = $start->addMonths($n);
            $rows[] = [
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'fiscal_year_id' => $year->id,
                'period_number' => $n + 1,
                'calendar_year' => $monthStart->year,
                'calendar_month' => $monthStart->month,
                'start_date' => $monthStart->toDateString(),
                'end_date' => $monthStart->endOfMonth()->toDateString(),
                'status' => FiscalPeriodStatus::Open->value,
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('fiscal_periods')->insertOrIgnore($rows);

        return $year;
    }

    private function findPeriod(CarbonImmutable $day, string $companyId): ?FiscalPeriod
    {
        return FiscalPeriod::query()
            ->with('fiscalYear')
            ->where('company_id', $companyId)
            ->where('calendar_year', $day->year)
            ->where('calendar_month', $day->month)
            ->first();
    }
}
