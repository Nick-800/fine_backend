<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Company;
use App\Models\OperatingUnit;
use Carbon\CarbonImmutable;

/**
 * The company's local date. The server runs on UTC while the company works on
 * Tripoli time, so "today" taken from the server clock would date anything
 * done between midnight and 02:00 local on the previous day — and, with
 * monthly locks (ACC-11), into a month that may already be closed.
 */
final class CompanyClock
{
    public const FALLBACK_TIMEZONE = 'Africa/Tripoli';

    public static function timezone(?OperatingUnit $unit = null): string
    {
        return $unit?->company?->timezone
            ?? Company::query()->value('timezone')
            ?? self::FALLBACK_TIMEZONE;
    }

    public static function now(?OperatingUnit $unit = null): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone($unit));
    }

    public static function today(?OperatingUnit $unit = null): string
    {
        return self::now($unit)->toDateString();
    }
}
