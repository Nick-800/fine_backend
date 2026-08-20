<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Company;
use App\Models\CreditApprovalRequest;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\AccountingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);

    $makeUnit = fn (string $name, string $code) => OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => $name, 'code' => $code, 'unit_type' => 'store',
    ]);

    $this->store = $makeUnit('Showroom', 'STORE-01');
    $this->furnitureUnit = $makeUnit('Furniture Unit', 'FURN-01');

    $this->storeWarehouse = Warehouse::create([
        'operating_unit_id' => $this->store->id, 'name' => 'Store WH', 'code' => 'WH-S',
    ]);
    $this->furnitureWarehouse = Warehouse::create([
        'operating_unit_id' => $this->furnitureUnit->id, 'name' => 'Furniture WH', 'code' => 'WH-F',
    ]);

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Store Manager', 'slug' => 'store_manager']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => $this->store->id]);

    $entity = Entity::create(['entity_type' => 'organization', 'name' => 'Sahara Trading']);
    $this->client = Client::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->store->id,
        'credit_limit' => 1000,
        'current_balance' => 0,
    ]);

    $this->sofa = InventoryItem::create([
        'name' => 'Sofa', 'sku' => 'SOFA-1', 'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'each',
    ]);

    // Store holds 5 sofas at 150 cost each.
    StockLot::create([
        'inventory_item_id' => $this->sofa->id,
        'warehouse_id' => $this->storeWarehouse->id,
        'lot_number' => 'FG-STOCK-1',
        'quantity' => 5,
        'unit_cost' => 150,
        'status' => 'available',
    ]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->store->id]);

    $this->makeOrder = fn (array $overrides = [], float $qty = 2, float $price = 400) => ($this->api)()
        ->postJson('/api/v1/sales-orders', array_merge([
            'order_number' => 'SO-'.fake()->unique()->numberBetween(1000, 9999),
            'buyer_type' => 'client',
            'client_id' => $this->client->id,
            'lines' => [[
                'inventory_item_id' => $this->sofa->id,
                'quantity' => $qty,
                'unit_price' => $price,
            ]],
        ], $overrides))->json();
});

test('a sale within the credit limit confirms on submit', function () {
    $order = ($this->makeOrder)([], 2, 400); // 800 <= limit 1000

    ($this->api)()->postJson("/api/v1/sales-orders/{$order['id']}/submit")
        ->assertStatus(200)
        ->assertJsonPath('status', 'confirmed');
});

test('a sale over the limit escalates instead of confirming', function () {
    // SALE-01/02: 2 × 600 = 1200 > limit 1000 → blocked pending approval.
    $order = ($this->makeOrder)([], 2, 600);

    $res = ($this->api)()->postJson("/api/v1/sales-orders/{$order['id']}/submit")
        ->assertStatus(200)
        ->assertJsonPath('status', 'pending_approval')
        ->json();

    expect((float) $res['credit_approval_request']['amount_over_limit'])->toBe(200.0);

    // The order cannot be fulfilled while blocked.
    ($this->api)()->postJson("/api/v1/sales-orders/{$order['id']}/fulfill")
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});

test('approving the escalation unblocks the order and rejecting kills it', function () {
    $first = ($this->makeOrder)([], 2, 600);
    ($this->api)()->postJson("/api/v1/sales-orders/{$first['id']}/submit");

    $approval = CreditApprovalRequest::first();

    ($this->api)()->putJson("/api/v1/credit-approval-requests/{$approval->id}/approve")
        ->assertStatus(200);

    expect(SalesOrder::find($first['id'])->status->value)->toBe('confirmed');

    $second = ($this->makeOrder)([], 2, 700);
    ($this->api)()->postJson("/api/v1/sales-orders/{$second['id']}/submit");

    $approval2 = CreditApprovalRequest::where('sales_order_id', $second['id'])->first();
    ($this->api)()->putJson("/api/v1/credit-approval-requests/{$approval2->id}/reject")
        ->assertStatus(200);

    expect(SalesOrder::find($second['id'])->status->value)->toBe('rejected');
});

test('fulfillment issues stock, raises the client balance, and posts AR and COGS', function () {
    $order = ($this->makeOrder)([], 2, 400);
    ($this->api)()->postJson("/api/v1/sales-orders/{$order['id']}/submit");
    ($this->api)()->postJson("/api/v1/sales-orders/{$order['id']}/fulfill")
        ->assertStatus(200)
        ->assertJsonPath('status', 'fulfilled');

    // 2 of 5 sofas gone.
    expect((float) StockLot::where('lot_number', 'FG-STOCK-1')->value('quantity'))->toBe(3.0)
        // SALE-06: the client now owes the invoice.
        ->and((float) $this->client->fresh()->current_balance)->toBe(800.0);

    $tb = app(AccountingService::class)->trialBalance();
    $byCode = collect($tb['rows'])->keyBy('account_code');

    // AR 800, revenue 800, COGS 2×150=300 out of FG-Furniture.
    expect($tb['balanced'])->toBeTrue()
        ->and((float) $byCode['1300']['debit'])->toBe(800.0)
        ->and((float) $byCode['4100']['credit'])->toBe(800.0)
        ->and((float) $byCode['5100']['debit'])->toBe(300.0)
        ->and((float) $byCode['1134']['credit'])->toBe(300.0);
});

test('payments settle the balance and split paid from partially paid', function () {
    $order = ($this->makeOrder)([], 2, 400);
    ($this->api)()->postJson("/api/v1/sales-orders/{$order['id']}/submit");
    ($this->api)()->postJson("/api/v1/sales-orders/{$order['id']}/fulfill");

    ($this->api)()->postJson("/api/v1/sales-orders/{$order['id']}/record-payment", [
        'amount' => 500, 'payment_method' => 'cash',
    ])->assertStatus(200)->assertJsonPath('status', 'partially_paid');

    expect((float) $this->client->fresh()->current_balance)->toBe(300.0);

    ($this->api)()->postJson("/api/v1/sales-orders/{$order['id']}/record-payment", [
        'amount' => 300,
    ])->assertStatus(200)->assertJsonPath('status', 'paid');

    expect((float) $this->client->fresh()->current_balance)->toBe(0.0);

    // Overpayment is refused.
    ($this->api)()->postJson("/api/v1/sales-orders/{$order['id']}/record-payment", ['amount' => 1])
        ->assertStatus(422);
});

test('an internal transfer skips credit, moves stock between units, and posts at cost', function () {
    // SALE-03/08: even an absurd value sails past the credit gate.
    $order = ($this->makeOrder)([
        'buyer_type' => 'internal_unit',
        'buyer_unit_id' => $this->furnitureUnit->id,
        'client_id' => null,
    ], 2, 99999);

    ($this->api)()->postJson("/api/v1/sales-orders/{$order['id']}/submit")
        ->assertStatus(200)
        ->assertJsonPath('status', 'confirmed');

    ($this->api)()->postJson("/api/v1/sales-orders/{$order['id']}/fulfill")->assertStatus(200);

    // Stock left the store and arrived in the furniture unit at cost.
    $received = StockLot::withoutGlobalScopes()
        ->where('warehouse_id', $this->furnitureWarehouse->id)->first();

    expect($received)->not->toBeNull()
        ->and((float) $received->quantity)->toBe(2.0)
        ->and((float) $received->unit_cost)->toBe(150.0);

    // No AR, no revenue — only subledger movement, netting to zero per account.
    $tb = app(AccountingService::class)->trialBalance();
    $byCode = collect($tb['rows'])->keyBy('account_code');

    expect($tb['balanced'])->toBeTrue()
        ->and($byCode->has('1300'))->toBeFalse()
        ->and($byCode->has('4100'))->toBeFalse()
        ->and((float) $byCode['1134']['balance'])->toBe(0.0);

    // And no balance moved on any client.
    expect((float) $this->client->fresh()->current_balance)->toBe(0.0);

    ($this->api)()->postJson("/api/v1/sales-orders/{$order['id']}/complete")
        ->assertStatus(200)->assertJsonPath('status', 'completed');
});

test('pos checkout is one atomic step and the drawer report sees it', function () {
    // SALE-09: no draft, no fulfil step — sold means done.
    ($this->api)()->postJson('/api/v1/pos/sales', [
        'order_number' => 'POS-1001',
        'payment_method' => 'cash',
        'items' => [[
            'inventory_item_id' => $this->sofa->id, 'quantity' => 1, 'unit_price' => 450,
        ]],
    ])->assertStatus(201)
        ->assertJsonPath('status', 'paid')
        ->assertJsonPath('channel', 'pos');

    expect((float) StockLot::where('lot_number', 'FG-STOCK-1')->value('quantity'))->toBe(4.0);

    // Cash 450 in, revenue 450, COGS 150 — and the trial balance holds.
    $tb = app(AccountingService::class)->trialBalance();
    $byCode = collect($tb['rows'])->keyBy('account_code');

    expect($tb['balanced'])->toBeTrue()
        ->and((float) $byCode['1200']['debit'])->toBe(450.0)
        ->and((float) $byCode['4100']['credit'])->toBe(450.0);

    $report = ($this->api)()->getJson('/api/v1/pos/daily-report')->assertStatus(200)->json();

    expect($report['sales_count'])->toBe(1)
        ->and((float) $report['total'])->toBe(450.0)
        ->and((float) $report['by_method']['cash']['total'])->toBe(450.0);
});

test('closing the register records the drawer count against system cash', function () {
    ($this->api)()->postJson('/api/v1/pos/sales', [
        'order_number' => 'POS-2001',
        'payment_method' => 'cash',
        'items' => [['inventory_item_id' => $this->sofa->id, 'quantity' => 1, 'unit_price' => 450]],
    ])->assertStatus(201);

    // Card takings must not inflate the expected drawer cash.
    ($this->api)()->postJson('/api/v1/pos/sales', [
        'order_number' => 'POS-2002',
        'payment_method' => 'card',
        'items' => [['inventory_item_id' => $this->sofa->id, 'quantity' => 1, 'unit_price' => 300]],
    ])->assertStatus(201);

    $close = ($this->api)()->postJson('/api/v1/pos/daily-close', ['counted_cash' => 440])
        ->assertStatus(201)->json();

    expect((float) $close['expected_cash'])->toBe(450.0)
        ->and((float) $close['counted_cash'])->toBe(440.0)
        ->and((float) $close['difference'])->toBe(-10.0)
        ->and($close['sales_count'])->toBe(2)
        ->and((float) $close['total_sales'])->toBe(750.0);

    // The saved close is readable back for the same day.
    ($this->api)()->getJson('/api/v1/pos/daily-close')
        ->assertStatus(200)
        ->assertJsonPath('data.id', $close['id']);
});

test('a register day cannot be closed twice', function () {
    ($this->api)()->postJson('/api/v1/pos/daily-close', ['counted_cash' => 0])->assertStatus(201);

    ($this->api)()->postJson('/api/v1/pos/daily-close', ['counted_cash' => 0])
        ->assertStatus(422)
        ->assertJsonPath('code', 'POS_DAY_ALREADY_CLOSED');
});

test('a sale that stock cannot cover is refused whole', function () {
    $order = ($this->makeOrder)([], 2, 400);
    ($this->api)()->postJson("/api/v1/sales-orders/{$order['id']}/submit");

    // Drain the shelf behind the order's back.
    StockLot::where('lot_number', 'FG-STOCK-1')->update(['quantity' => 1]);

    ($this->api)()->postJson("/api/v1/sales-orders/{$order['id']}/fulfill")
        ->assertStatus(422)
        ->assertJsonPath('code', 'INSUFFICIENT_COMPONENT_STOCK');

    // Nothing was drawn, order still confirmed.
    expect((float) StockLot::where('lot_number', 'FG-STOCK-1')->value('quantity'))->toBe(1.0)
        ->and(SalesOrder::find($order['id'])->status->value)->toBe('confirmed');
});

test('an internal restock request needs approval before it can move stock', function () {
    // The store asks the furniture unit, which holds the goods.
    StockLot::create([
        'inventory_item_id' => $this->sofa->id,
        'warehouse_id' => $this->furnitureWarehouse->id,
        'lot_number' => 'FG-FURN-1',
        'quantity' => 10,
        'unit_cost' => 140,
        'status' => 'available',
    ]);

    $restock = ($this->api)()->postJson('/api/v1/internal-restock-requests', [
        'request_number' => 'RSR-1001',
        'source_unit_id' => $this->furnitureUnit->id,
        'lines' => [['inventory_item_id' => $this->sofa->id, 'quantity' => 4]],
    ])->assertStatus(201)->assertJsonPath('status', 'pending_approval')->json();

    // SALE-05: fulfilling before approval is refused.
    ($this->api)()->postJson("/api/v1/internal-restock-requests/{$restock['id']}/fulfill")
        ->assertStatus(422);

    ($this->api)()->putJson("/api/v1/internal-restock-requests/{$restock['id']}/approve")
        ->assertStatus(200);

    ($this->api)()->postJson("/api/v1/internal-restock-requests/{$restock['id']}/fulfill")
        ->assertStatus(200)
        ->assertJsonPath('status', 'fulfilled');

    // 4 left the furniture unit, 4 arrived in the store at cost.
    expect((float) StockLot::withoutGlobalScopes()->where('lot_number', 'FG-FURN-1')->value('quantity'))->toBe(6.0);

    $arrived = StockLot::where('warehouse_id', $this->storeWarehouse->id)
        ->where('lot_number', 'like', 'RST-%')->first();

    expect((float) $arrived->quantity)->toBe(4.0)
        ->and((float) $arrived->unit_cost)->toBe(140.0);

    expect(app(AccountingService::class)->trialBalance()['balanced'])->toBeTrue();
});

test('the invoice reflects the fulfilled order', function () {
    $order = ($this->makeOrder)([], 2, 400);

    // No invoice before fulfillment.
    ($this->api)()->getJson("/api/v1/sales-orders/{$order['id']}/invoice")->assertStatus(422);

    ($this->api)()->postJson("/api/v1/sales-orders/{$order['id']}/submit");
    ($this->api)()->postJson("/api/v1/sales-orders/{$order['id']}/fulfill");

    $invoice = ($this->api)()->getJson("/api/v1/sales-orders/{$order['id']}/invoice")
        ->assertStatus(200)->json();

    expect($invoice['buyer'])->toBe('Sahara Trading')
        ->and((float) $invoice['total_amount'])->toBe(800.0)
        ->and((float) $invoice['outstanding'])->toBe(800.0);
});
