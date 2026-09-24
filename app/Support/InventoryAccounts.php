<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one place item types map to inventory accounts. Intake (StockLotService),
 * sale (SalesOrderService), and adjustment write-off (StockAdjustmentService)
 * must agree, or value enters stock through one account and leaves through
 * another — `finished_good` drifted exactly that way (1134 in, 1110 out)
 * until 2026-08-20.
 */
final class InventoryAccounts
{
    public static function forItemType(?string $itemType): string
    {
        return match ($itemType) {
            'foam_block' => '1131',
            'cut_template_piece', 'slice' => '1132',
            'byproduct_fill' => '1133',
            'furniture_finished_good', 'finished_good' => '1134',
            default => '111', // raw materials, chemicals, containers, fabric…
        };
    }
}
