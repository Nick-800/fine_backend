<?php

declare(strict_types=1);

use App\Enums\ChequeStatus;
use App\Enums\InventoryEventType;
use App\Enums\SalesOrderStatus;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Cheque;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\OperatingUnitAccount;
use App\Models\Role;
use App\Models\SalePayment;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->company = Company::create(['name' => 'Fine Test Co', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Store',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $blueprint->id,
        'name' => 'Misrata Showroom',
        'unit_type' => 'store',
        'currency' => 'LYD',
        'status' => 'active',
    ]);
    linkRevenueAccount($this->unit, '40204');

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $this->item = InventoryItem::create([
        'name' => 'Table',
        'code' => 'TBL-01',
        'item_type' => 'furniture_finished_good',
        'unit_of_measure' => 'piece',
    ]);

    $warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Misrata WH',
        'code' => 'WH-MIS',
    ]);

    foreach ([
        [InventoryEventType::Purchases, '1134'],
        [InventoryEventType::Sales, '40204'],
        [InventoryEventType::Cogs, '51'],
    ] as [$event, $code]) {
        OperatingUnitAccount::create([
            'operating_unit_id' => $this->unit->id,
            'event_type' => $event->value,
            'account_id' => Account::where('account_code', $code)->sole()->id,
        ]);
    }

    StockLot::create([
        'warehouse_id' => $warehouse->id,
        'inventory_item_id' => $this->item->id,
        'lot_number' => 'LOT-CHQ-1',
        'quantity' => 50,
        'unit_cost' => 60,
        'status' => 'available',
    ]);

    $this->drawer = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Cash Drawer',
        'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);

    $entity = Entity::create(['entity_type' => 'organization', 'name' => 'Naseem Furnishings']);
    $this->client = Client::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'credit_limit' => 5000,
        'current_balance' => 0,
    ]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);
});

test('collecting by cheque creates an in-portfolio cheque, posts one entry DR 124 / CR receivable, drops balance once, and settles sale', function (): void {
    // 1. Create a receivable sale of 300
    $sale = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'receivable',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 150],
        ],
    ])->assertCreated()->json();

    expect((float) $this->client->fresh()->current_balance)->toBe(300.0);

    // Initial journal entries count (sale entry: revenue + receivable + cogs + stock)
    $entriesBeforeCollection = JournalEntry::where('source_document_type', 'Cheque')->count();

    // 2. Collect full amount by cheque
    $response = ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'amount' => 300,
        'method' => 'cheque',
        'cheque' => [
            'cheque_no' => 'CHQ-8899',
            'bank_name' => 'Aman Bank',
            'drawer_name' => 'Naseem Furnishings',
            'due_date' => '2026-11-20',
            'is_certified' => true,
        ],
    ])->assertSuccessful();

    $response->assertJsonPath('amount_paid', fn ($v): bool => (float) $v === 300.0)
        ->assertJsonPath('status', SalesOrderStatus::Completed->value)
        ->assertJsonPath('payments.0.method', 'cheque')
        ->assertJsonPath('payments.0.cash_account_id', null)
        ->assertJsonPath('payments.0.cheque_id', fn ($v): bool => ! empty($v));

    $chequeId = $response->json('payments.0.cheque_id');
    $cheque = Cheque::findOrFail($chequeId);

    expect($cheque->status)->toBe(ChequeStatus::InPortfolio)
        ->and($cheque->cheque_no)->toBe('CHQ-8899')
        ->and($cheque->bank_name)->toBe('Aman Bank')
        ->and((float) $cheque->amount)->toBe(300.0)
        ->and($cheque->client_id)->toBe($this->client->id);

    // Balance drops exactly once: from 300 to 0
    expect((float) $this->client->fresh()->current_balance)->toBe(0.0);

    // Exactly one cheque receive journal entry was posted
    expect(JournalEntry::where('source_document_type', 'Cheque')->count())->toBe($entriesBeforeCollection + 1);

    $receiveEntry = JournalEntry::where('source_document_type', 'Cheque')
        ->where('source_document_id', $cheque->id)
        ->sole();

    $notesReceivableAccount = Account::where('account_code', '124')->value('id');
    $debitLine = JournalLine::where('journal_entry_id', $receiveEntry->id)
        ->where('account_id', $notesReceivableAccount)
        ->sole();
    $creditLine = JournalLine::where('journal_entry_id', $receiveEntry->id)
        ->where('account_id', '!=', $notesReceivableAccount)
        ->sole();

    expect((float) $debitLine->debit)->toBe(300.0)
        ->and((float) $creditLine->credit)->toBe(300.0)
        ->and($creditLine->client_id)->toBe($this->client->id);

    $payment = SalePayment::withoutGlobalScopes()->where('sales_order_id', $sale['id'])->sole();
    expect($payment->method)->toBe('cheque')
        ->and($payment->cheque_id)->toBe($cheque->id)
        ->and($payment->journal_entry_id)->toBe($receiveEntry->id)
        ->and($payment->cash_account_id)->toBeNull();
});

test('amount above outstanding is refused', function (): void {
    $sale = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'receivable',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 200],
        ],
    ])->assertCreated()->json();

    ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'amount' => 250,
        'method' => 'cheque',
        'cheque' => [
            'cheque_no' => 'CHQ-OVER',
            'bank_name' => 'Wahda Bank',
            'due_date' => '2026-11-30',
        ],
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PAYMENT_EXCEEDS_OUTSTANDING');

    expect(Cheque::count())->toBe(0)
        ->and(SalePayment::withoutGlobalScopes()->where('sales_order_id', $sale['id'])->count())->toBe(0);
});

test('cheque payments are excluded from expected cash in report and close', function (): void {
    // 1. Cash sale of 100
    ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $this->drawer->id,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100],
        ],
    ])->assertCreated();

    // 2. Receivable sale of 200 collected with cheque of 200
    $creditSale = ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'receivable',
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 200],
        ],
    ])->assertCreated()->json();

    ($this->api)()->postJson("/api/v1/sales/{$creditSale['id']}/payments", [
        'amount' => 200,
        'method' => 'cheque',
        'cheque' => [
            'cheque_no' => 'CHQ-EXCLUDED',
            'bank_name' => 'Sahara Bank',
            'due_date' => '2026-12-01',
        ],
    ])->assertSuccessful();

    // 3. Daily report only counts the cash payment (100) in expected_cash
    $report = ($this->api)()->getJson('/api/v1/pos/daily-report')->assertOk()->json();
    expect((float) $report['expected_cash'])->toBe(100.0);

    // 4. Daily close matches 100 exactly with no difference
    $close = ($this->api)()->postJson('/api/v1/pos/daily-close', [
        'counted_cash' => 100,
    ])->assertCreated()->json();

    expect((float) $close['expected_cash'])->toBe(100.0)
        ->and((float) $close['counted_cash'])->toBe(100.0)
        ->and((float) $close['difference'])->toBe(0.0)
        ->and($close['journal_entry_id'])->toBeNull();
});
