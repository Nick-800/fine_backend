<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * FR-GL-002 / ACC-14: a frozen account keeps its history and balance but
 * refuses new postings and new links. Freezing a header freezes its subtree.
 */
enum AccountStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'نشط',
            self::Frozen => 'مجمّد',
        };
    }
}
