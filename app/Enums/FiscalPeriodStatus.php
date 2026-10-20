<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ACC-11: a locked month refuses every posting dated inside it. The
 * accounting manager locks an ended month; only the owner reopens it.
 */
enum FiscalPeriodStatus: string
{
    case Open = 'open';
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'مفتوح',
            self::Locked => 'مقفل',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::Locked],
            self::Locked => [self::Open],
        };
    }
}
