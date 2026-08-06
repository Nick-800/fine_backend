<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Enums\EntityRoleType;
use App\Models\Entity;
use App\Services\EntityService;
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

    /**
     * Split this model off into a new standalone Entity.
     */
    public function splitEntity(EntityRoleType $roleType, ?string $newName = null): Entity
    {
        return app(EntityService::class)->splitEntity($this, $roleType, $newName);
    }

    /**
     * Re-link this model to a target Entity ID.
     */
    public function relinkEntity(string $targetEntityId, EntityRoleType $roleType): Entity
    {
        return app(EntityService::class)->relinkEntity(
            $this,
            $targetEntityId,
            $roleType,
            $this->operating_unit_id ?? null
        );
    }
}
