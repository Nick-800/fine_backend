<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EntityRoleType;
use App\Enums\EntityType;
use App\Models\Entity;
use App\Models\EntityRole;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * Split a domain model off into its own separate standalone Entity.
     */
    public function splitEntity(
        Model $domainModel,
        EntityRoleType $roleType,
        ?string $newEntityName = null
    ): Entity {
        return DB::transaction(function () use ($domainModel, $roleType, $newEntityName): Entity {
            $currentEntity = $domainModel->entity;

            $name = $newEntityName ?? ($currentEntity ? $currentEntity->name : 'New Entity');
            $entityType = $currentEntity ? $currentEntity->entity_type : EntityType::Individual;
            $taxNumber = $currentEntity ? $currentEntity->tax_number : null;
            $operatingUnitId = $domainModel->operating_unit_id ?? null;

            $newEntity = Entity::create([
                'name' => $name,
                'entity_type' => $entityType,
                'tax_number' => $taxNumber,
                'is_active' => true,
            ]);

            $this->ensureEntityRole($newEntity, $roleType, $operatingUnitId);

            $domainModel->update([
                'entity_id' => $newEntity->id,
            ]);

            return $newEntity->load(['roles', 'contacts']);
        });
    }

    /**
     * Re-link a domain model to a different existing Entity.
     */
    public function relinkEntity(
        Model $domainModel,
        string $targetEntityId,
        EntityRoleType $roleType,
        ?string $operatingUnitId = null
    ): Entity {
        return DB::transaction(function () use ($domainModel, $targetEntityId, $roleType, $operatingUnitId): Entity {
            $targetEntity = Entity::findOrFail($targetEntityId);

            $this->ensureEntityRole($targetEntity, $roleType, $operatingUnitId);

            $domainModel->update([
                'entity_id' => $targetEntity->id,
            ]);

            return $targetEntity;
        });
    }
}
