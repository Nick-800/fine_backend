<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Raised when a journal references an account code the chart of accounts lacks.
 *
 * Deliberately fatal to the operation rather than skipped. An event that moves
 * value but posts nothing leaves inventory and the ledger permanently out of
 * step, and nothing downstream would report it — the trial balance would simply
 * be quietly wrong.
 */
final class MissingAccountException extends Exception
{
    //
}
