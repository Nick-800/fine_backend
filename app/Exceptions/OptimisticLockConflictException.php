<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class OptimisticLockConflictException extends RuntimeException
{
    public function __construct(string $message = 'The record has been updated by another user.')
    {
        parent::__construct($message);
    }
}
