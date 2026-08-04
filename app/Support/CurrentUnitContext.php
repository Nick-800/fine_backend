<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\OperatingUnit;

final class CurrentUnitContext
{
    private ?OperatingUnit $unit = null;

    public function setUnit(OperatingUnit $unit): void
    {
        $this->unit = $unit;
    }

    public function unit(): ?OperatingUnit
    {
        return $this->unit;
    }

    public function id(): ?string
    {
        return $this->unit?->id;
    }

    public function getUnitId(): ?string
    {
        return $this->id();
    }

    public function hasUnit(): bool
    {
        return $this->unit !== null;
    }

    public function clear(): void
    {
        $this->unit = null;
    }
}
