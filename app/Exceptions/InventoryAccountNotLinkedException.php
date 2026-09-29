<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\InventoryEventType;
use App\Models\InventoryItem;
use Exception;

/**
 * Raised when a posting service tries to consume an accounting event on an
 * inventory item that has no matching `inventory_item_accounts` override.
 *
 * Deliberately fatal to the operation rather than skipped. An event that
 * moves value but posts nothing leaves inventory and the ledger permanently
 * out of step, and nothing downstream would report it — the trial balance
 * would simply be quietly wrong.
 *
 * Surfaces as HTTP 422 with code `INVENTORY_ACCOUNT_NOT_LINKED` at the
 * controller boundary.
 */
final class InventoryAccountNotLinkedException extends Exception
{
    public function __construct(
        public readonly InventoryItem $item,
        public readonly InventoryEventType $event,
    ) {
        parent::__construct(sprintf(
            'Inventory item "%s" (%s) has no chart-of-accounts account linked for event "%s".',
            $item->name,
            $item->code,
            $event->arabicLabel(),
        ));
    }
}
