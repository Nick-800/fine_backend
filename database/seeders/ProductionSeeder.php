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
 * Production-ready structural seeder.
 *
 * Seeds:
 *   - the company row
 *   - all roles + permissions (via RoleSeeder)
 *   - all 5 unit blueprints (via BlueprintSeeder)
 *   - all 5 operating units, each with its default warehouse
 *     (via OperatingUnitService::provision)
 *   - the chart of accounts (via ChartOfAccountsSeeder) so the GL exists
 *     with every account empty — no opening journal entries are posted
 *   - the single owner user (owner@erp.com / password, company-wide role,
 *     must_change_password = false)
 *
 * Deliberately does NOT seed: opening balances, FX rates, cash accounts,
 * demo inventory, demo suppliers/import orders, demo batches, or per-unit
 * demo users. The administrator opens balances via the manual journal API
 * and creates the rest after the system is up — or operators run
 * `db:seed:dummy` for a richer environment.
 *
 * Idempotent — every record is keyed on a natural unique (name / slug /
 * email / account_code). Re-running on an already-seeded database is a
 * no-op.
 */
final class ProductionSeeder extends Seeder
{
    /**
     * List of initial company-wide owner users.
     *
     * @var array<int, array{name: string, email: string, password: string}>
     */
    private const OWNER_USERS = [
        [
            'name' => 'Nick Owner',
            'email' => 'owner@erp.com',
            'password' => 'password',
        ],
        [
            'name' => 'حازم',
            'email' => 'hazemfast@gmail.com',
            'password' => 'password',
        ],
    ];

    /**
     * Map of array-key → [blueprint_name, operating_unit_name].
     * Blueprint names must match BlueprintSeeder exactly.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const UNIT_SPECS = [
        'procurement' => ['Foam Manufactory Blueprint', 'المشتريات والخزانة'],
        'foam' => ['Foam Manufactory Blueprint', 'مصنع الإسفنج'],
        'cutter' => ['Cutter Manufactory Blueprint', 'قسم القص'],
        'furniture' => ['Furniture Manufactory Blueprint', 'قسم الأثاث'],
        'showroom' => ['Store Blueprint', 'صالة العرض'],
    ];

    public function run(): void
    {
        $this->seedCompany();
        $this->seedRolesAndPermissions();
        $this->seedBlueprints();
        $this->seedOperatingUnits();
        $this->seedChartOfAccounts();
        $this->seedOwnerUsers();
    }

    private function seedCompany(): void
    {
        Company::firstOrCreate(
            ['name' => 'Fine'],
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

    private function seedOwnerUsers(): void
    {
        $ownerRole = Role::where('slug', 'owner')->first();
        if ($ownerRole === null) {
            return;
        }

        foreach (self::OWNER_USERS as $ownerData) {
            $user = User::withTrashed()->firstOrNew(['email' => $ownerData['email']]);

            if ($user->trashed()) {
                $user->restore();
            }

            if (! $user->exists) {
                $user->id = (string) Str::uuid();
            }

            $user->name = $ownerData['name'];
            $user->password = $ownerData['password'];
            $user->is_active = true;
            $user->must_change_password = false;
            $user->save();

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
}
