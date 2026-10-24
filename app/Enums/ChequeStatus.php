<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 08 Wave 2 / Rule TRS-05: Customer cheque lifecycle statuses.
 */
enum ChequeStatus: string
{
    case InPortfolio = 'in_portfolio';
    case Deposited = 'deposited';
    case Cleared = 'cleared';
    case Bounced = 'bounced';
    case ReturnedToDrawer = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::InPortfolio => 'في الحافظة',
            self::Deposited => 'برسم التحصيل',
            self::Cleared => 'محصّل',
            self::Bounced => 'مرتجع',
            self::ReturnedToDrawer => 'مُعاد للساحب',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::InPortfolio => [self::Deposited, self::Cleared, self::Bounced, self::ReturnedToDrawer],
            self::Deposited => [self::Cleared, self::Bounced],
            self::Bounced => [self::Deposited],
            self::Cleared => [],
            self::ReturnedToDrawer => [],
        };
    }
}
