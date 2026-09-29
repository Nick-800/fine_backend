<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * One piece of a sold bundle on its way to the client: defined (Pending),
 * sent to be cut (AtCutter), set aside from stock or produced (Ready), then
 * handed over (Delivered).
 */
enum SaleComponentStatus: string
{
    case Pending = 'pending';
    case AtCutter = 'at_cutter';
    case Ready = 'ready';
    case Delivered = 'delivered';
}
