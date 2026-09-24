<?php

declare(strict_types=1);

use App\Enums\FixedAssetStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\FixedAssetService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Foam', 'workflow_set' => ['production_batch' => []],
        'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->assets = app(FixedAssetService::class);

    // 13,000 cost, 1,000 salvage, 5 years straight-line → 200/month.
    $this->straightLineAsset = fn (array $overrides = []) => $this->assets->acquire(array_merge([
        'company_id' => $this->company->id,
        'operating_unit_id' => $this->unit->id,
        'name' => 'Foam mixing line',
        'asset_code' => 'FA-'.fake()->unique()->numberBetween(1000, 9999),
        'acquisition_cost' => 13000.0,
        'acquisition_date' => '2026-01-15',
        'depreciation_method' => 'straight_line',
        'useful_life_years' => 5,
        'salvage_value' => 1000.0,
        'payment_source' => 'cash',
    ], $overrides));
});

test('acquiring an asset capitalises it against cash', function () {
    $asset = ($this->straightLineAsset)();

    $entry = JournalEntry::where('source_document_type', 'FixedAsset')
        ->where('source_document_id', $asset->id)->sole();
    $lines = $entry->lines()->with('account')->get();

    $debit = $lines->firstWhere(fn ($l) => (float) $l->debit > 0);
    $credit = $lines->firstWhere(fn ($l) => (float) $l->credit > 0);

    expect($debit->account->account_code)->toBe('14')
        ->and((float) $debit->debit)->toBe(13000.0)
        ->and($credit->account->account_code)->toBe('12')
        ->and($asset->status)->toBe(FixedAssetStatus::Active)
        ->and($asset->bookValue())->toBe(13000.0);
});

test('salvage value at or above cost is refused', function () {
    expect(fn () => ($this->straightLineAsset)(['salvage_value' => 13000.0]))
        ->toThrow(InvalidArgumentException::class);
});

test('straight line depreciation posts a constant monthly amount', function () {
    $asset = ($this->straightLineAsset)();

    $entry = $this->assets->depreciateForPeriod($asset, '2026-02');

    expect((float) $entry->amount)->toBe(200.0)
        ->and((float) $entry->book_value_after)->toBe(12800.0)
        ->and((float) $asset->refresh()->accumulated_depreciation)->toBe(200.0);

    $journal = JournalEntry::where('source_document_type', 'DepreciationEntry')
        ->where('source_document_id', $entry->id)->sole();
    $lines = $journal->lines()->with('account')->get();

    expect($lines->firstWhere(fn ($l) => (float) $l->debit > 0)->account->account_code)->toBe('55')
        ->and($lines->firstWhere(fn ($l) => (float) $l->credit > 0)->account->account_code)->toBe('145');
});

test('a period never posts twice', function () {
    $asset = ($this->straightLineAsset)();

    $this->assets->depreciateForPeriod($asset, '2026-02');
    $second = $this->assets->depreciateForPeriod($asset, '2026-02');

    expect($second)->toBeNull()
        ->and((float) $asset->refresh()->accumulated_depreciation)->toBe(200.0)
        ->and(JournalEntry::where('source_document_type', 'DepreciationEntry')->count())->toBe(1);
});

test('declining balance depreciates from book value', function () {
    // 12,000 over 5 years double-declining → 40%/yr on book value.
    $asset = ($this->straightLineAsset)([
        'acquisition_cost' => 12000.0,
        'depreciation_method' => 'declining_balance',
        'salvage_value' => 0.0,
    ]);

    $first = $this->assets->depreciateForPeriod($asset, '2026-02');
    $second = $this->assets->depreciateForPeriod($asset->refresh(), '2026-03');

    expect((float) $first->amount)->toBe(400.0)          // 12000 × 0.4 / 12
        ->and((float) $second->amount)->toBe(386.6667);  // 11600 × 0.4 / 12
});

test('book value never sinks below salvage', function () {
    // 1,300 cost, 1,000 salvage, 5y straight line → 5/month for 60 months,
    // but only 300 of headroom exists in total.
    $asset = ($this->straightLineAsset)(['acquisition_cost' => 1300.0]);

    // 1300−1000 = 300 headroom at 5/month → cap kicks in long before life ends;
    // simulate by running until nothing posts.
    $posted = 0;
    $period = Carbon::createFromFormat('Y-m', '2026-01');

    while ($posted < 100) {
        $period = $period->addMonth();
        $entry = $this->assets->depreciateForPeriod($asset->refresh(), $period->format('Y-m'));

        if ($entry === null) {
            break;
        }

        $posted++;
    }

    expect($posted)->toBe(60)
        ->and($asset->refresh()->bookValue())->toBe(1000.0);
});

test('maintenance pauses depreciation and reactivation resumes it', function () {
    $asset = ($this->straightLineAsset)();

    $this->assets->transition($asset, FixedAssetStatus::UnderMaintenance);
    expect($this->assets->depreciateForPeriod($asset->refresh(), '2026-02'))->toBeNull();

    $this->assets->transition($asset->refresh(), FixedAssetStatus::Active);
    expect($this->assets->depreciateForPeriod($asset->refresh(), '2026-02'))->not->toBeNull();
});

test('disposal above book value posts a gain', function () {
    $asset = ($this->straightLineAsset)();
    $this->assets->depreciateForPeriod($asset, '2026-02'); // book 12,800

    $asset = $this->assets->dispose($asset->refresh(), 13500.0);

    expect($asset->status)->toBe(FixedAssetStatus::Disposed);

    $entry = JournalEntry::where('description', 'like', 'Asset disposed%')->sole();
    $byCode = $entry->lines()->with('account')->get()->groupBy(fn ($l) => $l->account->account_code);

    expect((float) $byCode['12']->sole()->debit)->toBe(13500.0)
        ->and((float) $byCode['145']->sole()->debit)->toBe(200.0)
        ->and((float) $byCode['14']->sole()->credit)->toBe(13000.0)
        ->and((float) $byCode['43']->sole()->credit)->toBe(700.0)
        ->and($entry->isBalanced())->toBeTrue();
});

test('disposal below book value posts a loss and a disposed asset stays disposed', function () {
    $asset = ($this->straightLineAsset)();

    $asset = $this->assets->dispose($asset, 11000.0); // book 13,000 → 2,000 loss

    $entry = JournalEntry::where('description', 'like', 'Asset disposed%')->sole();
    $byCode = $entry->lines()->with('account')->get()->groupBy(fn ($l) => $l->account->account_code);

    expect((float) $byCode['56']->sole()->debit)->toBe(2000.0)
        ->and($entry->isBalanced())->toBeTrue();

    expect(fn () => $this->assets->dispose($asset->refresh(), 1.0))
        ->toThrow(InvalidStateTransitionException::class);

    expect(fn () => $this->assets->depreciateForPeriod($asset->refresh(), '2026-03'))
        ->toThrow(InvalidStateTransitionException::class);
});

test('the depreciation schedule projects to salvage value', function () {
    $asset = ($this->straightLineAsset)();

    $schedule = $this->assets->schedule($asset);

    expect($schedule)->toHaveCount(60)
        ->and($schedule[0]['period'])->toBe('2026-01')
        ->and((float) $schedule[0]['amount'])->toBe(200.0)
        ->and((float) end($schedule)['book_value_after'])->toBe(1000.0);
});

test('the schedule endpoint and asset endpoints are wired', function () {
    $asset = ($this->straightLineAsset)();

    $this->actingAs($this->owner)
        ->getJson("/api/v1/fixed-assets/{$asset->id}/depreciation-schedule")
        ->assertStatus(200)
        ->assertJsonPath('book_value', 13000)
        ->assertJsonCount(60, 'rows');

    $this->actingAs($this->owner)
        ->postJson("/api/v1/fixed-assets/{$asset->id}/depreciate", ['period' => '2026-02'])
        ->assertStatus(201);

    // Second run for the same period is a refusal, not a double post.
    $this->actingAs($this->owner)
        ->postJson("/api/v1/fixed-assets/{$asset->id}/depreciate", ['period' => '2026-02'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'DEPRECIATION_SKIPPED');
});

test('the scheduled command depreciates every active asset once', function () {
    ($this->straightLineAsset)();
    ($this->straightLineAsset)(['name' => 'Delivery truck', 'operating_unit_id' => null]);

    $this->artisan('accounting:run-depreciation', ['--period' => '2026-05'])
        ->expectsOutputToContain('2 posted, 0 skipped, 0 failed')
        ->assertExitCode(0);

    $this->artisan('accounting:run-depreciation', ['--period' => '2026-05'])
        ->expectsOutputToContain('0 posted, 2 skipped, 0 failed')
        ->assertExitCode(0);
});

test('a company-wide caller sees other units assets with company_wide=1', function () {
    ($this->straightLineAsset)();

    $otherBlueprint = UnitBlueprint::create([
        'name' => 'Store', 'workflow_set' => ['sales_order' => []],
        'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $otherBlueprint->id,
        'name' => 'Showroom', 'code' => 'STORE-01', 'unit_type' => 'store', 'status' => 'active',
    ]);

    // Pinned to the other unit: the foam asset is invisible without the flag.
    $scoped = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $otherUnit->id])
        ->getJson('/api/v1/fixed-assets')
        ->assertStatus(200)->json('total');

    $companyWide = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $otherUnit->id])
        ->getJson('/api/v1/fixed-assets?company_wide=1')
        ->assertStatus(200)->json('total');

    expect($scoped)->toBe(0)
        ->and($companyWide)->toBe(1);

    // And can act on it — depreciating from a different pinned unit must not 404.
    $assetId = $this->actingAs($this->owner)
        ->getJson('/api/v1/fixed-assets?company_wide=1')->json('data.0.id');

    $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $otherUnit->id])
        ->postJson("/api/v1/fixed-assets/{$assetId}/depreciate", ['period' => '2026-02'])
        ->assertStatus(201);
});

test('the trial balance stays balanced through acquire, depreciate and dispose', function () {
    $asset = ($this->straightLineAsset)();
    $this->assets->depreciateForPeriod($asset, '2026-02');
    $this->assets->depreciateForPeriod($asset->refresh(), '2026-03');
    $this->assets->dispose($asset->refresh(), 9000.0);

    $tb = $this->actingAs($this->owner)->getJson('/api/v1/reports/trial-balance')
        ->assertStatus(200)->json();

    expect($tb['balanced'])->toBeTrue();
});
