<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What produced a journal entry. Automatic entries come from business
 * events (ACC-02); the manual family is posted by an accountant (ACC-04);
 * closing entries come from the year-end close (ACC-12) and reversals
 * mirror an earlier entry (ACC-13).
 */
enum JournalEntryType: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
    case Adjustment = 'adjustment';
    case Opening = 'opening';
    case Closing = 'closing';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'تلقائي',
            self::Manual => 'يدوي',
            self::Adjustment => 'تسوية',
            self::Opening => 'افتتاحي',
            self::Closing => 'إقفال',
            self::Reversal => 'عكسي',
        };
    }

    /**
     * Types an accountant may post by hand.
     */
    public function isManualFamily(): bool
    {
        return in_array($this, [self::Manual, self::Adjustment, self::Opening], true);
    }

    /**
     * @return list<string>
     */
    public static function manualValues(): array
    {
        return [self::Manual->value, self::Adjustment->value, self::Opening->value];
    }
}
