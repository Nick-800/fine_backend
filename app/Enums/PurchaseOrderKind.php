<?php

declare(strict_types=1);

namespace App\Enums;

enum PurchaseOrderKind: string
{
    case Foreign = 'foreign';
    case Local = 'local';
}
