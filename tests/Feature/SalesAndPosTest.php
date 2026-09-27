<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Bundle;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\CreditApprovalRequest;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\AccountingService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);
    $this->seed(ChartOfAccountsTestSeeder::class);

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

    $this->drawer = CashAccount::create([
        'operating_unit_id' => $this->store->id, 'name' => 'خزينة الصالة', 'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);
    $this->bank = CashAccount::create([
        'operating_unit_id' => $this->store->id, 'name' => 'مصرف الجمهورية', 'kind' => 'bank',
        'account_id' => Account::where('account_code', '1212')->value('id'),
    ]);

    $this->sofa = InventoryItem::create([
        'name' => 'Sofa', 'code' => 'SOFA-1', 'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'each',
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

    $this->api = fn (?User $as = null) => $this->actingAs($as ?? $this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->store->id]);

    // A cash sale of sofas to the client, overridable per test.
    $this->sell = fn (array $overrides = [], float $qty = 1, float $price = 450) => ($this->api)()
        ->postJson('/api/v1/sales', array_merge([
            'client_id' => $this->client->id,
            'payment_method' => 'cash',
            'cash_account_id' => $this->drawer->id,
            'lines' => [[
                'inventory_item_id' => $this->sofa->id,
                'quantity' => $qty,
                'unit_price' => $price,
            ]],
        ], $overrides));

    $this->balances = function (): Collection {
        $tb = app(AccountingService::class)->trialBalance();
        expect($tb['balanced'])->toBeTrue();

        return collect($tb['rows'])->keyBy('account_code');
    };
});

test('a cash sale is one atomic step: stock out, cash into the chosen treasury, revenue and cost booked', function () {
    $sale = ($this->sell)()
        ->assertCreated()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('fulfillment_status', 'delivered')
        ->assertJsonPath('payment_method', 'cash')
        ->assertJsonPath('channel', 'pos')
        ->json();

    expect($sale['order_number'])->toBe('S-'.now()->format('Y').'-00001')
        ->and((float) StockLot::where('lot_number', 'FG-STOCK-1')->value('quantity'))->toBe(4.0);

    // Cash 450 into the showroom treasury (121102), revenue 450, COGS 150 out of 1134.
    $byCode = ($this->balances)();
    expect((float) $byCode['121102']['debit'])->toBe(450.0)
        ->and((float) $byCode['41']['credit'])->toBe(450.0)
        ->and((float) $byCode['51']['debit'])->toBe(150.0)
        ->and((float) $byCode['1134']['credit'])->toBe(150.0)
        ->and($byCode->has('12'))->toBeFalse();

    $payment = SalePayment::sole();
    expect((float) $payment->amount)->toBe(450.0)
        ->and($payment->cash_account_id)->toBe($this->drawer->id)
        ->and($payment->journal_entry_id)->not->toBeNull();
});

test('a bank sale lands in the bank account', function () {
    ($this->sell)(['payment_method' => 'bank', 'cash_account_id' => $this->bank->id])->assertCreated();

    expect((float) ($this->balances)()['1212']['debit'])->toBe(450.0);
});

test('revenue goes to the unit own sales account when it has one', function () {
    $this->store->update(['revenue_account_id' => Account::where('account_code', '40202')->value('id')]);

    ($this->sell)()->assertCreated();

    $byCode = ($this->balances)();
    expect((float) $byCode['40202']['credit'])->toBe(450.0)
        ->and($byCode->has('41'))->toBeFalse();
});

test('every sale needs a registered buyer, a payment method and a usable treasury', function () {
    ($this->sell)(['client_id' => null])
        ->assertUnprocessable()->assertJsonPath('code', 'BUYER_REQUIRED');

    ($this->sell)(['payment_method' => null])
        ->assertUnprocessable()->assertJsonPath('code', 'PAYMENT_METHOD_REQUIRED');

    ($this->sell)(['cash_account_id' => null])
        ->assertUnprocessable()->assertJsonPath('code', 'TREASURY_REQUIRED');

    ($this->sell)(['cash_account_id' => $this->bank->id])
        ->assertUnprocessable()->assertJsonPath('code', 'TREASURY_KIND_MISMATCH');

    $loose = CashAccount::create(['operating_unit_id' => $this->store->id, 'name' => 'Loose', 'kind' => 'cash']);
    ($this->sell)(['cash_account_id' => $loose->id])
        ->assertUnprocessable()->assertJsonPath('code', 'TREASURY_NOT_LINKED');

    // Nothing sold, nothing moved.
    expect(SalesOrder::count())->toBe(0)
        ->and((float) StockLot::where('lot_number', 'FG-STOCK-1')->value('quantity'))->toBe(5.0);
});

test('a client registered in another unit cannot be sold to here', function () {
    $otherEntity = Entity::create(['entity_type' => 'individual', 'name' => 'Elsewhere']);
    $other = Client::create(['entity_id' => $otherEntity->id, 'operating_unit_id' => $this->furnitureUnit->id]);

    ($this->sell)(['client_id' => $other->id])
        ->assertUnprocessable()->assertJsonPath('code', 'CLIENT_NOT_FOUND');
});

test('retrying a checkout with the same request id returns the same sale instead of selling twice', function () {
    $requestId = (string) Str::uuid();

    $first = ($this->sell)(['client_request_id' => $requestId])->assertCreated()->json('id');
    $second = ($this->sell)(['client_request_id' => $requestId])->assertCreated()->json('id');

    expect($second)->toBe($first)
        ->and(SalesOrder::count())->toBe(1)
        ->and((float) StockLot::where('lot_number', 'FG-STOCK-1')->value('quantity'))->toBe(4.0);
});

test('a receivable sale within the limit books the client receivable under 122 and raises the balance', function () {
    ($this->sell)(['payment_method' => 'receivable', 'cash_account_id' => null], 2, 400)
        ->assertCreated()
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('amount_paid', '0.0000');

    expect((float) $this->client->fresh()->current_balance)->toBe(800.0)
        ->and(SalePayment::count())->toBe(0);

    // Never inventory header 13 — the client has no own account, so the 122 header.
    $byCode = ($this->balances)();
    expect((float) $byCode['122']['debit'])->toBe(800.0)
        ->and($byCode->has('13'))->toBeFalse();
});

test('a receivable sale over the limit waits for a manager with nothing moved', function () {
    // SALE-01/02: 2 × 600 = 1200 > limit 1000.
    $sale = ($this->sell)(['payment_method' => 'receivable', 'cash_account_id' => null], 2, 600)
        ->assertCreated()
        ->assertJsonPath('status', 'pending_approval')
        ->json();

    expect((float) $sale['credit_approval_request']['amount_over_limit'])->toBe(200.0)
        ->and((float) StockLot::where('lot_number', 'FG-STOCK-1')->value('quantity'))->toBe(5.0)
        ->and(JournalEntry::count())->toBe(0)
        ->and((float) $this->client->fresh()->current_balance)->toBe(0.0);

    // No invoice for a sale that has not happened.
    ($this->api)()->getJson("/api/v1/sales/{$sale['id']}/invoice")
        ->assertUnprocessable()->assertJsonPath('code', 'NOT_SOLD');
});

test('approving an over-limit sale completes it; rejecting kills it', function () {
    $first = ($this->sell)(['payment_method' => 'receivable', 'cash_account_id' => null], 2, 600)->json();
    $approval = CreditApprovalRequest::where('sales_order_id', $first['id'])->sole();

    ($this->api)()->getJson('/api/v1/credit-approval-requests?status=pending')
        ->assertSuccessful()->assertJsonPath('total', 1);

    ($this->api)()->putJson("/api/v1/credit-approval-requests/{$approval->id}/approve")->assertSuccessful();

    expect(SalesOrder::find($first['id'])->status->value)->toBe('open')
        ->and((float) StockLot::where('lot_number', 'FG-STOCK-1')->value('quantity'))->toBe(3.0)
        ->and((float) $this->client->fresh()->current_balance)->toBe(1200.0);

    $second = ($this->sell)(['payment_method' => 'receivable', 'cash_account_id' => null], 1, 700)->json();
    $approval2 = CreditApprovalRequest::where('sales_order_id', $second['id'])->sole();

    ($this->api)()->putJson("/api/v1/credit-approval-requests/{$approval2->id}/reject")->assertSuccessful();

    expect(SalesOrder::find($second['id'])->status->value)->toBe('rejected')
        ->and((float) StockLot::where('lot_number', 'FG-STOCK-1')->value('quantity'))->toBe(3.0);

    // A decided request cannot be decided again.
    ($this->api)()->putJson("/api/v1/credit-approval-requests/{$approval2->id}/approve")
        ->assertUnprocessable()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});

test('a cashier sells but cannot approve credit', function () {
    $cashier = User::factory()->create(['must_change_password' => false]);
    $cashierRole = Role::create(['name' => 'Cashier', 'slug' => 'pos-cashier']);
    UserRole::create(['user_id' => $cashier->id, 'role_id' => $cashierRole->id, 'operating_unit_id' => $this->store->id]);

    $sale = ($this->api)($cashier)->postJson('/api/v1/sales', [
        'client_id' => $this->client->id,
        'payment_method' => 'receivable',
        'lines' => [['inventory_item_id' => $this->sofa->id, 'quantity' => 2, 'unit_price' => 600]],
    ])->assertCreated()->json();

    $approval = CreditApprovalRequest::where('sales_order_id', $sale['id'])->sole();

    ($this->api)($cashier)->putJson("/api/v1/credit-approval-requests/{$approval->id}/approve")
        ->assertForbidden()->assertJsonPath('code', 'ACCESS_DENIED');
});

test('collections settle a receivable into a chosen treasury', function () {
    $sale = ($this->sell)(['payment_method' => 'receivable', 'cash_account_id' => null], 2, 400)->json();

    ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'amount' => 500, 'method' => 'cash', 'cash_account_id' => $this->drawer->id,
    ])->assertSuccessful()->assertJsonPath('status', 'open');

    expect((float) $this->client->fresh()->current_balance)->toBe(300.0);

    ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'amount' => 300, 'method' => 'bank', 'cash_account_id' => $this->bank->id,
    ])->assertSuccessful()->assertJsonPath('status', 'completed');

    expect((float) $this->client->fresh()->current_balance)->toBe(0.0)
        ->and(SalePayment::count())->toBe(2);

    ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'amount' => 1, 'method' => 'cash', 'cash_account_id' => $this->drawer->id,
    ])->assertUnprocessable()->assertJsonPath('code', 'PAYMENT_EXCEEDS_OUTSTANDING');

    $byCode = ($this->balances)();
    expect((float) $byCode['122']['balance'])->toBe(0.0)
        ->and((float) $byCode['121102']['debit'])->toBe(500.0)
        ->and((float) $byCode['1212']['debit'])->toBe(300.0);
});

test('a paid sale has nothing to collect', function () {
    $sale = ($this->sell)()->json();

    ($this->api)()->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'amount' => 10, 'method' => 'cash', 'cash_account_id' => $this->drawer->id,
    ])->assertUnprocessable()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});

test('an internal transfer skips credit and payment and moves stock at cost, keeping each piece size', function () {
    $piece = InventoryItem::create([
        'name' => 'Cut piece D25', 'code' => 'CUT-D25', 'item_type' => 'cut_template_piece', 'unit_of_measure' => 'each',
    ]);
    $lot = StockLot::create([
        'inventory_item_id' => $piece->id, 'warehouse_id' => $this->storeWarehouse->id,
        'lot_number' => 'CUT-1', 'quantity' => 1, 'unit_cost' => 80, 'status' => 'available',
        'length_m' => 2, 'width_m' => 0.7, 'height_m' => 0.1,
    ]);

    // SALE-03/08: typed prices are ignored — the transfer is at cost.
    ($this->api)()->postJson('/api/v1/sales', [
        'buyer_unit_id' => $this->furnitureUnit->id,
        'lines' => [
            ['inventory_item_id' => $this->sofa->id, 'quantity' => 2, 'unit_price' => 99999],
            ['inventory_item_id' => $piece->id, 'stock_lot_id' => $lot->id, 'quantity' => 1, 'unit_price' => 0],
        ],
    ])->assertCreated()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('buyer_type', 'internal_unit')
        ->assertJsonPath('total_amount', '380.0000');

    $received = StockLot::withoutGlobalScopes()->where('warehouse_id', $this->furnitureWarehouse->id)->get();
    $sofas = $received->firstWhere('inventory_item_id', $this->sofa->id);
    $cutPiece = $received->firstWhere('inventory_item_id', $piece->id);

    expect((float) $sofas->quantity)->toBe(2.0)
        ->and((float) $sofas->unit_cost)->toBe(150.0)
        ->and((float) $cutPiece->length_m)->toBe(2.0)
        ->and((float) $cutPiece->width_m)->toBe(0.7)
        ->and((float) $cutPiece->height_m)->toBe(0.1)
        ->and($cutPiece->source_stock_lot_id)->toBe($lot->id);

    // No receivable, no revenue — only subledger movement, netting to zero per account.
    $byCode = ($this->balances)();
    expect($byCode->has('122'))->toBeFalse()
        ->and($byCode->has('41'))->toBeFalse()
        ->and((float) $byCode['1134']['balance'])->toBe(0.0)
        ->and((float) $byCode['1132']['balance'])->toBe(0.0)
        ->and((float) $this->client->fresh()->current_balance)->toBe(0.0);
});

test('an internal transfer carries inventory items only', function () {
    $bundle = Bundle::create(['name' => 'Arabic sofa']);

    ($this->api)()->postJson('/api/v1/sales', [
        'buyer_unit_id' => $this->furnitureUnit->id,
        'lines' => [['line_type' => 'bundle', 'bundle_id' => $bundle->id, 'quantity' => 1, 'unit_price' => 0]],
    ])->assertUnprocessable()->assertJsonPath('code', 'INTERNAL_BUNDLE_NOT_ALLOWED');

    ($this->api)()->postJson('/api/v1/sales', [
        'buyer_unit_id' => $this->store->id,
        'lines' => [['inventory_item_id' => $this->sofa->id, 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonPath('code', 'SELF_TRANSFER');
});

test('a finished_good item leaves stock from the same account intake put it into', function () {
    // The mapping drift: intake filed `finished_good` under 1134 but the
    // sale used to credit the 111 default — value entered one account and
    // left another. Both sides must agree on 1134.
    $good = InventoryItem::create([
        'name' => 'Mattress', 'code' => 'MATT-1', 'item_type' => 'finished_good', 'unit_of_measure' => 'each',
    ]);

    ($this->api)()->postJson('/api/v1/stock-lots/intake', [
        'inventory_item_id' => $good->id,
        'warehouse_id' => $this->storeWarehouse->id,
        'lot_number' => 'FG-MATT-1',
        'quantity' => 2,
        'unit_cost' => 200,
        'source' => 'opening_balance',
    ])->assertCreated();

    ($this->sell)(['lines' => [['inventory_item_id' => $good->id, 'quantity' => 1, 'unit_price' => 350]]])
        ->assertCreated();

    $byCode = ($this->balances)();
    expect((float) $byCode['1134']['debit'])->toBe(400.0)
        ->and((float) $byCode['1134']['credit'])->toBe(200.0)
        ->and((float) ($byCode['111']['debit'] ?? 0))->toBe(0.0)
        ->and((float) ($byCode['111']['credit'] ?? 0))->toBe(0.0);
});

test('a sale that stock cannot cover is refused whole', function () {
    ($this->sell)([], 9, 400)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'INSUFFICIENT_COMPONENT_STOCK');

    expect(SalesOrder::count())->toBe(0)
        ->and((float) StockLot::where('lot_number', 'FG-STOCK-1')->value('quantity'))->toBe(5.0)
        ->and(JournalEntry::count())->toBe(0);
});

test('closing the register counts cash received, not bank or receivable', function () {
    ($this->sell)()->assertCreated();                                                            // cash 450
    ($this->sell)(['payment_method' => 'bank', 'cash_account_id' => $this->bank->id], 1, 300)->assertCreated();
    $credit = ($this->sell)(['payment_method' => 'receivable', 'cash_account_id' => null], 1, 200)->json();

    // A cash collection on a receivable is drawer cash too.
    ($this->api)()->postJson("/api/v1/sales/{$credit['id']}/payments", [
        'amount' => 100, 'method' => 'cash', 'cash_account_id' => $this->drawer->id,
    ])->assertSuccessful();

    $report = ($this->api)()->getJson('/api/v1/pos/daily-report')->assertSuccessful()->json();
    expect($report['sales_count'])->toBe(3)
        ->and((float) $report['total'])->toBe(950.0)
        ->and((float) $report['by_method']['receivable']['total'])->toBe(200.0)
        ->and((float) $report['collected']['cash']['total'])->toBe(550.0)
        ->and((float) $report['collected']['bank']['total'])->toBe(300.0)
        ->and((float) $report['expected_cash'])->toBe(550.0);

    $close = ($this->api)()->postJson('/api/v1/pos/daily-close', ['counted_cash' => 540])
        ->assertCreated()->json();

    expect((float) $close['expected_cash'])->toBe(550.0)
        ->and((float) $close['difference'])->toBe(-10.0)
        ->and($close['sales_count'])->toBe(3)
        ->and((float) $close['total_sales'])->toBe(950.0);

    ($this->api)()->getJson('/api/v1/pos/daily-close')
        ->assertSuccessful()
        ->assertJsonPath('data.id', $close['id']);
});

test('the register day follows the company timezone, not UTC', function () {
    // 22:30 UTC on the 27th is 00:30 on the 28th in Tripoli (UTC+2).
    $this->travelTo(CarbonImmutable::parse('2026-09-27 22:30:00', 'UTC'));
    ($this->sell)()->assertCreated();

    expect(($this->api)()->getJson('/api/v1/pos/daily-report?date=2026-09-28')->json('sales_count'))->toBe(1)
        ->and(($this->api)()->getJson('/api/v1/pos/daily-report?date=2026-09-27')->json('sales_count'))->toBe(0)
        ->and(($this->api)()->getJson('/api/v1/pos/daily-report')->json('date'))->toBe('2026-09-28');
});

test('a register day cannot be closed twice', function () {
    ($this->api)()->postJson('/api/v1/pos/daily-close', ['counted_cash' => 0])->assertCreated();

    ($this->api)()->postJson('/api/v1/pos/daily-close', ['counted_cash' => 0])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'POS_DAY_ALREADY_CLOSED');
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
    ])->assertCreated()->assertJsonPath('status', 'pending_approval')->json();

    // SALE-05: fulfilling before approval is refused.
    ($this->api)()->postJson("/api/v1/internal-restock-requests/{$restock['id']}/fulfill")
        ->assertUnprocessable();

    ($this->api)()->putJson("/api/v1/internal-restock-requests/{$restock['id']}/approve")
        ->assertSuccessful();

    ($this->api)()->postJson("/api/v1/internal-restock-requests/{$restock['id']}/fulfill")
        ->assertSuccessful()
        ->assertJsonPath('status', 'fulfilled');

    // 4 left the furniture unit, 4 arrived in the store at cost.
    expect((float) StockLot::withoutGlobalScopes()->where('lot_number', 'FG-FURN-1')->value('quantity'))->toBe(6.0);

    $arrived = StockLot::where('warehouse_id', $this->storeWarehouse->id)
        ->where('lot_number', 'like', 'RST-%')->first();

    expect((float) $arrived->quantity)->toBe(4.0)
        ->and((float) $arrived->unit_cost)->toBe(140.0);

    ($this->balances)();
});

test('the invoice reflects the sale, with the sku and the treasury', function () {
    $sale = ($this->sell)(['payment_method' => 'receivable', 'cash_account_id' => null], 2, 400)->json();

    $invoice = ($this->api)()->getJson("/api/v1/sales/{$sale['id']}/invoice")->assertSuccessful()->json();

    expect($invoice['buyer'])->toBe('Sahara Trading')
        ->and($invoice['invoice_number'])->toBe('INV-'.$sale['order_number'])
        ->and($invoice['payment_method'])->toBe('receivable')
        ->and($invoice['lines'][0]['sku'])->toBe('SOFA-1')
        ->and($invoice['lines'][0]['description'])->toBe('Sofa')
        ->and((float) $invoice['total_amount'])->toBe(800.0)
        ->and((float) $invoice['outstanding'])->toBe(800.0);
});

test('the sales list is paginated and filterable', function () {
    ($this->sell)()->assertCreated();
    ($this->sell)(['payment_method' => 'receivable', 'cash_account_id' => null], 1, 200)->assertCreated();

    ($this->api)()->getJson('/api/v1/sales?per_page=1')
        ->assertSuccessful()
        ->assertJsonPath('total', 2)
        ->assertJsonCount(1, 'data');

    ($this->api)()->getJson('/api/v1/sales?payment_method=receivable')
        ->assertSuccessful()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.client.entity.name', 'Sahara Trading');
});
