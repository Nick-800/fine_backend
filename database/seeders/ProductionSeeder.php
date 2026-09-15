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

/**
 * Production-ready structural + financial seeder.
 *
 * Seeds:
 *   - the company row
 *   - all roles + permissions (via RoleSeeder)
 *   - all 5 unit blueprints (via BlueprintSeeder)
 *   - all 5 operating units, each with its default warehouse
 *     (via OperatingUnitService::provision)
 *   - the chart of accounts (via ChartOfAccountsSeeder) so the GL exists
 *   - opening balances across AR / FG / FA / AP / Wages, offset to Retained
 *     Earnings (via OpeningBalanceSeeder) so the system has working money
 *     for orders on day one
 *   - the single owner user (owner@erp.com / password, company-wide role,
 *     must_change_password = false)
 *
 * Deliberately does NOT seed: FX rates, cash accounts, demo inventory,
 * demo suppliers/import orders, demo batches, or per-unit demo users. The
 * administrator creates those after the system is up — or operators run
 * `db:seed:dummy` for a richer environment.
 *
 * Idempotent — every record is keyed on a natural unique (name / slug /
 * email / account_code / journal description). Re-running on an
 * already-seeded database is a no-op.
 */
final class ProductionSeeder extends Seeder
{
    private const OWNER_EMAIL = 'owner@erp.com';

    private const OWNER_PASSWORD = 'password';

    private const OWNER_NAME = 'Nick Owner';

    /**
     * Map of array-key → [blueprint_name, operating_unit_name].
     * Blueprint names must match BlueprintSeeder exactly.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const UNIT_SPECS = [
        'procurement' => ['Foam Manufactory Blueprint', 'Procurement & Treasury'],
        'foam' => ['Foam Manufactory Blueprint', 'Foam Manufacturer'],
        'cutter' => ['Cutter Manufactory Blueprint', 'Cutter'],
        'furniture' => ['Furniture Manufactory Blueprint', 'Furniture'],
        'showroom' => ['Store Blueprint', 'Showroom'],
    ];

    public function run(): void
    {
        $this->seedCompany();
        $this->seedRolesAndPermissions();
        $this->seedBlueprints();
        $this->seedOperatingUnits();
        $this->seedChartOfAccounts();
        $this->seedOpeningBalances();
        $this->seedOwnerUser();
    }

    private function seedCompany(): void
    {
        Company::firstOrCreate(
            ['name' => 'Al-Amana Foam & Furniture Co.'],
            [
                'id' => (string) Str::uuid(),
                'default_currency' => 'LYD',
                'overhead_absorption_enabled' => false,
                'transfer_pricing_mode' => 'at_cost',
                'timezone' => 'Africa/Tripoli',
            ],
        );
    }

    private function seedRolesAndPermissions(): void
    {
        $this->call(RoleSeeder::class);
    }

    private function seedBlueprints(): void
    {
        $this->call(BlueprintSeeder::class);
    }

    private function seedChartOfAccounts(): void
    {
        $this->call(ChartOfAccountsSeeder::class);
    }

    private function seedOpeningBalances(): void
    {
        $this->call(OpeningBalanceSeeder::class);
    }

    /**
     * @return array<string, OperatingUnit>
     */
    private function seedOperatingUnits(): array
    {
        /** @var OperatingUnitService $service */
        $service = app(OperatingUnitService::class);

        $resolved = [];
        foreach (self::UNIT_SPECS as $key => [$blueprintName, $unitName]) {
            $existing = OperatingUnit::where('name', $unitName)->first();
            if ($existing !== null) {
                $resolved[$key] = $existing;

                continue;
            }

            $blueprint = UnitBlueprint::where('name', $blueprintName)->firstOrFail();
            $resolved[$key] = $service->provision($blueprint, $unitName);
        }

        return $resolved;
    }

    private function seedOwnerUser(): void
    {
        $user = User::firstOrCreate(
            ['email' => self::OWNER_EMAIL],
            [
                'id' => (string) Str::uuid(),
                'name' => self::OWNER_NAME,
                'password' => Hash::make(self::OWNER_PASSWORD),
                'is_active' => true,
                'must_change_password' => false,
            ],
        );

        $ownerRole = Role::where('slug', 'owner')->first();
        if ($ownerRole === null) {
            return;
        }

        UserRole::firstOrCreate(
            [
                'user_id' => $user->id,
                'role_id' => $ownerRole->id,
                'operating_unit_id' => null,
            ],
            ['id' => (string) Str::uuid()],
        );
    }
}
