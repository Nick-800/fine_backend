<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EntityRoleType;
use App\Enums\EntityType;
use App\Models\Entity;
use App\Models\EntityRole;
use Illuminate\Support\Facades\DB;

final class EntityService
{
    /**
     * Automatically create an Entity and associate the given EntityRole.
     */
    public function createEntityForDomainModel(
        EntityRoleType $roleType,
        array $attributes,
        ?string $operatingUnitId = null
    ): Entity {
        return DB::transaction(function () use ($roleType, $attributes, $operatingUnitId): Entity {
            $entityType = $attributes['entity_type'] ?? (
                $roleType === EntityRoleType::Employee ? EntityType::Individual : EntityType::Organization
            );

            if (is_string($entityType)) {
                $entityType = EntityType::from($entityType);
            }

            $entity = Entity::create([
                'name' => $attributes['name'],
                'entity_type' => $entityType,
                'tax_number' => $attributes['tax_number'] ?? null,
                'user_id' => $attributes['user_id'] ?? null,
                'is_active' => $attributes['is_active'] ?? true,
            ]);

            $this->ensureEntityRole($entity, $roleType, $operatingUnitId);

            return $entity;
        });
    }

    /**
     * Ensure an Entity has the specified EntityRole attached.
     */
    public function ensureEntityRole(
        Entity $entity,
        EntityRoleType $roleType,
        ?string $operatingUnitId = null
    ): EntityRole {
        return EntityRole::firstOrCreate([
            'entity_id' => $entity->id,
            'role_type' => $roleType,
            'operating_unit_id' => $operatingUnitId,
        ]);
    }
}
