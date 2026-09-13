<?php

declare(strict_types=1);

namespace App\Enums;

enum CutterWorkOrderStatus: string
{
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case InProduction = 'in_production';
    case AwaitingByproductWeighIn = 'awaiting_byproduct_weigh_in';
    case QualityCheck = 'quality_check';
    case Completed = 'completed';
    case Invoiced = 'invoiced';

    /**
     * Phase 05 §5.4. Linear, like the foam batch lifecycle — there is no
     * branching and no way back.
     *
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Requested => [self::Confirmed],
            self::Confirmed => [self::InProduction],
            self::InProduction => [self::AwaitingByproductWeighIn],
            self::AwaitingByproductWeighIn => [self::QualityCheck],
            self::QualityCheck => [self::Completed],
            self::Completed => [self::Invoiced],
            self::Invoiced => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    /**
     * Blocks are picked at order creation (CUT-block-sale) and may be re-picked
     * later as long as the order hasn't physically been cut yet. After that the
     * block is already consumed and the picker is closed.
     */
    public function acceptsBlockSelection(): bool
    {
        return in_array($this, [self::Requested, self::Confirmed], true);
    }
}
