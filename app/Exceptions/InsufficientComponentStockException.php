<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Raised when a production order's BOM cannot be covered by available stock.
 *
 * Rendered as 422 naming each short component, so the manager sees what to
 * restock rather than a generic failure. The whole reservation rolls back —
 * a half-reserved order would pin stock for a build that cannot start.
 */
final class InsufficientComponentStockException extends Exception
{
    //
}
