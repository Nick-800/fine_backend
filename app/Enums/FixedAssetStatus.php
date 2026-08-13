<?php

declare(strict_types=1);

namespace App\Enums;

enum FixedAssetStatus: string
{
    case Active = 'active';
    case UnderMaintenance = 'under_maintenance';
    case Disposed = 'disposed';

    /**
     * §8.6 asset state machine. Disposal is terminal.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Active => [self::UnderMaintenance, self::Disposed],
            self::UnderMaintenance => [self::Active, self::Disposed],
            self::Disposed => [],
        };
    }
}
