<?php

declare(strict_types=1);

namespace App\Enums;

enum SyncConflictStatus: string
{
    case Quarantined = 'quarantined';
    case ResolvedOverride = 'resolved_override';
    case ResolvedEdited = 'resolved_edited';
    case ResolvedVoided = 'resolved_voided';
}
