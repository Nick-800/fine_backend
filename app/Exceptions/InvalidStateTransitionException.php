<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Raised when a domain object is pushed into a state its lifecycle does not allow.
 *
 * Rendered as 422: the caller asked for something the workflow forbids.
 */
final class InvalidStateTransitionException extends Exception
{
    //
}
