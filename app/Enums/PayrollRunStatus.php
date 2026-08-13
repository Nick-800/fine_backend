<?php

declare(strict_types=1);

namespace App\Enums;

enum PayrollRunStatus: string
{
    case Draft = 'draft';
    case Calculated = 'calculated';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Paid = 'paid';
    case Posted = 'posted';

    /**
     * HR-06: a run passes through every gate before it reaches the ledger.
     * Recalculation keeps a run in Calculated; Posted is terminal.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Calculated],
            self::Calculated => [self::Calculated, self::PendingApproval],
            self::PendingApproval => [self::Approved],
            self::Approved => [self::Paid],
            self::Paid => [self::Posted],
            self::Posted => [],
        };
    }
}
