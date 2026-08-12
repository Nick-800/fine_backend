<?php

declare(strict_types=1);

namespace App\Enums;

enum ProductionOrderStatus: string
{
    case Requested = 'requested';
    case BomConfirmed = 'bom_confirmed';
    case InProduction = 'in_production';
    case QualityCheck = 'quality_check';
    case ReadyForCollection = 'ready_for_collection';
    case Completed = 'completed';

    /**
     * Phase 06 §6.4 — linear like the other manufacturing lifecycles.
     *
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Requested => [self::BomConfirmed],
            self::BomConfirmed => [self::InProduction],
            self::InProduction => [self::QualityCheck],
            self::QualityCheck => [self::ReadyForCollection],
            self::ReadyForCollection => [self::Completed],
            self::Completed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    /**
     * Labor may be logged while assembly is under way and during QC — the
     * forgiving window for late entries — but not after the finished-goods
     * journal has posted, or the logged cost would never reach the ledger.
     */
    public function acceptsLaborLogs(): bool
    {
        return in_array($this, [self::InProduction, self::QualityCheck], true);
    }
}
