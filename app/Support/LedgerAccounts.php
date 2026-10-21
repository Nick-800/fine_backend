<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Standard ledger account codes referenced across accounting operations.
 *
 * ACC-12, ACC-15, BR-CUR-008.
 */
final class LedgerAccounts
{
    public const RETAINED_EARNINGS = '33';

    public const FX_GAIN = '42';

    public const FX_LOSS = '53';
}
