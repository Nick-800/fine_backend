<?php

declare(strict_types=1);

namespace App\Enums;

enum AllocationPaymentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار الموافقة',
            self::Approved => 'معتمد بانتظار التأكيد',
            self::Paid => 'مدفوع ومؤكد',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => $next === self::Approved,
            self::Approved => $next === self::Paid,
            self::Paid => false,
        };
    }
}
