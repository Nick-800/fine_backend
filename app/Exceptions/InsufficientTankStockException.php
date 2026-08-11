<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Raised when a foam run would draw more chemical than the tanks hold (FOAM-02).
 *
 * Rendered as 422 so the operator sees which chemical is short rather than a
 * generic failure.
 */
final class InsufficientTankStockException extends Exception
{
    //
}
