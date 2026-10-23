<?php

declare(strict_types=1);

use App\Enums\TreasuryVoucherKind;
use App\Enums\TreasuryVoucherStatus;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\TreasuryVoucher;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use App\Support\RolePermissions;
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
    $this->cashier = $makeUser('pos-cashier', $this->unit->id);

    $this->treasury = linkedTreasury($this->unit, '121101');
});

test('receipt posts DR treasury / CR counter with voucher date and number RV-…; payment posts DR counter / CR treasury, PV-…', function (): void {
    // 1. Receipt voucher
    $receiptPayload = [
        'kind' => 'receipt',
        'cash_account_id' => $this->treasury->id,
        'counter_account_code' => '42',
        'amount' => 250.0,
        'voucher_date' => '2026-10-15',
        'payee' => 'Ahmed Al-Fituri',
        'description' => 'Consulting fee received',
        'operating_unit_id' => $this->unit->id,
    ];

    $response = $this->actingAs($this->owner)
        ->postJson('/api/v1/treasury-vouchers', $receiptPayload)
        ->assertStatus(201);

    $voucherId = $response->json('data.id');
    $voucher = TreasuryVoucher::findOrFail($voucherId);

    expect($voucher->kind)->toBe(TreasuryVoucherKind::Receipt)
        ->and($voucher->status)->toBe(TreasuryVoucherStatus::Posted)
        ->and($voucher->voucher_number)->toStartWith('RV-2026-')
        ->and($voucher->voucher_date->toDateString())->toBe('2026-10-15')
        ->and((float) $voucher->amount)->toBe(250.0)
        ->and((float) $voucher->amount_lyd)->toBe(250.0);

    $entry = JournalEntry::where('source_document_type', 'TreasuryVoucher')
        ->where('source_document_id', $voucher->id)
        ->sole();

    expect($entry->entry_date->toDateString())->toBe('2026-10-15')
        ->and($entry->description)->toBe("سند قبض {$voucher->voucher_number}: Consulting fee received");

    $lines = $entry->lines()->with('account')->get();
    expect($lines)->toHaveCount(2);

    $debitLine = $lines->firstWhere('debit', '>', 0);
    $creditLine = $lines->firstWhere('credit', '>', 0);

    expect($debitLine->account->account_code)->toBe('121101')
        ->and((float) $debitLine->debit)->toBe(250.0)
        ->and($debitLine->operating_unit_id)->toBe($this->unit->id)
        ->and($debitLine->memo)->toBe('Ahmed Al-Fituri - Consulting fee received')
        ->and($creditLine->account->account_code)->toBe('42')
        ->and((float) $creditLine->credit)->toBe(250.0)
        ->and($creditLine->operating_unit_id)->toBe($this->unit->id)
        ->and($creditLine->memo)->toBe('Ahmed Al-Fituri - Consulting fee received');

    // 2. Payment voucher
    $paymentPayload = [
        'kind' => 'payment',
        'cash_account_id' => $this->treasury->id,
        'counter_account_code' => '54',
        'amount' => 80.0,
        'voucher_date' => '2026-10-16',
        'payee' => 'Municipal Utility',
        'description' => 'Water bill',
        'operating_unit_id' => $this->unit->id,
    ];

    $payResponse = $this->actingAs($this->owner)
        ->postJson('/api/v1/treasury-vouchers', $paymentPayload)
        ->assertStatus(201);

    $payVoucherId = $payResponse->json('data.id');
    $payVoucher = TreasuryVoucher::findOrFail($payVoucherId);

    expect($payVoucher->kind)->toBe(TreasuryVoucherKind::Payment)
        ->and($payVoucher->voucher_number)->toStartWith('PV-2026-')
        ->and($payVoucher->voucher_date->toDateString())->toBe('2026-10-16')
        ->and((float) $payVoucher->amount)->toBe(80.0);

    $payEntry = JournalEntry::where('source_document_type', 'TreasuryVoucher')
        ->where('source_document_id', $payVoucher->id)
        ->sole();

    expect($payEntry->entry_date->toDateString())->toBe('2026-10-16')
        ->and($payEntry->description)->toBe("سند صرف {$payVoucher->voucher_number}: Water bill");

    $payLines = $payEntry->lines()->with('account')->get();
    $payDebit = $payLines->firstWhere('debit', '>', 0);
    $payCredit = $payLines->firstWhere('credit', '>', 0);

    expect($payDebit->account->account_code)->toBe('54')
        ->and((float) $payDebit->debit)->toBe(80.0)
        ->and($payCredit->account->account_code)->toBe('121101')
        ->and((float) $payCredit->credit)->toBe(80.0);
});

test("client receipt credits the client's receivable leaf with client_id and lowers current_balance; client payment raises it", function (): void {
    $entity = Entity::create(['entity_type' => 'organization', 'name' => 'Benghazi Trading']);
    $client = Client::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'credit_limit' => 2000,
        'current_balance' => 1000.0,
    ]);

    // 1. Client receipt: receives 400 from client -> receivable credited, balance drops to 600
    $receiptResponse = $this->actingAs($this->owner)->postJson('/api/v1/treasury-vouchers', [
        'kind' => 'receipt',
        'cash_account_id' => $this->treasury->id,
        'client_id' => $client->id,
        'amount' => 400.0,
        'description' => 'Collection on debt',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $client->refresh();
    expect((float) $client->current_balance)->toBe(600.0);

    $voucher = TreasuryVoucher::findOrFail($receiptResponse->json('data.id'));
    expect($voucher->client_id)->toBe($client->id);

    $entry = JournalEntry::where('source_document_type', 'TreasuryVoucher')
        ->where('source_document_id', $voucher->id)
        ->sole();

    $receivableLine = $entry->lines->firstWhere('client_id', $client->id);
    expect($receivableLine)->not->toBeNull()
        ->and((float) $receivableLine->credit)->toBe(400.0)
        ->and((float) $receivableLine->debit)->toBe(0.0)
        ->and($receivableLine->account_id)->toBe($client->account_id);

    // 2. Client payment (refund): pays 150 to client -> receivable debited, balance raises to 750
    $payResponse = $this->actingAs($this->owner)->postJson('/api/v1/treasury-vouchers', [
        'kind' => 'payment',
        'cash_account_id' => $this->treasury->id,
        'client_id' => $client->id,
        'amount' => 150.0,
        'description' => 'Customer overpayment refund',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $client->refresh();
    expect((float) $client->current_balance)->toBe(750.0);

    $payVoucher = TreasuryVoucher::findOrFail($payResponse->json('data.id'));
    $payEntry = JournalEntry::where('source_document_type', 'TreasuryVoucher')
        ->where('source_document_id', $payVoucher->id)
        ->sole();

    $payReceivableLine = $payEntry->lines->firstWhere('client_id', $client->id);
    expect($payReceivableLine)->not->toBeNull()
        ->and((float) $payReceivableLine->debit)->toBe(150.0)
        ->and((float) $payReceivableLine->credit)->toBe(0.0)
        ->and($payReceivableLine->account_id)->toBe($client->account_id);
});

test('counter account that is a treasury account (e.g. 121104) is refused VOUCHER_COUNTER_IS_TREASURY', function (): void {
    $response = $this->actingAs($this->owner)->postJson('/api/v1/treasury-vouchers', [
        'kind' => 'receipt',
        'cash_account_id' => $this->treasury->id,
        'counter_account_code' => '121104',
        'amount' => 100.0,
        'description' => 'Attempting treasury to treasury via voucher',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(422);

    $response->assertJsonPath('code', 'VOUCHER_COUNTER_IS_TREASURY');
    expect(TreasuryVoucher::count())->toBe(0);
});

test('a USD treasury requires fx_rate and stamps currency/original_amount/fx_rate on both lines, LYD = amount × rate', function (): void {
    $usdAccount = Account::where('account_code', '121105')->first();
    $usdTreasury = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'USD Clearing Safe',
        'kind' => 'cash',
        'account_id' => $usdAccount->id,
        'currency' => 'USD',
        'balance' => 0,
    ]);

    // 1. Missing fx_rate should be rejected
    $this->actingAs($this->owner)->postJson('/api/v1/treasury-vouchers', [
        'kind' => 'receipt',
        'cash_account_id' => $usdTreasury->id,
        'counter_account_code' => '42',
        'amount' => 100.0,
        'description' => 'Foreign receipt without rate',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(422);

    // 2. With fx_rate = 5.25, amount = 100 USD -> amount_lyd = 525.0
    $response = $this->actingAs($this->owner)->postJson('/api/v1/treasury-vouchers', [
        'kind' => 'receipt',
        'cash_account_id' => $usdTreasury->id,
        'counter_account_code' => '42',
        'amount' => 100.0,
        'fx_rate' => 5.25,
        'description' => 'Foreign cash receipt',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $voucher = TreasuryVoucher::findOrFail($response->json('data.id'));
    expect($voucher->currency)->toBe('USD')
        ->and((float) $voucher->amount)->toBe(100.0)
        ->and((float) $voucher->fx_rate)->toBe(5.25)
        ->and((float) $voucher->amount_lyd)->toBe(525.0);

    $entry = JournalEntry::where('source_document_type', 'TreasuryVoucher')
        ->where('source_document_id', $voucher->id)
        ->sole();

    $lines = $entry->lines;
    expect($lines)->toHaveCount(2);

    foreach ($lines as $line) {
        expect($line->currency)->toBe('USD')
            ->and((float) $line->original_amount)->toBe(100.0)
            ->and((float) $line->fx_rate)->toBe(5.25);
    }

    $debit = $lines->firstWhere('debit', '>', 0);
    $credit = $lines->firstWhere('credit', '>', 0);

    expect((float) $debit->debit)->toBe(525.0)
        ->and((float) $credit->credit)->toBe(525.0);
});

test('voucher dated in a locked month → 422 PERIOD_LOCKED and no voucher row, no journal entry', function (): void {
    $accounting = app(AccountingService::class);

    // Post an entry to provision FY2026 and period 2026-09
    $accounting->postJournal('Seed September', [
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

    // Now attempt to post a voucher dated in the locked month
    $initialVouchersCount = TreasuryVoucher::count();
    $initialEntriesCount = JournalEntry::count();

    $response = $this->actingAs($this->owner)->postJson('/api/v1/treasury-vouchers', [
        'kind' => 'receipt',
        'cash_account_id' => $this->treasury->id,
        'counter_account_code' => '42',
        'amount' => 500.0,
        'voucher_date' => '2026-09-20',
        'description' => 'Late backdated voucher',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(422);

    $response->assertJsonPath('code', 'PERIOD_LOCKED');

    expect(TreasuryVoucher::count())->toBe($initialVouchersCount)
        ->and(JournalEntry::count())->toBe($initialEntriesCount);
});

test('cancel reverses the entry (balanced mirror linked via reversal_of_id), restores the client balance, second cancel → VOUCHER_ALREADY_CANCELLED', function (): void {
    $entity = Entity::create(['entity_type' => 'organization', 'name' => 'Tripoli Paints']);
    $client = Client::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'credit_limit' => 5000,
        'current_balance' => 1200.0,
    ]);

    // Create a receipt voucher
    $res = $this->actingAs($this->owner)->postJson('/api/v1/treasury-vouchers', [
        'kind' => 'receipt',
        'cash_account_id' => $this->treasury->id,
        'client_id' => $client->id,
        'amount' => 300.0,
        'description' => 'Payment towards invoice',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $voucherId = $res->json('data.id');
    $client->refresh();
    expect((float) $client->current_balance)->toBe(900.0);

    $voucher = TreasuryVoucher::findOrFail($voucherId);
    $originalEntryId = $voucher->journal_entry_id;

    // Cancel voucher
    $cancelRes = $this->actingAs($this->accountant)
        ->postJson("/api/v1/treasury-vouchers/{$voucherId}/cancel", [
            'reason' => 'Cheque bounced or mistake in receipt',
        ])
        ->assertStatus(200);

    $voucher->refresh();
    expect($voucher->status)->toBe(TreasuryVoucherStatus::Cancelled)
        ->and($voucher->cancel_reason)->toBe('Cheque bounced or mistake in receipt')
        ->and($voucher->cancelled_by_user_id)->toBe($this->accountant->id)
        ->and($voucher->cancelled_at)->not->toBeNull()
        ->and($voucher->cancel_journal_entry_id)->not->toBeNull();

    // Reversal entry verification
    $reversalEntry = JournalEntry::findOrFail($voucher->cancel_journal_entry_id);
    expect($reversalEntry->reversal_of_id)->toBe($originalEntryId)
        ->and($reversalEntry->reversal_reason)->toBe('Cheque bounced or mistake in receipt');

    // Client balance restored
    $client->refresh();
    expect((float) $client->current_balance)->toBe(1200.0);

    // Second cancel attempt
    $secondCancel = $this->actingAs($this->accountant)
        ->postJson("/api/v1/treasury-vouchers/{$voucherId}/cancel", [
            'reason' => 'Trying to cancel again',
        ])
        ->assertStatus(422);

    $secondCancel->assertJsonPath('code', 'VOUCHER_ALREADY_CANCELLED');
});

test("roles: a pos-cashier can't create; a treasury-officer can create but can't cancel; accounting-manager can cancel", function (): void {
    $payload = [
        'kind' => 'receipt',
        'cash_account_id' => $this->treasury->id,
        'counter_account_code' => '42',
        'amount' => 100.0,
        'description' => 'Role permission check',
        'operating_unit_id' => $this->unit->id,
    ];

    // 1. pos-cashier cannot create
    $this->actingAs($this->cashier)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/treasury-vouchers', $payload)
        ->assertStatus(403);

    // 2. treasury-officer CAN create
    $createRes = $this->actingAs($this->treasuryOfficer)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/treasury-vouchers', $payload)
        ->assertStatus(201);

    $voucherId = $createRes->json('data.id');

    // 3. treasury-officer CANNOT cancel
    $this->actingAs($this->treasuryOfficer)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/treasury-vouchers/{$voucherId}/cancel", [
            'reason' => 'Unauthorized cancellation',
        ])
        ->assertStatus(403);

    // 4. accounting-manager CAN cancel
    $this->actingAs($this->accountant)
        ->postJson("/api/v1/treasury-vouchers/{$voucherId}/cancel", [
            'reason' => 'Authorized manager cancellation',
        ])
        ->assertStatus(200);
});

test('list filters (kind, from/to inclusive of to, search)', function (): void {
    // Create 3 vouchers:
    // Voucher A: receipt on 2026-10-10, payee "Al-Nour LLC", desc "Advance payment"
    $vA = $this->actingAs($this->owner)->postJson('/api/v1/treasury-vouchers', [
        'kind' => 'receipt',
        'cash_account_id' => $this->treasury->id,
        'counter_account_code' => '42',
        'amount' => 100.0,
        'voucher_date' => '2026-10-10',
        'payee' => 'Al-Nour LLC',
        'description' => 'Advance payment',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201)->json('data');

    // Voucher B: payment on 2026-10-15, payee "Al-Majd Supplies", desc "Office supplies"
    $vB = $this->actingAs($this->owner)->postJson('/api/v1/treasury-vouchers', [
        'kind' => 'payment',
        'cash_account_id' => $this->treasury->id,
        'counter_account_code' => '54',
        'amount' => 200.0,
        'voucher_date' => '2026-10-15',
        'payee' => 'Al-Majd Supplies',
        'description' => 'Office supplies',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201)->json('data');

    // Voucher C: payment on 2026-10-18, payee "Sahara Transport", desc "Fleet logistics"
    $vC = $this->actingAs($this->owner)->postJson('/api/v1/treasury-vouchers', [
        'kind' => 'payment',
        'cash_account_id' => $this->treasury->id,
        'counter_account_code' => '54',
        'amount' => 300.0,
        'voucher_date' => '2026-10-18',
        'payee' => 'Sahara Transport',
        'description' => 'Fleet logistics',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201)->json('data');

    // Filter kind=receipt -> should return only vA
    $resKind = $this->actingAs($this->owner)
        ->getJson('/api/v1/treasury-vouchers?kind=receipt')
        ->assertStatus(200)
        ->json('data');

    expect($resKind)->toHaveCount(1)
        ->and($resKind[0]['id'])->toBe($vA['id']);

    // Filter from=2026-10-10 to=2026-10-15 (inclusive of to) -> should return vA and vB, not vC
    $resDate = $this->actingAs($this->owner)
        ->getJson('/api/v1/treasury-vouchers?from=2026-10-10&to=2026-10-15')
        ->assertStatus(200)
        ->json('data');

    $ids = collect($resDate)->pluck('id')->all();
    expect($ids)->toContain($vA['id'])
        ->and($ids)->toContain($vB['id'])
        ->and($ids)->not->toContain($vC['id']);

    // Filter search by payee or description
    $resSearch = $this->actingAs($this->owner)
        ->getJson('/api/v1/treasury-vouchers?search=Majd')
        ->assertStatus(200)
        ->json('data');

    expect($resSearch)->toHaveCount(1)
        ->and($resSearch[0]['id'])->toBe($vB['id']);

    // Search by voucher number
    $resSearchNum = $this->actingAs($this->owner)
        ->getJson("/api/v1/treasury-vouchers?search={$vA['voucher_number']}")
        ->assertStatus(200)
        ->json('data');

    expect($resSearchNum)->toHaveCount(1)
        ->and($resSearchNum[0]['id'])->toBe($vA['id']);
});

test('show returns single voucher resource with full details', function (): void {
    $res = $this->actingAs($this->owner)->postJson('/api/v1/treasury-vouchers', [
        'kind' => 'receipt',
        'cash_account_id' => $this->treasury->id,
        'counter_account_code' => '42',
        'amount' => 120.0,
        'payee' => 'Salem Baruni',
        'description' => 'Miscellaneous income',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $voucherId = $res->json('data.id');

    $show = $this->actingAs($this->owner)
        ->getJson("/api/v1/treasury-vouchers/{$voucherId}")
        ->assertStatus(200)
        ->json('data');

    expect($show['id'])->toBe($voucherId)
        ->and($show['voucher_number'])->toStartWith('RV-2026-')
        ->and($show['kind'])->toBe('receipt')
        ->and($show['kind_label'])->toBe('سند قبض')
        ->and($show['status'])->toBe('posted')
        ->and($show['status_label'])->toBe('مرحّل')
        ->and($show['cash_account']['id'])->toBe($this->treasury->id)
        ->and($show['counter_account']['code'])->toBe('42')
        ->and($show['journal_entry'])->not->toBeNull()
        ->and($show['created_by']['id'])->toBe($this->owner->id);
});

test('a unit-scoped user posts into their own unit even when another unit is sent', function (): void {
    $otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->unit->blueprint_id,
        'name' => 'Cutter',
        'code' => 'CUT-01',
        'unit_type' => 'manufactory',
        'status' => 'active',
    ]);

    $this->actingAs($this->treasuryOfficer)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/treasury-vouchers', [
            'kind' => 'receipt',
            'cash_account_id' => $this->treasury->id,
            'counter_account_code' => '42',
            'amount' => 10,
            'description' => 'Scrap sale',
            'operating_unit_id' => $otherUnit->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.operating_unit_id', $this->unit->id);

    $entry = JournalEntry::where('source_document_type', 'TreasuryVoucher')->sole();
    expect($entry->lines()->pluck('operating_unit_id')->unique()->all())->toBe([$this->unit->id]);
});
