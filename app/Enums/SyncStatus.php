<?php

declare(strict_types=1);

namespace App\Enums;

enum SyncStatus: string
{
    case Synced = 'synced';
    case PendingSettlement = 'pending_settlement';
    case Quarantined = 'quarantined';
}
