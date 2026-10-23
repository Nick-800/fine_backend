<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 08 Wave 2 / TRS-02: Receipt or payment voucher kind.
 */
enum TreasuryVoucherKind: string
{
    case Receipt = 'receipt';
    case Payment = 'payment';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'سند قبض',
            self::Payment => 'سند صرف',
        };
    }
}
