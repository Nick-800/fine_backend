<?php

declare(strict_types=1);

namespace App\Enums;

enum SalesOrderStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Confirmed = 'confirmed';
    case Fulfilled = 'fulfilled';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Completed = 'completed'; // internal transfers end here

    /**
     * Unlike the manufacturing lifecycles this one branches, and the branch is
     * never the caller's to pick: submit lands on confirmed or pending_approval
     * depending on the credit check, and payment lands on paid or partially_paid
     * depending on the amount. There is deliberately no free-target transition
     * endpoint — actions decide outcomes, so the credit gate cannot be walked
     * around by naming the state directly.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Paid, self::Rejected, self::Completed], true);
    }

    public function acceptsPayment(): bool
    {
        return in_array($this, [self::Fulfilled, self::PartiallyPaid], true);
    }
}
