<?php

declare(strict_types=1);

use App\Enums\ChequeStatus;
use App\Models\Cheque;
use App\Models\ChequeEvent;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use App\Services\TreasuryBalanceService;
use App\Support\RolePermissions;
use App\Support\SalesAccounts;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Africa/Tripoli'));

    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant',
        'code' => 'FOAM-01',
        'unit_type' => 'manufactory',
        'status' => 'active',
    ]);

    $makeUser = function (string $slug, ?string $unitId = null): User {
        $user = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        RolePermissions::grantDefaults($role);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => $unitId]);

        return $user;
    };

    $this->owner = $makeUser('owner');
    $this->accountant = $makeUser('accounting-manager');
    $this->treasuryOfficer = $makeUser('treasury-officer', $this->unit->id);
    $this->storeManager = $makeUser('store-manager', $this->unit->id);

    $this->cashTreasury = linkedTreasury($this->unit, '121101', 'cash');
    $this->bankTreasury = linkedTreasury($this->unit, '1212', 'bank');

    $this->entity = Entity::create(['entity_type' => 'organization', 'name' => 'Benghazi Trading']);
    $this->client = Client::create([
        'entity_id' => $this->entity->id,
        'operating_unit_id' => $this->unit->id,
        'credit_limit' => 50000,
        'current_balance' => 1000.0,
        'status' => 'active',
    ]);

    $this->balanceService = app(TreasuryBalanceService::class);
    $this->accounting = app(AccountingService::class);

    $this->withHeader('X-Operating-Unit-ID', $this->unit->id);
});

test('receive posts DR 124 / CR client and lowers current_balance', function (): void {
    $payload = [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-98765',
        'bank_name' => 'Aman Bank',
        'drawer_name' => 'Ali Benghazi',
        'amount' => 300.0,
        'due_date' => '2026-10-25',
        'received_date' => '2026-10-18',
        'notes' => 'Advance payment cheque',
        'operating_unit_id' => $this->unit->id,
    ];

    $response = $this->actingAs($this->treasuryOfficer)
        ->postJson('/api/v1/cheques', $payload)
        ->assertStatus(201);

    $response->assertJsonPath('data.number', fn ($v): bool => str_starts_with((string) $v, 'CHQ-2026-'))
        ->assertJsonPath('data.amount', fn ($v): bool => (float) $v === 300.0)
        ->assertJsonPath('data.status', 'in_portfolio')
        ->assertJsonPath('data.status_label', 'في الحافظة')
        ->assertJsonPath('data.days_to_due', 5);

    $chequeId = $response->json('data.id');
    $cheque = Cheque::findOrFail($chequeId);

    // Client current balance decreased: 1000.0 - 300.0 = 700.0
    $this->client->refresh();
    expect((float) $this->client->current_balance)->toBe(700.0);

    // One journal entry: DR 124 / CR client receivable
    $entry = JournalEntry::where('source_document_type', 'Cheque')
        ->where('source_document_id', $cheque->id)
        ->sole();

    expect($entry->entry_date->toDateString())->toBe('2026-10-18');

    $lines = $entry->lines;
    $debitLine = $lines->firstWhere('debit', '>', 0);
    $creditLine = $lines->firstWhere('credit', '>', 0);

    expect($debitLine->account->account_code)->toBe('124')
        ->and((float) $debitLine->debit)->toBe(300.0)
        ->and($creditLine->account->account_code)->toBe(SalesAccounts::receivableFor($this->client))
        ->and((float) $creditLine->credit)->toBe(300.0)
        ->and($creditLine->client_id)->toBe($this->client->id);

    // One event recorded
    $event = ChequeEvent::where('cheque_id', $cheque->id)->sole();
    expect($event->from_status)->toBeNull()
        ->and($event->to_status)->toBe(ChequeStatus::InPortfolio)
        ->and($event->journal_entry_id)->toBe($entry->id);
});

test('deposit posts DR 125 / CR 124 with a bank treasury only', function (): void {
    $createRes = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-11111',
        'bank_name' => 'Wahda Bank',
        'amount' => 400.0,
        'due_date' => '2026-10-22',
        'received_date' => '2026-10-18',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $chequeId = $createRes->json('data.id');

    // Refuse deposit to cash treasury
    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/deposit", [
        'cash_account_id' => $this->cashTreasury->id,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'CHEQUE_DEPOSIT_NOT_BANK');

    // Deposit to bank treasury
    $depositRes = $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/deposit", [
        'cash_account_id' => $this->bankTreasury->id,
        'date' => '2026-10-19',
        'note' => 'Deposited to Tripoli branch',
    ])->assertOk();

    $depositRes->assertJsonPath('data.status', 'deposited')
        ->assertJsonPath('data.status_label', 'برسم التحصيل')
        ->assertJsonPath('data.deposit_cash_account.id', $this->bankTreasury->id);

    // Journal entry: DR 125 / CR 124
    $cheque = Cheque::findOrFail($chequeId);
    $events = ChequeEvent::where('cheque_id', $chequeId)->orderBy('created_at', 'asc')->get();
    expect($events)->toHaveCount(2);

    $depositEvent = $events[1];
    expect($depositEvent->from_status)->toBe(ChequeStatus::InPortfolio)
        ->and($depositEvent->to_status)->toBe(ChequeStatus::Deposited)
        ->and($depositEvent->event_date->toDateString())->toBe('2026-10-19');

    $entry = $depositEvent->journalEntry;
    $lines = $entry->lines;
    $debitLine = $lines->firstWhere('debit', '>', 0);
    $creditLine = $lines->firstWhere('credit', '>', 0);

    expect($debitLine->account->account_code)->toBe('125')
        ->and((float) $debitLine->debit)->toBe(400.0)
        ->and($creditLine->account->account_code)->toBe('124')
        ->and((float) $creditLine->credit)->toBe(400.0);

    // Client current balance remains unchanged
    $this->client->refresh();
    expect((float) $this->client->current_balance)->toBe(600.0);
});

test('clear posts DR bank / CR 125 and the bank live balance rises', function (): void {
    $createRes = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-22222',
        'bank_name' => 'Sahara Bank',
        'amount' => 500.0,
        'due_date' => '2026-10-20',
        'received_date' => '2026-10-18',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $chequeId = $createRes->json('data.id');

    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/deposit", [
        'cash_account_id' => $this->bankTreasury->id,
        'date' => '2026-10-19',
    ])->assertOk();

    $initialBankBalance = $this->balanceService->balanceFor($this->bankTreasury)['ledger_balance'];
    expect($initialBankBalance)->toBe(0.0);

    $clearRes = $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/clear", [
        'date' => '2026-10-20',
        'note' => 'Funds credited',
    ])->assertOk();

    $clearRes->assertJsonPath('data.status', 'cleared')
        ->assertJsonPath('data.status_label', 'محصّل');

    // Live balance of bank rises
    $newBankBalance = $this->balanceService->balanceFor($this->bankTreasury)['ledger_balance'];
    expect($newBankBalance)->toBe(500.0);

    // Entry: DR bank (1212) / CR 125
    $cheque = Cheque::findOrFail($chequeId);
    $events = ChequeEvent::where('cheque_id', $chequeId)->orderBy('created_at', 'asc')->get();
    expect($events)->toHaveCount(3);

    $clearEvent = $events[2];
    expect($clearEvent->to_status)->toBe(ChequeStatus::Cleared);

    $entry = $clearEvent->journalEntry;
    $lines = $entry->lines;
    $debitLine = $lines->firstWhere('debit', '>', 0);
    $creditLine = $lines->firstWhere('credit', '>', 0);

    expect($debitLine->account->account_code)->toBe('1212')
        ->and((float) $debitLine->debit)->toBe(500.0)
        ->and($creditLine->account->account_code)->toBe('125')
        ->and((float) $creditLine->credit)->toBe(500.0);
});

test('bounce from deposited and from portfolio restores the receivable and current_balance', function (): void {
    // 1. Bounce from deposited
    $res1 = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-BOUNCE-1',
        'bank_name' => 'Commerce Bank',
        'amount' => 150.0,
        'due_date' => '2026-10-20',
        'received_date' => '2026-10-18',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $cheque1Id = $res1->json('data.id');

    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$cheque1Id}/deposit", [
        'cash_account_id' => $this->bankTreasury->id,
        'date' => '2026-10-19',
    ])->assertOk();

    $this->client->refresh();
    expect((float) $this->client->current_balance)->toBe(850.0);

    $bounceRes1 = $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$cheque1Id}/bounce", [
        'reason' => 'Insufficient funds in drawer account',
        'date' => '2026-10-20',
    ])->assertOk();

    $bounceRes1->assertJsonPath('data.status', 'bounced')
        ->assertJsonPath('data.status_label', 'مرتجع')
        ->assertJsonPath('data.bounce_reason', 'Insufficient funds in drawer account');

    // Client current balance goes back up: 850 + 150 = 1000
    $this->client->refresh();
    expect((float) $this->client->current_balance)->toBe(1000.0)
        ->and($this->client->status->value)->toBe('active'); // client is NOT suspended

    $cheque1 = Cheque::findOrFail($cheque1Id);
    $bounceEvent1 = $cheque1->events()->where('to_status', ChequeStatus::Bounced)->first();
    expect($bounceEvent1->from_status)->toBe(ChequeStatus::Deposited)
        ->and($bounceEvent1->to_status)->toBe(ChequeStatus::Bounced);

    $lines1 = $bounceEvent1->journalEntry->lines;
    $debit1 = $lines1->firstWhere('debit', '>', 0);
    $credit1 = $lines1->firstWhere('credit', '>', 0);

    expect($debit1->account->account_code)->toBe(SalesAccounts::receivableFor($this->client))
        ->and((float) $debit1->debit)->toBe(150.0)
        ->and($debit1->client_id)->toBe($this->client->id)
        ->and($credit1->account->account_code)->toBe('125')
        ->and((float) $credit1->credit)->toBe(150.0);

    // 2. Bounce directly from in_portfolio
    $res2 = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-BOUNCE-2',
        'bank_name' => 'Commerce Bank',
        'amount' => 100.0,
        'due_date' => '2026-10-20',
        'received_date' => '2026-10-18',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $cheque2Id = $res2->json('data.id');

    $this->client->refresh();
    expect((float) $this->client->current_balance)->toBe(900.0);

    $bounceRes2 = $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$cheque2Id}/bounce", [
        'reason' => 'Drawer issued stop-payment notice',
        'date' => '2026-10-19',
    ])->assertOk();

    $bounceRes2->assertJsonPath('data.status', 'bounced');

    $this->client->refresh();
    expect((float) $this->client->current_balance)->toBe(1000.0);

    $cheque2 = Cheque::findOrFail($cheque2Id);
    $bounceEvent2 = $cheque2->events()->where('to_status', ChequeStatus::Bounced)->first();
    expect($bounceEvent2->from_status)->toBe(ChequeStatus::InPortfolio)
        ->and($bounceEvent2->to_status)->toBe(ChequeStatus::Bounced);

    $lines2 = $bounceEvent2->journalEntry->lines;
    $debit2 = $lines2->firstWhere('debit', '>', 0);
    $credit2 = $lines2->firstWhere('credit', '>', 0);

    expect($debit2->account->account_code)->toBe(SalesAccounts::receivableFor($this->client))
        ->and((float) $debit2->debit)->toBe(100.0)
        ->and($credit2->account->account_code)->toBe('124')
        ->and((float) $credit2->credit)->toBe(100.0);
});

test('redeposit after bounce', function (): void {
    $createRes = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-REDEPOSIT',
        'bank_name' => 'Aman Bank',
        'amount' => 200.0,
        'due_date' => '2026-10-20',
        'received_date' => '2026-10-18',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $chequeId = $createRes->json('data.id');

    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/deposit", [
        'cash_account_id' => $this->bankTreasury->id,
        'date' => '2026-10-18',
    ])->assertOk();

    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/bounce", [
        'reason' => 'Temporary liquidity shortage',
        'date' => '2026-10-19',
    ])->assertOk();

    $this->client->refresh();
    expect((float) $this->client->current_balance)->toBe(1000.0);

    // Redeposit
    $redepositRes = $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/deposit", [
        'cash_account_id' => $this->bankTreasury->id,
        'date' => '2026-10-20',
        'note' => 'Client confirmed account has funds now',
    ])->assertOk();

    $redepositRes->assertJsonPath('data.status', 'deposited');

    // Client current balance goes DOWN again: 1000 - 200 = 800
    $this->client->refresh();
    expect((float) $this->client->current_balance)->toBe(800.0);

    // Entry: DR 125 / CR client receivable
    $cheque = Cheque::findOrFail($chequeId);
    $lastEvent = $cheque->events()->where('from_status', ChequeStatus::Bounced)->first();
    expect($lastEvent->from_status)->toBe(ChequeStatus::Bounced)
        ->and($lastEvent->to_status)->toBe(ChequeStatus::Deposited);

    $lines = $lastEvent->journalEntry->lines;
    $debit = $lines->firstWhere('debit', '>', 0);
    $credit = $lines->firstWhere('credit', '>', 0);

    expect($debit->account->account_code)->toBe('125')
        ->and((float) $debit->debit)->toBe(200.0)
        ->and($credit->account->account_code)->toBe(SalesAccounts::receivableFor($this->client))
        ->and((float) $credit->credit)->toBe(200.0)
        ->and($credit->client_id)->toBe($this->client->id);
});

test('certified cheque clears straight from the portfolio, a non-certified one is refused', function (): void {
    // 1. Non-certified cheque
    $res1 = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-REGULAR',
        'bank_name' => 'Wahda Bank',
        'amount' => 350.0,
        'due_date' => '2026-10-20',
        'received_date' => '2026-10-18',
        'is_certified' => false,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $cheque1Id = $res1->json('data.id');

    // Attempting to clear regular cheque straight from portfolio is refused
    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$cheque1Id}/clear", [
        'cash_account_id' => $this->bankTreasury->id,
        'date' => '2026-10-19',
    ])->assertStatus(422)
        ->assertJsonPath('code', 'CHEQUE_NOT_CERTIFIED');

    // 2. Certified cheque
    $res2 = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-CERTIFIED',
        'bank_name' => 'Aman Bank',
        'amount' => 600.0,
        'due_date' => '2026-10-20',
        'received_date' => '2026-10-18',
        'is_certified' => true,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $cheque2Id = $res2->json('data.id');

    $clearRes = $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$cheque2Id}/clear", [
        'cash_account_id' => $this->bankTreasury->id,
        'date' => '2026-10-19',
        'note' => 'Certified cheque cleared immediately',
    ])->assertOk();

    $clearRes->assertJsonPath('data.status', 'cleared')
        ->assertJsonPath('data.deposit_cash_account.id', $this->bankTreasury->id);

    // Entry: DR bank (1212) / CR 124
    $cheque2 = Cheque::findOrFail($cheque2Id);
    $clearEvent = $cheque2->events()->where('to_status', ChequeStatus::Cleared)->first();
    expect($clearEvent->from_status)->toBe(ChequeStatus::InPortfolio)
        ->and($clearEvent->to_status)->toBe(ChequeStatus::Cleared);

    $lines = $clearEvent->journalEntry->lines;
    $debit = $lines->firstWhere('debit', '>', 0);
    $credit = $lines->firstWhere('credit', '>', 0);

    expect($debit->account->account_code)->toBe('1212')
        ->and((float) $debit->debit)->toBe(600.0)
        ->and($credit->account->account_code)->toBe('124')
        ->and((float) $credit->credit)->toBe(600.0);
});

test('return to drawer', function (): void {
    $createRes = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-RETURN',
        'bank_name' => 'Sahara Bank',
        'amount' => 250.0,
        'due_date' => '2026-10-25',
        'received_date' => '2026-10-18',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $chequeId = $createRes->json('data.id');

    $this->client->refresh();
    expect((float) $this->client->current_balance)->toBe(750.0);

    $returnRes = $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/return", [
        'note' => 'Signature mismatch with commercial registry',
        'date' => '2026-10-19',
    ])->assertOk();

    $returnRes->assertJsonPath('data.status', 'returned')
        ->assertJsonPath('data.status_label', 'مُعاد للساحب');

    // Balance restored: 750 + 250 = 1000
    $this->client->refresh();
    expect((float) $this->client->current_balance)->toBe(1000.0);

    $cheque = Cheque::findOrFail($chequeId);
    $event = $cheque->events()->where('to_status', ChequeStatus::ReturnedToDrawer)->first();
    expect($event->from_status)->toBe(ChequeStatus::InPortfolio)
        ->and($event->to_status)->toBe(ChequeStatus::ReturnedToDrawer)
        ->and($event->note)->toBe('Signature mismatch with commercial registry');

    $lines = $event->journalEntry->lines;
    $debit = $lines->firstWhere('debit', '>', 0);
    $credit = $lines->firstWhere('credit', '>', 0);

    expect($debit->account->account_code)->toBe(SalesAccounts::receivableFor($this->client))
        ->and((float) $debit->debit)->toBe(250.0)
        ->and($debit->client_id)->toBe($this->client->id)
        ->and($credit->account->account_code)->toBe('124')
        ->and((float) $credit->credit)->toBe(250.0);
});

test('invalid transitions refused', function (): void {
    // 1. Cleared cheque is terminal
    $res = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-TERMINAL-1',
        'bank_name' => 'Aman Bank',
        'amount' => 100.0,
        'due_date' => '2026-10-20',
        'received_date' => '2026-10-18',
        'is_certified' => true,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $chequeId = $res->json('data.id');

    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/clear", [
        'cash_account_id' => $this->bankTreasury->id,
        'date' => '2026-10-19',
    ])->assertOk();

    // Cleared -> Bounced refused
    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/bounce", [
        'reason' => 'Attempt bounce on cleared',
    ])->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_CHEQUE_TRANSITION');

    // Cleared -> Deposit refused
    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/deposit", [
        'cash_account_id' => $this->bankTreasury->id,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_CHEQUE_TRANSITION');

    // 2. Returned cheque is terminal
    $res2 = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-TERMINAL-2',
        'bank_name' => 'Aman Bank',
        'amount' => 100.0,
        'due_date' => '2026-10-20',
        'received_date' => '2026-10-18',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $cheque2Id = $res2->json('data.id');

    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$cheque2Id}/return", [
        'note' => 'Returned',
    ])->assertOk();

    // Returned -> Clear refused
    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$cheque2Id}/clear", [
        'cash_account_id' => $this->bankTreasury->id,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_CHEQUE_TRANSITION');

    // 3. Deposited -> Return to drawer refused
    $res3 = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-DEP-RET',
        'bank_name' => 'Aman Bank',
        'amount' => 100.0,
        'due_date' => '2026-10-20',
        'received_date' => '2026-10-18',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $cheque3Id = $res3->json('data.id');

    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$cheque3Id}/deposit", [
        'cash_account_id' => $this->bankTreasury->id,
    ])->assertOk();

    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$cheque3Id}/return", [
        'note' => 'Cannot return deposited cheque',
    ])->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_CHEQUE_TRANSITION');
});

test('event date before the last event refused', function (): void {
    $createRes = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-DATE-TEST',
        'bank_name' => 'Aman Bank',
        'amount' => 100.0,
        'due_date' => '2026-10-25',
        'received_date' => '2026-10-15',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $chequeId = $createRes->json('data.id');

    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/deposit", [
        'cash_account_id' => $this->bankTreasury->id,
        'date' => '2026-10-18',
    ])->assertOk();

    // Attempting clear with date before 2026-10-18
    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/clear", [
        'date' => '2026-10-17',
    ])->assertStatus(422)
        ->assertJsonPath('code', 'CHEQUE_DATE_BEFORE_LAST_EVENT');
});

test('a locked month refuses the move and leaves the status unchanged', function (): void {
    // Post an entry to provision FY2026 and period 2026-09
    $this->accounting->postJournal('Seed September', [
        ['account_code' => '54', 'debit' => 10.0],
        ['account_code' => '223', 'credit' => 10.0],
    ], entryDate: '2026-09-15');

    $period = FiscalPeriod::query()
        ->where('calendar_year', 2026)
        ->where('calendar_month', 9)
        ->sole();

    $this->actingAs($this->accountant)
        ->postJson("/api/v1/fiscal-periods/{$period->id}/lock", ['include_earlier' => true])
        ->assertOk();

    // 1. Receive in locked month is refused
    $initialChequesCount = Cheque::count();
    $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-LOCKED-MONTH',
        'bank_name' => 'Aman Bank',
        'amount' => 100.0,
        'due_date' => '2026-10-25',
        'received_date' => '2026-09-20',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'PERIOD_LOCKED');

    expect(Cheque::count())->toBe($initialChequesCount);

    // 2. Cheque created in open period; deposit into locked period refused, status unchanged
    $res = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-LOCKED-DEP',
        'bank_name' => 'Aman Bank',
        'amount' => 100.0,
        'due_date' => '2026-10-25',
        'received_date' => '2026-10-15',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $chequeId = $res->json('data.id');
    $cheque = Cheque::findOrFail($chequeId);
    expect($cheque->status)->toBe(ChequeStatus::InPortfolio);

    // Deposit backdated to locked September
    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/deposit", [
        'cash_account_id' => $this->bankTreasury->id,
        'date' => '2026-09-25',
    ])->assertStatus(422);

    $cheque->refresh();
    expect($cheque->status)->toBe(ChequeStatus::InPortfolio);
});

test('each move writes one event with its entry', function (): void {
    $res = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-HISTORY-1',
        'bank_name' => 'Aman Bank',
        'amount' => 450.0,
        'due_date' => '2026-10-25',
        'received_date' => '2026-10-16',
        'notes' => 'Received from client',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $chequeId = $res->json('data.id');

    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/deposit", [
        'cash_account_id' => $this->bankTreasury->id,
        'date' => '2026-10-18',
        'note' => 'Deposited to branch',
    ])->assertOk();

    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/clear", [
        'date' => '2026-10-20',
        'note' => 'Cheque cleared successfully',
    ])->assertOk();

    $showRes = $this->actingAs($this->treasuryOfficer)->getJson("/api/v1/cheques/{$chequeId}")
        ->assertOk();

    $events = $showRes->json('data.events');
    expect($events)->toHaveCount(3)
        ->and($events[0]['from_status'])->toBeNull()
        ->and($events[0]['to_status'])->toBe('in_portfolio')
        ->and($events[0]['to_status_label'])->toBe('في الحافظة')
        ->and($events[0]['journal_entry']['id'])->not->toBeNull()
        ->and($events[0]['journal_entry']['reference'])->not->toBeNull()
        ->and($events[0]['user']['id'])->toBe($this->treasuryOfficer->id)
        ->and($events[1]['from_status'])->toBe('in_portfolio')
        ->and($events[1]['to_status'])->toBe('deposited')
        ->and($events[1]['to_status_label'])->toBe('برسم التحصيل')
        ->and($events[2]['from_status'])->toBe('deposited')
        ->and($events[2]['to_status'])->toBe('cleared')
        ->and($events[2]['to_status_label'])->toBe('محصّل');
});

test('summary counts and overdue', function (): void {
    // Today is 2026-10-20
    // 1. InPortfolio due 2026-10-25 (within 7 days)
    $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-SUM-1',
        'bank_name' => 'Bank A',
        'amount' => 100.0,
        'due_date' => '2026-10-25',
        'received_date' => '2026-10-15',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    // 2. InPortfolio due 2026-10-15 (overdue by 5 days)
    $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-SUM-2',
        'bank_name' => 'Bank B',
        'amount' => 200.0,
        'due_date' => '2026-10-15',
        'received_date' => '2026-10-10',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    // 3. InPortfolio due 2026-11-20 (> 7 days in future)
    $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-SUM-3',
        'bank_name' => 'Bank C',
        'amount' => 300.0,
        'due_date' => '2026-11-20',
        'received_date' => '2026-10-15',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    // 4. Deposited due 2026-10-15 (past due date, but status is deposited so NOT in-portfolio overdue)
    $res4 = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-SUM-4',
        'bank_name' => 'Bank D',
        'amount' => 400.0,
        'due_date' => '2026-10-15',
        'received_date' => '2026-10-10',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques/'.$res4->json('data.id').'/deposit', [
        'cash_account_id' => $this->bankTreasury->id,
        'date' => '2026-10-12',
    ])->assertOk();

    $summaryRes = $this->actingAs($this->treasuryOfficer)->getJson('/api/v1/cheques/summary')
        ->assertOk();

    $summaryRes->assertJsonPath('data.due_within_7_days.count', 1)
        ->assertJsonPath('data.due_within_7_days.total', fn ($v): bool => (float) $v === 100.0)
        ->assertJsonPath('data.overdue.count', 1)
        ->assertJsonPath('data.overdue.total', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonPath('data.by_status.in_portfolio.count', 3)
        ->assertJsonPath('data.by_status.in_portfolio.total', fn ($v): bool => (float) $v === 600.0)
        ->assertJsonPath('data.by_status.deposited.count', 1)
        ->assertJsonPath('data.by_status.deposited.total', fn ($v): bool => (float) $v === 400.0);
});

test('roles: store-manager can read but not receive or mutate', function (): void {
    $createRes = $this->actingAs($this->owner)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-ROLE-1',
        'bank_name' => 'Aman Bank',
        'amount' => 100.0,
        'due_date' => '2026-10-25',
        'received_date' => '2026-10-15',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $chequeId = $createRes->json('data.id');

    // Store manager reads
    $this->actingAs($this->storeManager)->getJson('/api/v1/cheques')->assertOk();
    $this->actingAs($this->storeManager)->getJson('/api/v1/cheques/summary')->assertOk();
    $this->actingAs($this->storeManager)->getJson("/api/v1/cheques/{$chequeId}")->assertOk();

    // Store manager cannot receive
    $this->actingAs($this->storeManager)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-FORBIDDEN',
        'bank_name' => 'Aman Bank',
        'amount' => 100.0,
        'due_date' => '2026-10-25',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(403)
        ->assertJsonPath('code', 'CHEQUE_FORBIDDEN');

    // Store manager cannot deposit
    $this->actingAs($this->storeManager)->postJson("/api/v1/cheques/{$chequeId}/deposit", [
        'cash_account_id' => $this->bankTreasury->id,
    ])->assertStatus(403)
        ->assertJsonPath('code', 'CHEQUE_FORBIDDEN');
});

test('foreign currency cheques are refused with CHEQUE_CURRENCY_UNSUPPORTED', function (): void {
    $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'CHQ-USD',
        'bank_name' => 'Aman Bank',
        'amount' => 100.0,
        'currency' => 'USD',
        'due_date' => '2026-10-25',
        'received_date' => '2026-10-15',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'CHEQUE_CURRENCY_UNSUPPORTED');
});

test('index supports search and filtering and is ordered by due_date asc', function (): void {
    // Cheque A: due 2026-10-30
    $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'NUM-AAA',
        'bank_name' => 'Aman Special Bank',
        'drawer_name' => 'Tariq Drawer',
        'amount' => 100.0,
        'due_date' => '2026-10-30',
        'received_date' => '2026-10-15',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    // Cheque B: due 2026-10-21
    $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques', [
        'client_id' => $this->client->id,
        'cheque_no' => 'NUM-BBB',
        'bank_name' => 'Wahda General Bank',
        'drawer_name' => 'Hassan Drawer',
        'amount' => 200.0,
        'due_date' => '2026-10-21',
        'received_date' => '2026-10-15',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    // Search by cheque_no
    $searchRes1 = $this->actingAs($this->treasuryOfficer)->getJson('/api/v1/cheques?search=NUM-AAA')
        ->assertOk();
    expect($searchRes1->json('data'))->toHaveCount(1)
        ->and($searchRes1->json('data.0.cheque_no'))->toBe('NUM-AAA');

    // Search by drawer_name
    $searchRes2 = $this->actingAs($this->treasuryOfficer)->getJson('/api/v1/cheques?search=Hassan')
        ->assertOk();
    expect($searchRes2->json('data'))->toHaveCount(1)
        ->and($searchRes2->json('data.0.drawer_name'))->toBe('Hassan Drawer');

    // Filter by due_date range
    $rangeRes = $this->actingAs($this->treasuryOfficer)->getJson('/api/v1/cheques?due_from=2026-10-20&due_to=2026-10-25')
        ->assertOk();
    expect($rangeRes->json('data'))->toHaveCount(1)
        ->and($rangeRes->json('data.0.cheque_no'))->toBe('NUM-BBB');

    // Default order is due_date asc (NUM-BBB 2026-10-21 first, then NUM-AAA 2026-10-30)
    $allRes = $this->actingAs($this->treasuryOfficer)->getJson('/api/v1/cheques')
        ->assertOk();
    expect($allRes->json('data.0.cheque_no'))->toBe('NUM-BBB')
        ->and($allRes->json('data.1.cheque_no'))->toBe('NUM-AAA');
});
