<?php

declare(strict_types=1);

use App\Exceptions\HeaderAccountPostingException;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->asOwner = fn () => $this->actingAs($this->owner);
});

test('the ledger refuses a posting to a header account before writing anything', function () {
    expect(fn () => app(AccountingService::class)->postJournal('Cash to the header', [
        ['account_code' => '12', 'debit' => 100.0],
        ['account_code' => '31', 'credit' => 100.0],
    ]))->toThrow(HeaderAccountPostingException::class, 'Account 12');

    expect(fn () => app(AccountingService::class)->postJournal('Sale to the header', [
        ['account_code' => '121101', 'debit' => 100.0],
        ['account_code' => '41', 'credit' => 100.0],
    ]))->toThrow(HeaderAccountPostingException::class, 'Account 41');

    expect(JournalLine::count())->toBe(0);
});

test('a manual entry may still name the receivables root, nothing else above a leaf', function () {
    // Give 122 a child so it is a header.
    $root = Account::where('account_code', '122')->sole();
    Account::create([
        'chart_of_accounts_id' => $root->chart_of_accounts_id, 'parent_account_id' => $root->id,
        'account_code' => '122001', 'name' => 'Client', 'type' => $root->type, 'currency' => 'LYD',
    ]);

    ($this->asOwner)()->postJson('/api/v1/journal-entries', [
        'description' => 'Opening receivable',
        'lines' => [
            ['account_code' => '122', 'debit' => 100],
            ['account_code' => '40201', 'credit' => 100],
        ],
    ])->assertCreated();

    expect(fn () => app(AccountingService::class)->postJournal('Automated', [
        ['account_code' => '122', 'debit' => 100.0],
        ['account_code' => '40201', 'credit' => 100.0],
    ]))->toThrow(HeaderAccountPostingException::class);
});

test('a header posting renders as 422 HEADER_ACCOUNT_POSTING', function () {
    $treasury = CashAccount::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'Linked to a header', 'kind' => 'cash',
        // Bypasses the LeafAccount request rule, as an old row could.
        'account_id' => Account::where('account_code', '1211')->value('id'),
    ]);

    app(AccountingService::class)->postJournal('Credit purchase', [
        ['account_code' => '111', 'debit' => 500.0],
        ['account_code' => '21', 'credit' => 500.0],
    ]);

    ($this->asOwner)()->postJson('/api/v1/payable-settlements', [
        'account_code' => '21', 'amount' => 100, 'cash_account_id' => $treasury->id,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'HEADER_ACCOUNT_POSTING')
        ->assertJsonPath('account_code', '1211');
});

test('cash operations refuse a treasury that is missing or not linked to the ledger', function () {
    $loose = CashAccount::create(['operating_unit_id' => $this->unit->id, 'name' => 'Loose drawer', 'kind' => 'cash']);

    app(AccountingService::class)->postJournal('Credit purchase', [
        ['account_code' => '111', 'debit' => 500.0],
        ['account_code' => '21', 'credit' => 500.0],
    ]);

    ($this->asOwner)()->postJson('/api/v1/payable-settlements', ['account_code' => '21', 'amount' => 100])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cash_account_id');

    ($this->asOwner)()->postJson('/api/v1/payable-settlements', [
        'account_code' => '21', 'amount' => 100, 'cash_account_id' => $loose->id,
    ])->assertStatus(422)->assertJsonPath('code', 'TREASURY_NOT_LINKED');

    ($this->asOwner)()->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/overhead-expenses', [
            'category' => 'electricity', 'amount' => 50, 'expense_date' => now()->toDateString(),
            'payment_source' => 'cash',
        ])->assertStatus(422)
        ->assertJsonValidationErrors('cash_account_id');

    // A purchase on credit needs no treasury.
    ($this->asOwner)()->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/overhead-expenses', [
            'category' => 'electricity', 'amount' => 50, 'expense_date' => now()->toDateString(),
            'payment_source' => 'payable',
        ])->assertCreated();
});

test('overhead paid in cash leaves the chosen treasury', function () {
    $treasury = linkedTreasury($this->unit, '121102');

    $expense = ($this->asOwner)()->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/overhead-expenses', [
            'category' => 'electricity', 'amount' => 75, 'expense_date' => now()->toDateString(),
            'payment_source' => 'cash', 'cash_account_id' => $treasury->id,
        ])->assertCreated()->json();

    $credit = JournalLine::with('account', 'journalEntry')
        ->whereHas('journalEntry', fn ($q) => $q->where('source_document_id', $expense['id']))
        ->where('credit', '>', 0)->sole();

    expect($credit->account->account_code)->toBe('121102');
});

test('the treasury picker lists linked treasuries, without balances, to any signed-in role', function () {
    linkedTreasury($this->unit, '121101');
    CashAccount::create(['operating_unit_id' => $this->unit->id, 'name' => 'Loose drawer', 'kind' => 'cash', 'balance' => 999]);

    $operator = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Foam Operator', 'slug' => 'foam-operator']);
    UserRole::create(['user_id' => $operator->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unit->id]);

    $options = $this->actingAs($operator)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/cash-accounts/options')->assertOk()->json('data');

    expect($options)->toHaveCount(1)
        ->and($options[0]['account_code'])->toBe('121101')
        ->and($options[0])->not->toHaveKey('balance');
});

test('the migration links units, treasuries and clients that predate the rule', function () {
    $showroom = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'صالة العرض', 'code' => 'STORE-01', 'unit_type' => 'store', 'status' => 'active',
    ]);
    $mainTreasury = CashAccount::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'Main Operating Cash Treasury', 'kind' => 'cash',
    ]);
    $entity = Entity::create(['entity_type' => 'organization', 'name' => 'Old client']);
    $client = Client::create(['entity_id' => $entity->id, 'operating_unit_id' => $showroom->id]);

    (require database_path('migrations/2026_10_19_100000_move_postings_off_header_accounts.php'))->up();

    expect($showroom->fresh()->revenueAccount?->account_code)->toBe('40204')
        ->and($this->unit->fresh()->revenue_account_id)->toBeNull()
        ->and($mainTreasury->fresh()->account?->account_code)->toBe('121104')
        ->and($client->fresh()->account?->account_code)->toStartWith('122')
        ->and($client->fresh()->account?->parent?->account_code)->toBe('122')
        ->and(Account::where('account_code', '225')->exists())->toBeTrue();
});
