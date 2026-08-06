<?php

declare(strict_types=1);

namespace App\Enums;

enum ImportOrderStatus: string
{
    case Draft = 'draft';
    case PendingPayment = 'pending_payment';
    case AwaitingBankApproval = 'awaiting_bank_approval';
    case AwaitingTransfer = 'awaiting_transfer';
    case Paid = 'paid';
    case InTransit = 'in_transit';
    case AtPort = 'at_port';
    case AwaitingReceipt = 'awaiting_receipt';
    case Received = 'received';
    case Complete = 'complete';
}
