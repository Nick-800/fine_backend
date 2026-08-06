<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentRoute: string
{
    case Bank = 'bank';
    case Market = 'market';
}
