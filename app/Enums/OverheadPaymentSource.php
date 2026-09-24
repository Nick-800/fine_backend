<?php

declare(strict_types=1);

namespace App\Enums;

enum OverheadPaymentSource: string
{
    case Cash = 'cash';
    case Payable = 'payable';

    public function accountCode(): string
    {
        return match ($this) {
            self::Cash => '12',    // Cash and Bank
            self::Payable => '21', // Accounts Payable
        };
    }
}
