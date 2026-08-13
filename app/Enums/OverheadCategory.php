<?php

declare(strict_types=1);

namespace App\Enums;

enum OverheadCategory: string
{
    case Water = 'water';
    case Electricity = 'electricity';
    case Rent = 'rent';
    case Maintenance = 'maintenance';
    case Other = 'other';
}
