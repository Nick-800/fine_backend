<?php

declare(strict_types=1);

namespace App\Enums;

enum AllocationMethod: string
{
    case EvenSplit = 'even_split';
    case UsageBased = 'usage_based';
    case HeadcountBased = 'headcount_based';
    case ManualPercentage = 'manual_percentage';
}
