<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Raised when an operation breaks a ledger rule (ACC-11 to ACC-16): a locked
 * period, an immutable entry, a frozen account, a reversal that isn't allowed.
 *
 * Rendered as 422 with its own machine code plus any context keys, so every
 * posting screen can show a precise message. Deliberately not an
 * InvalidArgumentException: the manual-entry endpoint maps those to
 * INVALID_JOURNAL and would hide the code.
 */
final class LedgerRuleException extends Exception
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, public readonly string $errorCode, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
