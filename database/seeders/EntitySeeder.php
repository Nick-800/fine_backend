<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EntityRoleType;
use App\Enums\EntityType;
use App\Models\Entity;
use App\Models\EntityContact;
use App\Models\EntityRole;
use App\Models\OperatingUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class EntitySeeder extends Seeder
{
    public function run(): void
    {
        $unit = OperatingUnit::first();

        if (! $unit) {
            return;
        }

        // 1. Create an Organization Entity
        $orgEntity = Entity::create([
            'id' => (string) Str::uuid(),
            'name' => 'Sahara Trading & Contracting Co.',
            'entity_type' => EntityType::Organization,
            'tax_number' => 'TAX-LIB-900800',
        ]);

        EntityContact::create([
            'entity_id' => $orgEntity->id,
            'email' => 'info@saharatrading.ly',
            'phone' => '+218-91-100-2000',
            'city' => 'Tripoli',
            'address' => 'Gargaresch Road',
            'is_primary' => true,
        ]);

        EntityRole::create([
            'entity_id' => $orgEntity->id,
            'role_type' => EntityRoleType::Client,
            'operating_unit_id' => $unit->id,
        ]);

        // 2. Create an Individual Entity
        $indEntity = Entity::create([
            'id' => (string) Str::uuid(),
            'name' => 'Nasser Al-Deen Ahmed',
            'entity_type' => EntityType::Individual,
            'tax_number' => 'NAT-88776655',
        ]);

        EntityContact::create([
            'entity_id' => $indEntity->id,
            'email' => 'nasser.ahmed@example.com',
            'phone' => '+218-92-300-4000',
            'city' => 'Benghazi',
            'is_primary' => true,
        ]);

        EntityRole::create([
            'entity_id' => $indEntity->id,
            'role_type' => EntityRoleType::Employee,
            'operating_unit_id' => $unit->id,
        ]);
    }
}
