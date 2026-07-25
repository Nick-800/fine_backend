<?php

declare(strict_types=1);

namespace App\Enums;

enum PayType: string
{
    case Hourly = 'hourly';
    case Monthly = 'monthly';
    case PieceRate = 'piece_rate';
}
