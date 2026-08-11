<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Raised when a serialized item is given a quantity other than 1 (INV-02).
 *
 * Rendered as 422 rather than 500: it is a rule the caller broke, not a fault.
 */
final class SerializedQuantityException extends Exception
{
    //
}
