<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far a sale's goods have got to the buyer. Plain item lines leave stock
 * at checkout, so a sale without bundles is Delivered on the spot. A bundle
 * waits for its pieces to be defined (AwaitingDefinition), taken from stock
 * or cut (InProgress), all set aside (Ready), then handed over (Delivered).
 */
enum SaleFulfillmentStatus: string
{
    case AwaitingDefinition = 'awaiting_definition';
    case InProgress = 'in_progress';
    case Ready = 'ready';
    case Delivered = 'delivered';
}
