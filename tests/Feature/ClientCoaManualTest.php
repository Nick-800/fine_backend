<?php

declare(strict_types=1);

use App\Enums\InventoryEventType;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\OperatingUnitAccount;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Test Company', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Standard Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->operatingUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Store Unit',
        'unit_type' => 'store',
        'currency' => 'LYD',
        'status' => 'active',
    ]);

    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->operatingUnit->id,
        'name' => 'Store WH',
        'code' => 'WH-ST',
    ]);

    $this->role = Role::create([
        'name' => 'Admin',
        'slug' => 'admin',
    ]);

    $this->user = User::factory()->create([
        'must_change_password' => false,
    ]);

    UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => $this->role->id,
        'operating_unit_id' => $this->operatingUnit->id,
    ]);

    // Receivables live under 122 الزبائن والعملاء — 13 is inventory in the unified chart.
    $this->parentArAccount = Account::where('account_code', '122')->firstOrFail();
});

test('operator can create client and manually provision dedicated COA account', function () {
    $response = $this->actingAs($this->user)
        ->withHeader('X-Operating-Unit-ID', $this->operatingUnit->id)
        ->postJson('/api/v1/clients', [
            'operating_unit_id' => $this->operatingUnit->id,
            'name' => 'Al-Nour Corp',
            'tax_number' => '123456',
            'credit_limit' => 5000,
            'payment_terms_days' => 45,
            'city' => 'Benghazi',
            'coa_action' => 'create_new',
            'new_account' => [
                'parent_account_id' => $this->parentArAccount->id,
                'account_code' => '122001',
                'name' => 'عميل - شركة النور',
                'currency' => 'LYD',
            ],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.account.account_code', '122001')
        ->assertJsonPath('data.account.name', 'عميل - شركة النور');

    $this->assertDatabaseHas('accounts', [
        'account_code' => '122001',
        'parent_account_id' => $this->parentArAccount->id,
    ]);

    $createdClient = Client::where('account_id', $response->json('data.account_id'))->first();
    expect($createdClient)->not->toBeNull();
});

test('a receivable sale and its collection route to the client linked account', function () {
    // 1. Create client with linked sub-account
    $clientAccount = Account::create([
        'chart_of_accounts_id' => $this->parentArAccount->chart_of_accounts_id,
        'parent_account_id' => $this->parentArAccount->id,
        'account_code' => '122002',
        'name' => 'عميل - الواحة',
        'type' => 'asset',
        'section' => $this->parentArAccount->section,
        'currency' => 'LYD',
    ]);

    $entity = Entity::create(['entity_type' => 'organization', 'name' => 'Al-Waha Traders']);
    $client = Client::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->operatingUnit->id,
        'credit_limit' => 10000,
        'current_balance' => 0,
        'account_id' => $clientAccount->id,
    ]);

    $item = InventoryItem::create([
        'name' => 'Foam Block',
        'code' => 'FB-01',
        'item_type' => 'furniture_finished_good',
        'unit_of_measure' => 'piece',
    ]);

    // Link per-event overrides the new posting guards now require. These
    // live on the operating unit — every item this unit transacts inherits
    // these accounts.
    foreach ([
        [InventoryEventType::Purchases, Account::where('account_code', '1134')->sole()->id],
        [InventoryEventType::Sales, Account::where('account_code', '41')->sole()->id],
        [InventoryEventType::Cogs, Account::where('account_code', '51')->sole()->id],
    ] as [$event, $accountId]) {
        OperatingUnitAccount::create([
            'operating_unit_id' => $this->operatingUnit->id,
            'event_type' => $event->value,
            'account_id' => $accountId,
        ]);
    }

    StockLot::create([
        'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $item->id,
        'lot_number' => 'LOT-01',
        'quantity' => 10,
        'unit_cost' => 50,
        'status' => 'available',
    ]);

    $drawer = CashAccount::create([
        'operating_unit_id' => $this->operatingUnit->id, 'name' => 'Drawer', 'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);

    $api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->operatingUnit->id]);

    // 2. Sell on the client's receivable at the POS.
    $sale = $api()->postJson('/api/v1/sales', [
        'client_id' => $client->id,
        'payment_method' => 'receivable',
        'lines' => [[
            'inventory_item_id' => $item->id,
            'quantity' => 2,
            'unit_price' => 100,
        ]],
    ])->assertCreated()->json();

    // 3. The sale debited 122002 (the client's own account), not a header.
    $saleLine = JournalLine::where('account_id', $clientAccount->id)
        ->where('debit', 200)
        ->first();

    expect($saleLine)->not->toBeNull();

    // 4. Collect the 200 LYD into the drawer.
    $api()->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'amount' => 200, 'method' => 'cash', 'cash_account_id' => $drawer->id,
    ])->assertSuccessful();

    // 5. The collection credited 122002.
    $paymentLine = JournalLine::where('account_id', $clientAccount->id)
        ->where('credit', 200)
        ->first();

    expect($paymentLine)->not->toBeNull();
});
