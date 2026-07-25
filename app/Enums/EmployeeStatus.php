<?php

declare(strict_types=1);

namespace App\Enums;

enum EmployeeStatus: string
{
    case Active = 'active';
    case Terminated = 'terminated';
    case OnLeave = 'on_leave';
}
