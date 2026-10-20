<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ACC-11 / ACC-12: an open year takes postings in its unlocked months; a
 * closed year takes none. Only the owner reopens a closed year.
 */
enum FiscalYearStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'مفتوحة',
            self::Closed => 'مقفلة',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::Closed],
            self::Closed => [self::Open],
        };
    }
}
