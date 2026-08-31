<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Models\Entity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasEntityLifecycle
{
    /**
     * Relationship to the backing Entity.
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
