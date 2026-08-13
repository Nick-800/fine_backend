<?php

declare(strict_types=1);

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Leave = 'leave';
    case HalfDay = 'half_day';

    /**
     * Only time actually on the floor earns hourly pay.
     */
    public function isPayable(): bool
    {
        return match ($this) {
            self::Present, self::HalfDay => true,
            self::Absent, self::Leave => false,
        };
    }
}
