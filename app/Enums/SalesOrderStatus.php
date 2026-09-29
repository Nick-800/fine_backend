<?php

declare(strict_types=1);

namespace App\Enums;

enum SalesOrderStatus: string
{
    /** A receivable sale over the client's credit limit, waiting on a manager (SALE-02). */
    case PendingApproval = 'pending_approval';

    /** Sold: stock and ledger have moved; payment or delivery may still be outstanding. */
    case Open = 'open';

    /** Fully paid and fully delivered. Internal transfers land here at checkout. */
    case Completed = 'completed';

    case Rejected = 'rejected';

    /*
     * Legacy states from the retired draft → submit → fulfill → pay flow.
     * Kept so historical rows still load; no code path moves an order into them.
     */
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Fulfilled = 'fulfilled';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    /**
     * There is deliberately no free-target transition endpoint — actions
     * decide outcomes (checkout, credit decision, collection, delivery), so
     * the credit gate cannot be walked around by naming a state.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Paid, self::Rejected, self::Completed], true);
    }

    /**
     * A sale that has actually happened — stock and ledger moved. Excludes
     * ones waiting on, or refused, credit approval.
     */
    public function isSold(): bool
    {
        return ! in_array($this, [self::PendingApproval, self::Rejected, self::Draft], true);
    }
}
