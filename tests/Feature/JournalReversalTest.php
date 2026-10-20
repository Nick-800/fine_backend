<?php

declare(strict_types=1);

use App\Enums\JournalEntryType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);

    $makeUser = function (string $slug): User {
        $user = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

        return $user;
    };
    $this->owner = $makeUser('owner');
    $this->accountant = $makeUser('accounting-manager');

    $this->accounting = app(AccountingService::class);
    $this->automatic = $this->accounting->postJournal('Overhead paid', [
        ['account_code' => '54', 'debit' => 120.0, 'operating_unit_id' => $this->unit->id, 'memo' => 'electricity'],
        ['account_code' => '121101', 'credit' => 120.0, 'operating_unit_id' => $this->unit->id],
    ], 'OverheadExpense', '01a00000-0000-7000-8000-000000000001', entryDate: '2026-10-05');

    $this->reverse = fn (User $user, JournalEntry $entry, array $body = []) => $this->actingAs($user)
        ->postJson("/api/v1/journal-entries/{$entry->id}/reverse", ['reason' => 'Posted to the wrong account', ...$body]);
});

test('a reversal mirrors the entry, links both ways and nets the books to zero', function () {
    $reversal = ($this->reverse)($this->accountant, $this->automatic, ['entry_date' => '2026-10-06'])
        ->assertCreated()
        ->assertJsonPath('entry_type', 'reversal')
        ->assertJsonPath('reversal_of_id', $this->automatic->id)
        ->assertJsonPath('reversal_reason', 'Posted to the wrong account')
        ->json();

    $entry = JournalEntry::with('lines.account')->find($reversal['id']);
    $byCode = $entry->lines->keyBy(fn ($l) => $l->account->account_code);

    expect($entry->isBalanced())->toBeTrue()
        ->and((float) $byCode['54']->credit)->toBe(120.0)
        ->and((float) $byCode['121101']->debit)->toBe(120.0)
        ->and($byCode['54']->operating_unit_id)->toBe($this->unit->id)
        ->and($entry->entry_date->toDateString())->toBe('2026-10-06')
        ->and($entry->source_document_type)->toBe('OverheadExpense');

    $tb = collect($this->accounting->trialBalance()['rows'])->keyBy('account_code');
    expect((float) $tb['54']['balance'])->toBe(0.0);

    $shown = $this->actingAs($this->owner)->getJson("/api/v1/journal-entries/{$this->automatic->id}")->assertOk()->json();
    expect($shown['reversal']['id'])->toBe($reversal['id']);

    $forDocument = $this->actingAs($this->owner)
        ->getJson('/api/v1/journal-entries/for-document/OverheadExpense/01a00000-0000-7000-8000-000000000001')
        ->assertOk()->json();
    expect($forDocument)->toHaveCount(2);
});

test('an entry is reversed only once, and a reversal cannot be reversed', function () {
    $reversal = ($this->reverse)($this->owner, $this->automatic)->assertCreated()->json();

    ($this->reverse)($this->owner, $this->automatic)
        ->assertStatus(422)->assertJsonPath('code', 'ALREADY_REVERSED');

    ($this->reverse)($this->owner, JournalEntry::find($reversal['id']))
        ->assertStatus(422)->assertJsonPath('code', 'REVERSAL_NOT_REVERSIBLE');

    expect(JournalEntry::where('reversal_of_id', $this->automatic->id)->count())->toBe(1);
});

test('a closing entry is not reversed directly', function () {
    $closing = $this->accounting->postJournal('Year-end close', [
        ['account_code' => '40201', 'debit' => 10.0],
        ['account_code' => '33', 'credit' => 10.0],
    ], entryType: JournalEntryType::Closing);

    ($this->reverse)($this->owner, $closing)
        ->assertStatus(422)->assertJsonPath('code', 'CLOSING_NOT_REVERSIBLE');
});

test('a reversal cannot be dated before the original', function () {
    ($this->reverse)($this->owner, $this->automatic, ['entry_date' => '2026-10-01'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'REVERSAL_DATE_BEFORE_ORIGINAL')
        ->assertJsonPath('original_date', '2026-10-05');
});

test('a reason is required and only accounting roles can reverse', function () {
    $this->actingAs($this->owner)->postJson("/api/v1/journal-entries/{$this->automatic->id}/reverse", [])
        ->assertStatus(422)->assertJsonValidationErrors('reason');

    $cashier = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Cashier', 'slug' => 'pos-cashier']);
    UserRole::create(['user_id' => $cashier->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unit->id]);

    $this->actingAs($cashier)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/journal-entries/{$this->automatic->id}/reverse", ['reason' => 'x'])
        ->assertForbidden();
});

test('a reversal still posts after the original account became a header', function () {
    $cash = Account::where('account_code', '121101')->sole();
    Account::create([
        'chart_of_accounts_id' => $cash->chart_of_accounts_id, 'parent_account_id' => $cash->id,
        'account_code' => '12110101', 'name' => 'Drawer A', 'type' => 'asset', 'currency' => 'LYD',
    ]);

    ($this->reverse)($this->owner, $this->automatic)->assertCreated();
});

test('manual entries carry their type and the journal filters by type, account and search', function () {
    $this->actingAs($this->accountant)->postJson('/api/v1/journal-entries', [
        'entry_type' => 'adjustment',
        'description' => 'Month-end accrual',
        'lines' => [
            ['account_code' => '54', 'debit' => 40],
            ['account_code' => '223', 'credit' => 40],
        ],
    ])->assertCreated()->assertJsonPath('entry_type', 'adjustment')->assertJsonPath('is_manual', true);

    $this->actingAs($this->accountant)->postJson('/api/v1/journal-entries', [
        'entry_type' => 'closing',
        'description' => 'Not allowed by hand',
        'lines' => [['account_code' => '54', 'debit' => 1], ['account_code' => '223', 'credit' => 1]],
    ])->assertStatus(422)->assertJsonValidationErrors('entry_type');

    $list = fn (string $query) => $this->actingAs($this->owner)->getJson("/api/v1/journal-entries?{$query}")->assertOk()->json('data');

    expect($list('entry_type[]=adjustment'))->toHaveCount(1);
    expect($list('entry_type[]=automatic'))->toHaveCount(1);
    expect($list('manual_only=1'))->toHaveCount(1);
    expect($list('account_id='.Account::where('account_code', '223')->value('id')))->toHaveCount(1);
    expect($list('search=accrual'))->toHaveCount(1);
    expect($list('min_amount=100'))->toHaveCount(1);
});

test('existing manual entries are backfilled as manual', function () {
    expect(JournalEntry::find($this->automatic->id)->entry_type)->toBe(JournalEntryType::Automatic);

    $legacy = $this->accounting->postJournal('Legacy manual', [
        ['account_code' => '54', 'debit' => 5.0],
        ['account_code' => '223', 'credit' => 5.0],
    ], isManual: true);

    expect($legacy->entry_type)->toBe(JournalEntryType::Manual);

    DB::table('journal_entries')->where('id', $legacy->id)->update(['entry_type' => 'automatic']);
    DB::table('journal_entries')->where('is_manual', true)->update(['entry_type' => 'manual']);
    expect(JournalEntry::find($legacy->id)->entry_type)->toBe(JournalEntryType::Manual);
});
