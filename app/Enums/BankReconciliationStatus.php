<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 08 Wave 2 / TRS-04: Bank reconciliation lifecycle statuses.
 */
enum BankReconciliationStatus: string
{
    case Draft = 'draft';
    case Finished = 'finished';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Finished => 'منتهية',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Finished],
            self::Finished => [],
        };
    }
}
