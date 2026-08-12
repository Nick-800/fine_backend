<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Raised when a journal entry's debits do not equal its credits (ACC-01).
 *
 * Rendered as 422. This is the one rule the whole ledger rests on: an unbalanced
 * entry corrupts the trial balance permanently and is far harder to find later
 * than to reject now.
 */
final class UnbalancedJournalException extends Exception
{
    //
}
