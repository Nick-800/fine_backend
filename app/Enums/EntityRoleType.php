<?php

declare(strict_types=1);

namespace App\Enums;

enum EntityRoleType: string
{
    case Employee = 'employee';
    case Client = 'client';
    case Vendor = 'vendor';
}
