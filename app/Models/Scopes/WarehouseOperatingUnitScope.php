<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\CurrentUnitContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Unit scoping for models that carry no `operating_unit_id` of their own and are
 * reachable only through their warehouse — stock lots being the case in point.
 *
 * Mirrors OperatingUnitScope: when no unit is in context (company-wide roles such
 * as Owner) nothing is filtered, so those users still see across units.
 */
final class WarehouseOperatingUnitScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CurrentUnitContext::class);

        if (! $context->hasUnit()) {
            return;
        }

        $builder->whereHas('warehouse', function (Builder $query) use ($context): void {
            $query->where('operating_unit_id', $context->id());
        });
    }
}
