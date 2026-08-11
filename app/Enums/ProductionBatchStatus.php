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

    /**
     * The single state each status may advance to (Phase 04 §4.4). The lifecycle
     * is strictly linear — there is no branching and no way back.
     *
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Planned => [self::Configured],
            self::Configured => [self::Running],
            self::Running => [self::Consumed],
            self::Consumed => [self::Curing],
            self::Curing => [self::ReadyForGrading],
            self::ReadyForGrading => [self::Graded],
            self::Graded => [self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    /**
     * Blocks may only be registered once the run has actually produced them and
     * they have been measured — the sheet is keyed in at grading.
     */
    public function acceptsBlockRegistration(): bool
    {
        return in_array($this, [self::ReadyForGrading, self::Graded], true);
    }
}
