<?php

declare(strict_types=1);

namespace App\Enums;

enum ProductionBatchStatus: string
{
    case Planned = 'planned';
    case Configured = 'configured';
    case Running = 'running';
    case Consumed = 'consumed';
    case Curing = 'curing';
    case ReadyForGrading = 'ready_for_grading';
    case Graded = 'graded';
    case Closed = 'closed';
}
