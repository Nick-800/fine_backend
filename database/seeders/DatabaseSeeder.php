<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\OperatingUnitService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Company
        $company = Company::firstOrCreate(
            ['name' => 'Fine Productions'],
            [
                'id' => (string) Str::uuid(),
                'default_currency' => 'LYD',
                'overhead_absorption_enabled' => false,
                'transfer_pricing_mode' => 'at_cost',
                'timezone' => 'Africa/Tripoli',
            ]
        );

        // 2. Roles & Permissions
        $this->call(RoleSeeder::class);

        // 3. Blueprints
        $this->call(BlueprintSeeder::class);

        // 4. Operating Units
        $service = new OperatingUnitService;
        $foamBlueprint = UnitBlueprint::where('name', 'Foam Manufactory Blueprint')->firstOrFail();
        if (! OperatingUnit::where('name', 'مصنع الإسفنج')->exists()) {
            $service->provision($foamBlueprint, 'مصنع الإسفنج');
        }

        // 5. Owner user
        $ownerRole = Role::where('slug', 'owner')->firstOrFail();

        $owner = User::firstOrCreate(
            ['email' => 'owner@erp.com'],
            [
                'id' => (string) Str::uuid(),
                'name' => 'Nick Owner',
                'password' => Hash::make('password'),
                'is_active' => true,
                'must_change_password' => false,
            ]
        );

        UserRole::firstOrCreate(
            [
                'user_id' => $owner->id,
                'role_id' => $ownerRole->id,
                'operating_unit_id' => null,
            ],
            [
                'id' => (string) Str::uuid(),
            ]
        );
    }
}
