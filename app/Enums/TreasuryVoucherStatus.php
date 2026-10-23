<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 08 Wave 2 / TRS-02: Receipt and payment voucher lifecycle statuses.
 */
enum TreasuryVoucherStatus: string
{
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'مرحّل',
            self::Cancelled => 'ملغى',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Posted => [self::Cancelled],
            self::Cancelled => [],
        };
    }
}
