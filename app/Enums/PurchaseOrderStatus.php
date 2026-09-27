<?php

declare(strict_types=1);

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';

    // Foreign-flow statuses.
    case PendingPayment = 'pending_payment';
    case AwaitingBankApproval = 'awaiting_bank_approval';
    case AwaitingTransfer = 'awaiting_transfer';
    case Paid = 'paid';
    case InTransit = 'in_transit';
    case AtPort = 'at_port';
    case InTransitToWarehouse = 'in_transit_to_warehouse';
    case AtWarehouse = 'at_warehouse';
    case AwaitingReceipt = 'awaiting_receipt';
    case Received = 'received';
    case Complete = 'complete';

    // Local-flow statuses (used when `kind = 'local'`).
    case Approved = 'approved';
    case Closed = 'closed';
}
