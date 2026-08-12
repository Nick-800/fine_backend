<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Raised when the X-Operating-Unit-ID header names a unit that does not exist.
 *
 * Usually a client holding a unit id from a database that has since been reseeded
 * or swapped. Carries a machine-readable code so the client can clear the stale
 * value and recover, rather than retrying the same bad header forever.
 */
final class InvalidOperatingUnitException extends Exception
{
    //
}
