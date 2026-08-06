<?php

declare(strict_types=1);

namespace App\Enums;

enum LandedCostType: string
{
    case SupplierPrice = 'supplier_price';
    case FxSpread = 'fx_spread';
    case Customs = 'customs';
    case Freight = 'freight';
    case LocalTransport = 'local_transport';
    case Other = 'other';
}
