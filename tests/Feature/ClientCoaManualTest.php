<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\SalesOrderService;
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

    $this->parentArAccount = Account::where('account_code', '13')->firstOrFail();
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
                'account_code' => '130001',
                'name' => 'عميل - شركة النور',
                'currency' => 'LYD',
            ],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.account.account_code', '130001')
        ->assertJsonPath('data.account.name', 'عميل - شركة النور');

    $this->assertDatabaseHas('accounts', [
        'account_code' => '130001',
        'parent_account_id' => $this->parentArAccount->id,
    ]);

    $createdClient = Client::where('account_id', $response->json('data.account_id'))->first();
    expect($createdClient)->not->toBeNull();
});

test('sales fulfillment and payment directly routes to client linked account', function () {
    // 1. Create client with linked sub-account
    $clientAccount = Account::create([
        'chart_of_accounts_id' => $this->parentArAccount->chart_of_accounts_id,
        'parent_account_id' => $this->parentArAccount->id,
        'account_code' => '130002',
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
        'sku' => 'FB-01',
        'item_type' => 'furniture_finished_good',
        'unit_of_measure' => 'piece',
    ]);

    StockLot::create([
        'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $item->id,
        'lot_number' => 'LOT-01',
        'quantity' => 10,
        'unit_cost' => 50,
        'status' => 'available',
    ]);

    // 2. Create sales order via API with lines
    $orderRes = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->operatingUnit->id])
        ->postJson('/api/v1/sales-orders', [
            'order_number' => 'SO-TEST-001',
            'buyer_type' => 'client',
            'client_id' => $client->id,
            'lines' => [[
                'inventory_item_id' => $item->id,
                'quantity' => 2,
                'unit_price' => 100,
            ]],
        ])
        ->assertStatus(201)
        ->json();

    $order = SalesOrder::findOrFail($orderRes['id']);

    $service = app(SalesOrderService::class);
    $service->submit($order);
    $order->refresh();
    $service->fulfill($order);

    // 3. Verify journal entry debited 130002 (client account) instead of 13
    $fulfillmentLine = JournalLine::where('account_id', $clientAccount->id)
        ->where('debit', 200)
        ->first();

    expect($fulfillmentLine)->not->toBeNull();

    // 4. Record payment on the sales order for 200 LYD
    $service->recordPayment($order, 200);

    // 5. Verify journal entry credited 130002 (client account)
    $paymentLine = JournalLine::where('account_id', $clientAccount->id)
        ->where('credit', 200)
        ->first();

    expect($paymentLine)->not->toBeNull();
});
