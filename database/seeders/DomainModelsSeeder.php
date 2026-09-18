<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ClientStatus;
use App\Enums\EmployeeStatus;
use App\Enums\EntityType;
use App\Enums\PayType;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\OperatingUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class DomainModelsSeeder extends Seeder
{
    public function run(): void
    {
        $unit = OperatingUnit::first();

        if (! $unit) {
            return;
        }

        // 1. Seed Sample Client
        $clientEntity = Entity::where('name', 'Sahara Trading & Contracting Co.')->first() ?? Entity::create([
            'id' => (string) Str::uuid(),
            'name' => 'Sahara Trading & Contracting Co.',
            'entity_type' => EntityType::Organization,
        ]);

        Client::create([
            'id' => (string) Str::uuid(),
            'entity_id' => $clientEntity->id,
            'operating_unit_id' => $unit->id,
            'credit_limit' => 250000.00,
            'payment_terms_days' => 45,
            'status' => ClientStatus::Active,
        ]);

        // 2. Seed Sample Employee
        $empEntity = Entity::where('name', 'Nasser Al-Deen Ahmed')->first() ?? Entity::create([
            'id' => (string) Str::uuid(),
            'name' => 'Nasser Al-Deen Ahmed',
            'entity_type' => EntityType::Individual,
        ]);

        Employee::create([
            'id' => (string) Str::uuid(),
            'entity_id' => $empEntity->id,
            'operating_unit_id' => $unit->id,
            'job_title' => 'Senior Foam Plant Engineer',
            'pay_type' => PayType::Monthly,
            'hire_date' => now()->subYears(2)->toDateString(),
            'status' => EmployeeStatus::Active,
        ]);
    }
}
