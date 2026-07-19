<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\CurrentUnitContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class OperatingUnitScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CurrentUnitContext::class);

        if ($context->hasUnit()) {
            $builder->where($model->getTable().'.operating_unit_id', $context->id());
        }
    }
}
