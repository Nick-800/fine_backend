<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SalesRuleException;
use App\Models\OperatingUnit;
use App\Models\PosDailyClose;
use App\Support\CompanyClock;

/**
 * BR-INV-002 / BR-POS-004: once a unit's register day is closed and
 * reconciled, nothing more may be sold or collected against it — otherwise the
 * drawer count and the ledger drift apart.
 *
 * "Today" is the company's local day, matching how the daily close itself
 * decides which day it closes.
 */
final class PosDayGuard
{
    public const FALLBACK_TIMEZONE = CompanyClock::FALLBACK_TIMEZONE;

    public function timezoneFor(?OperatingUnit $unit): string
    {
        return CompanyClock::timezone($unit);
    }

    public function today(?OperatingUnit $unit): string
    {
        return CompanyClock::today($unit);
    }

    public function isClosed(string $operatingUnitId, string $date): bool
    {
        // Explicit unit filter without the request's unit scope: a collection can
        // be taken for an order whose unit differs from the header's.
        return PosDailyClose::withoutGlobalScopes()
            ->where('operating_unit_id', $operatingUnitId)
            ->whereDate('close_date', $date)
            ->exists();
    }

    /**
     * @throws SalesRuleException when the unit's register is closed for today
     */
    public function ensureOpen(OperatingUnit $unit): void
    {
        if ($this->isClosed($unit->id, $this->today($unit))) {
            throw new SalesRuleException(
                'This register day is already closed. Sales resume tomorrow.',
                'POS_DAY_ALREADY_CLOSED',
            );
        }
    }
}
