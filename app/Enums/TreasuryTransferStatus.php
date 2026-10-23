<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 08 Wave 2 / TRS-03: Treasury transfer lifecycle statuses.
 */
enum TreasuryTransferStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'بانتظار الاعتماد',
            self::Approved => 'معتمد',
            self::Rejected => 'مرفوض',
            self::Cancelled => 'ملغى',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [],
            self::Rejected => [],
            self::Cancelled => [],
        };
    }
}
