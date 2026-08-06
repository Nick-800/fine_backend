<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentRequestStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Rejected = 'rejected';
}
