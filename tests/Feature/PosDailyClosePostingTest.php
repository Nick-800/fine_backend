<?php

declare(strict_types=1);

use App\Enums\FiscalPeriodStatus;
use App\Enums\InventoryEventType;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\OperatingUnitAccount;
use App\Models\PosDailyClose;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\FiscalCalendar;
use Carbon\Carbon;
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
        'name' => 'Tripoli Central',
        'unit_type' => 'store',
        'currency' => 'LYD',
        'status' => 'active',
    ]);
    linkRevenueAccount($this->unit, '40204');

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $this->item = InventoryItem::create([
        'name' => 'Desk',
        'code' => 'DSK-01',
        'item_type' => 'furniture_finished_good',
        'unit_of_measure' => 'piece',
    ]);

    $warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Main Warehouse',
        'code' => 'WH-MAIN',
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
        'lot_number' => 'LOT-CLOSE-1',
        'quantity' => 100,
        'unit_cost' => 50,
        'status' => 'available',
    ]);

    $this->drawerA = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Cash Drawer A',
        'kind' => 'cash',
        'account_id' => Account::where('account_code', '121101')->value('id'),
    ]);

    $this->drawerB = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Cash Drawer B',
        'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);

    $entity = Entity::create(['entity_type' => 'organization', 'name' => 'Al-Madina Stores']);
    $this->client = Client::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'credit_limit' => 5000,
        'current_balance' => 0,
    ]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $this->makeSale = fn (CashAccount $drawer, float $price = 200) => ($this->api)()->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'cash',
        'cash_account_id' => $drawer->id,
        'lines' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => $price],
        ],
    ])->assertCreated();
});

test('shortage posts DR 625 / CR treasury with the close date', function (): void {
    ($this->makeSale)($this->drawerA, 200);

    $close = ($this->api)()->postJson('/api/v1/pos/daily-close', [
        'counted_cash' => 170,
    ])->assertCreated()->json();

    expect((float) $close['expected_cash'])->toBe(200.0)
        ->and((float) $close['counted_cash'])->toBe(170.0)
        ->and((float) $close['difference'])->toBe(-30.0)
        ->and($close['cash_account_id'])->toBe($this->drawerA->id)
        ->and($close['journal_entry_id'])->not->toBeNull();

    $entry = JournalEntry::findOrFail($close['journal_entry_id']);
    expect($entry->source_document_type)->toBe('PosDailyClose')
        ->and($entry->source_document_id)->toBe($close['id'])
        ->and($entry->entry_date->toDateString())->toBe(Carbon::parse($close['close_date'])->toDateString());

    $shortageAccountId = Account::where('account_code', '625')->value('id');
    $treasuryAccountId = Account::where('account_code', '121101')->value('id');

    $shortageLine = JournalLine::where('journal_entry_id', $entry->id)
        ->where('account_id', $shortageAccountId)
        ->sole();
    $treasuryLine = JournalLine::where('journal_entry_id', $entry->id)
        ->where('account_id', $treasuryAccountId)
        ->sole();

    expect((float) $shortageLine->debit)->toBe(30.0)
        ->and((float) $shortageLine->credit)->toBe(0.0)
        ->and($shortageLine->operating_unit_id)->toBe($this->unit->id)
        ->and((float) $treasuryLine->debit)->toBe(0.0)
        ->and((float) $treasuryLine->credit)->toBe(30.0)
        ->and($treasuryLine->operating_unit_id)->toBe($this->unit->id);
});

test('surplus posts DR treasury / CR 44', function (): void {
    ($this->makeSale)($this->drawerA, 200);

    $close = ($this->api)()->postJson('/api/v1/pos/daily-close', [
        'counted_cash' => 225,
    ])->assertCreated()->json();

    expect((float) $close['expected_cash'])->toBe(200.0)
        ->and((float) $close['counted_cash'])->toBe(225.0)
        ->and((float) $close['difference'])->toBe(25.0)
        ->and($close['cash_account_id'])->toBe($this->drawerA->id)
        ->and($close['journal_entry_id'])->not->toBeNull();

    $entry = JournalEntry::findOrFail($close['journal_entry_id']);
    expect($entry->source_document_type)->toBe('PosDailyClose')
        ->and($entry->source_document_id)->toBe($close['id'])
        ->and($entry->entry_date->toDateString())->toBe(Carbon::parse($close['close_date'])->toDateString());

    $overageAccountId = Account::where('account_code', '44')->value('id');
    $treasuryAccountId = Account::where('account_code', '121101')->value('id');

    $treasuryLine = JournalLine::where('journal_entry_id', $entry->id)
        ->where('account_id', $treasuryAccountId)
        ->sole();
    $overageLine = JournalLine::where('journal_entry_id', $entry->id)
        ->where('account_id', $overageAccountId)
        ->sole();

    expect((float) $treasuryLine->debit)->toBe(25.0)
        ->and((float) $treasuryLine->credit)->toBe(0.0)
        ->and($treasuryLine->operating_unit_id)->toBe($this->unit->id)
        ->and((float) $overageLine->debit)->toBe(0.0)
        ->and((float) $overageLine->credit)->toBe(25.0)
        ->and($overageLine->operating_unit_id)->toBe($this->unit->id);
});

test('exact count posts nothing', function (): void {
    ($this->makeSale)($this->drawerA, 200);

    $close = ($this->api)()->postJson('/api/v1/pos/daily-close', [
        'counted_cash' => 200,
    ])->assertCreated()->json();

    expect((float) $close['expected_cash'])->toBe(200.0)
        ->and((float) $close['counted_cash'])->toBe(200.0)
        ->and((float) $close['difference'])->toBe(0.0)
        ->and($close['cash_account_id'])->toBe($this->drawerA->id)
        ->and($close['journal_entry_id'])->toBeNull();

    expect(JournalEntry::where('source_document_type', 'PosDailyClose')->count())->toBe(0);
});

test('expected only counts cash into the chosen treasury', function (): void {
    ($this->makeSale)($this->drawerA, 100);
    ($this->makeSale)($this->drawerB, 60);

    // Daily report shows total expected cash and breakdown by account
    $report = ($this->api)()->getJson('/api/v1/pos/daily-report')->assertOk()->json();

    expect((float) $report['expected_cash'])->toBe(160.0);
    $byAccount = collect($report['expected_cash_by_account'])->keyBy('cash_account_id');
    expect((float) $byAccount->get($this->drawerA->id)['expected'])->toBe(100.0)
        ->and((float) $byAccount->get($this->drawerB->id)['expected'])->toBe(60.0);

    // Closing specifically for Drawer A counts only Drawer A's sales
    $closeA = ($this->api)()->postJson('/api/v1/pos/daily-close', [
        'cash_account_id' => $this->drawerA->id,
        'counted_cash' => 100,
    ])->assertCreated()->json();

    expect((float) $closeA['expected_cash'])->toBe(100.0)
        ->and((float) $closeA['difference'])->toBe(0.0)
        ->and($closeA['cash_account_id'])->toBe($this->drawerA->id)
        ->and($closeA['journal_entry_id'])->toBeNull();
});

test('two cash treasuries without cash_account_id is refused with CASH_ACCOUNT_REQUIRED', function (): void {
    ($this->makeSale)($this->drawerA, 100);
    ($this->makeSale)($this->drawerB, 50);

    $response = ($this->api)()->postJson('/api/v1/pos/daily-close', [
        'counted_cash' => 150,
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'CASH_ACCOUNT_REQUIRED');

    $treasuries = collect($response->json('treasuries'))->pluck('id')->all();
    expect($treasuries)->toContain($this->drawerA->id, $this->drawerB->id);
    expect(PosDailyClose::withoutGlobalScopes()->count())->toBe(0);
});

test('locked month refuses close with difference and leaves no close row', function (): void {
    ($this->makeSale)($this->drawerA, 100);

    $calendar = app(FiscalCalendar::class);
    $today = now('Africa/Tripoli')->toDateString();
    $period = $calendar->periodFor($today, $this->company->id);
    $period->update(['status' => FiscalPeriodStatus::Locked]);

    ($this->api)()->postJson('/api/v1/pos/daily-close', [
        'counted_cash' => 80,
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PERIOD_LOCKED');

    expect(PosDailyClose::withoutGlobalScopes()->count())->toBe(0);
});
