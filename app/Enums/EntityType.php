<?php

declare(strict_types=1);

namespace App\Enums;

enum EntityType: string
{
    case Individual = 'individual';
    case Organization = 'organization';
}
