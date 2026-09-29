<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Raised when a sale, quotation, bundle or payment breaks a selling rule.
 *
 * Rendered as 422 with its own machine code (e.g. BUYER_REQUIRED,
 * TREASURY_NOT_LINKED) so the POS can show the message and react to the code.
 */
final class SalesRuleException extends Exception
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }
}
