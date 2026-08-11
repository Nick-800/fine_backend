<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\CurrentUnitContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Unit scoping for models whose `operating_unit_id` is nullable, where null means
 * "shared across every unit" rather than "belongs to no one".
 *
 * Item categories are the case in point: a unit may define its own, but a category
 * with no unit is part of the common library and must stay visible to everyone.
 * Using the plain OperatingUnitScope here would hide those shared rows from every
 * unit-scoped user.
 */
final class OperatingUnitOrSharedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CurrentUnitContext::class);

        if (! $context->hasUnit()) {
            return;
        }

        $column = $model->getTable().'.operating_unit_id';

        $builder->where(function (Builder $query) use ($column, $context): void {
            $query->where($column, $context->id())
                ->orWhereNull($column);
        });
    }
}
