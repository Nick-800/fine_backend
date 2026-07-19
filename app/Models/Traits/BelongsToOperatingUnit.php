<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Models\Scopes\OperatingUnitScope;
use App\Support\CurrentUnitContext;

trait BelongsToOperatingUnit
{
    /**
     * Boot the trait to apply global scope and default values.
     */
    public static function bootBelongsToOperatingUnit(): void
    {
        static::addGlobalScope(new OperatingUnitScope);

        static::creating(function ($model): void {
            if ($model->operating_unit_id === null) {
                $context = app(CurrentUnitContext::class);
                if ($context->hasUnit()) {
                    $model->operating_unit_id = $context->id();
                }
            }
        });
    }
}
