<?php

declare(strict_types=1);

namespace App\Enums;

enum ClientStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Blacklisted = 'blacklisted';
}
