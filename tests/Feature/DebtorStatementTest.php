<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-05 12:00');

    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unitA = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Furniture Unit', 'code' => 'FURN-01', 'unit_type' => 'manufactory',
    ]);
    $this->unitB = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Foam Unit', 'code' => 'FOAM-01', 'unit_type' => 'manufactory',
    ]);

    $this->makeUser = function (string $slug, ?string $unitId) {
        $user = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => $unitId]);

        return $user;
    };

    $entity = Entity::create(['name' => 'شركة البناء الراقي', 'entity_type' => 'organization']);
    $this->client = Client::create([
        'entity_id' => $entity->id, 'operating_unit_id' => $this->unitA->id,
        'credit_limit' => 50000.0, 'current_balance' => 0.0,
    ]);

    $this->postLine = function (string $date, float $debit, string $unitId) {
        app(AccountingService::class)->postJournal('Sale '.$unitId, [
            ['account_code' => '122', 'debit' => $debit, 'credit' => 0, 'client_id' => $this->client->id, 'operating_unit_id' => $unitId],
            ['account_code' => '41', 'debit' => 0, 'credit' => $debit, 'operating_unit_id' => $unitId],
        ], entryDate: $date);
    };
});

afterEach(function () {
    Carbon::setTestNow();
});

const DEBTOR_URL = '/api/v1/reports/debtor-statements';

test('owner gets the statement shape', function () {
    ($this->postLine)('2026-09-10', 2500, $this->unitA->id);
    $owner = ($this->makeUser)('owner', null);

    $response = $this->actingAs($owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson(DEBTOR_URL.'?from=2026-09-01&to=2026-09-30');

    $response->assertOk()->assertJsonStructure(['period', 'generated_at', 'debtors', 'unassigned', 'totals']);
    expect($response->json('debtors'))->toHaveCount(1)
        ->and($response->json('debtors.0.closing_balance'))->toBe(2500);
});

test('from is required', function () {
    $owner = ($this->makeUser)('owner', null);

    $this->actingAs($owner)->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson(DEBTOR_URL.'?to=2026-09-30')
        ->assertStatus(422)->assertJsonValidationErrors('from');
});

test('to before from is rejected', function () {
    $owner = ($this->makeUser)('owner', null);

    $this->actingAs($owner)->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson(DEBTOR_URL.'?from=2026-09-30&to=2026-09-01')
        ->assertStatus(422)->assertJsonValidationErrors('to');
});

test('pos cashier is forbidden', function () {
    $cashier = ($this->makeUser)('pos-cashier', $this->unitA->id);

    $this->actingAs($cashier)->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson(DEBTOR_URL.'?from=2026-09-01&to=2026-09-30')
        ->assertForbidden();
});

test('accounting manager is allowed', function () {
    $user = ($this->makeUser)('accounting-manager', null);

    $this->actingAs($user)->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson(DEBTOR_URL.'?from=2026-09-01&to=2026-09-30')
        ->assertOk();
});

test('unit manager with company_wide only sees own unit lines', function () {
    ($this->postLine)('2026-09-10', 2500, $this->unitA->id);
    ($this->postLine)('2026-09-11', 700, $this->unitB->id);
    $manager = ($this->makeUser)('manager', $this->unitA->id);

    $response = $this->actingAs($manager)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson(DEBTOR_URL.'?from=2026-09-01&to=2026-09-30&company_wide=1');

    $response->assertOk();
    $lines = $response->json('debtors.0.lines');
    expect($lines)->toHaveCount(1)
        ->and($lines[0]['debit'])->toBe(2500);
});

test('owner with company_wide sees all units', function () {
    ($this->postLine)('2026-09-10', 2500, $this->unitA->id);
    ($this->postLine)('2026-09-11', 700, $this->unitB->id);
    $owner = ($this->makeUser)('owner', null);

    $response = $this->actingAs($owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson(DEBTOR_URL.'?from=2026-09-01&to=2026-09-30&company_wide=1');

    $response->assertOk();
    expect($response->json('debtors.0.lines'))->toHaveCount(2);
});

test('client_ids forces include_unassigned off', function () {
    ($this->postLine)('2026-09-10', 2500, $this->unitA->id);
    app(AccountingService::class)->postJournal('Untagged', [
        ['account_code' => '122', 'debit' => 300, 'credit' => 0, 'operating_unit_id' => $this->unitA->id],
        ['account_code' => '41', 'debit' => 0, 'credit' => 300, 'operating_unit_id' => $this->unitA->id],
    ], entryDate: '2026-09-12');
    $owner = ($this->makeUser)('owner', null);
    $headers = ['X-Operating-Unit-ID' => $this->unitA->id];

    $without = $this->actingAs($owner)->withHeaders($headers)
        ->getJson(DEBTOR_URL.'?from=2026-09-01&to=2026-09-30&include_unassigned=1');
    expect($without->json('unassigned'))->not->toBeNull();

    $with = $this->actingAs($owner)->withHeaders($headers)
        ->getJson(DEBTOR_URL.'?from=2026-09-01&to=2026-09-30&include_unassigned=1&client_ids[]='.$this->client->id);
    $with->assertOk();
    expect($with->json('unassigned'))->toBeNull();
});
