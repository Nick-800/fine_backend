<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Bundle;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\CutterWorkOrder;
use App\Models\CutterWorkOrderLine;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\JournalLine;
use App\Models\MaterialRequest;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\SaleBundleComponent;
use App\Models\SalesOrder;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\AccountingService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $storeBlueprint = UnitBlueprint::create([
        'name' => 'Store Blueprint', 'workflow_set' => ['pos_sale' => []], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $cutterBlueprint = UnitBlueprint::create([
        'name' => 'Cutter Blueprint', 'workflow_set' => ['cutter_work_order' => []], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);

    $this->store = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $storeBlueprint->id, 'name' => 'Showroom', 'unit_type' => 'store',
    ]);
    $this->cutter = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $cutterBlueprint->id, 'name' => 'Cutter', 'unit_type' => 'manufactory',
    ]);

    $this->storeWarehouse = Warehouse::create(['operating_unit_id' => $this->store->id, 'name' => 'Store WH', 'code' => 'WH-S']);
    $this->cutterWarehouse = Warehouse::create(['operating_unit_id' => $this->cutter->id, 'name' => 'Block Yard', 'code' => 'WH-C']);

    // The cutter's purchases account is its cut-template-piece inventory
    // account (1132); the showroom's purchases stays on 111 for raw
    // materials. Per-unit overrides require per-unit calls.
    seedUnitAccounts($this->store);
    seedUnitAccounts($this->cutter, ['purchases' => '1132']);

    $this->cashier = User::factory()->create(['must_change_password' => false]);
    UserRole::create([
        'user_id' => $this->cashier->id,
        'role_id' => Role::create(['name' => 'Cashier', 'slug' => 'pos-cashier'])->id,
        'operating_unit_id' => $this->store->id,
    ]);
    $this->cutterManager = User::factory()->create(['must_change_password' => false]);
    UserRole::create([
        'user_id' => $this->cutterManager->id,
        'role_id' => Role::create(['name' => 'Cutter Manager', 'slug' => 'cutter-manager'])->id,
        'operating_unit_id' => $this->cutter->id,
    ]);

    $this->showroom = fn () => $this->actingAs($this->cashier)->withHeaders(['X-Operating-Unit-ID' => $this->store->id]);
    $this->cutterApi = fn () => $this->actingAs($this->cutterManager)->withHeaders(['X-Operating-Unit-ID' => $this->cutter->id]);

    $this->drawer = CashAccount::create([
        'operating_unit_id' => $this->store->id, 'name' => 'Drawer', 'kind' => 'cash',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);

    $this->foam = InventoryItem::create([
        'name' => 'إسفنج D25', 'code' => 'CUT-D25', 'item_type' => 'cut_template_piece',
        'unit_of_measure' => 'each', 'selling_price' => 1000, 'price_basis' => 'm3',
    ]);
    $this->fabric = InventoryItem::create([
        'name' => 'قماش', 'code' => 'FAB-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'meter',
    ]);
    $this->blockItem = InventoryItem::create([
        'name' => 'Foam block D25', 'code' => 'FB-D25', 'item_type' => 'foam_block', 'unit_of_measure' => 'm3',
    ]);

    $this->sofa = Bundle::create(['name' => 'جلسة عربية']);

    $this->sellSofa = function (string $clientName): array {
        $client = Client::create([
            'entity_id' => Entity::create(['entity_type' => 'individual', 'name' => $clientName])->id,
            'operating_unit_id' => $this->store->id,
        ]);

        $sale = ($this->showroom)()->postJson('/api/v1/sales', [
            'client_id' => $client->id,
            'payment_method' => 'cash',
            'cash_account_id' => $this->drawer->id,
            'lines' => [['line_type' => 'bundle', 'bundle_id' => $this->sofa->id, 'quantity' => 1, 'unit_price' => 2500]],
        ])->assertCreated()->json();

        $components = ($this->showroom)()->putJson(
            "/api/v1/sales/{$sale['id']}/lines/{$sale['lines'][0]['id']}/components",
            ['components' => [
                ['inventory_item_id' => $this->foam->id, 'quantity' => 4, 'length_m' => 2, 'width_m' => 0.7, 'height_m' => 0.1],
                ['inventory_item_id' => $this->foam->id, 'quantity' => 2, 'length_m' => 0.7, 'width_m' => 0.5, 'height_m' => 0.15],
                ['inventory_item_id' => $this->fabric->id, 'quantity' => 12],
            ]],
        )->assertSuccessful()->json('components');

        return [$sale, $components];
    };

    $this->block = fn (string $lotNumber, float $cost) => StockLot::create([
        'inventory_item_id' => $this->blockItem->id, 'warehouse_id' => $this->cutterWarehouse->id,
        'lot_number' => $lotNumber, 'quantity' => 1, 'unit_cost' => $cost, 'status' => 'available',
        'length_m' => 2, 'width_m' => 1, 'height_m' => 1,
    ]);

    // Drive a cutter order from requested to completed with the given blocks.
    $this->cut = function (string $orderId, array $blocks): void {
        foreach ($blocks as $block) {
            ($this->cutterApi)()->postJson("/api/v1/cutter-work-orders/{$orderId}/attach-block", ['stock_lot_id' => $block->id])->assertOk();
        }

        foreach (['confirmed', 'in_production', 'awaiting_byproduct_weigh_in'] as $status) {
            ($this->cutterApi)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => $status])->assertOk();
        }

        ($this->cutterApi)()->postJson("/api/v1/cutter-work-orders/{$orderId}/weigh-in", ['weight_kg' => 0])->assertCreated();

        foreach (['quality_check', 'completed'] as $status) {
            ($this->cutterApi)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => $status])->assertOk();
        }
    };
});

test('a showroom sends missing pieces to the cutter plant, which gets a numbered order sized from the sale', function () {
    [$sale, $components] = ($this->sellSofa)('Fatima');

    $order = ($this->showroom)()->postJson("/api/v1/sales/{$sale['id']}/send-to-cutter", [
        'component_ids' => [$components[0]['id'], $components[1]['id']],
    ])->assertCreated()->json();

    expect($order['order_number'])->toBe('CWO-'.now()->format('Y').'-00001')
        ->and($order['operating_unit_id'])->toBe($this->cutter->id)
        ->and($order['status'])->toBe('requested')
        ->and($order['lines'])->toHaveCount(2)
        ->and($order['lines'][0]['requested_spec'])->toBe('إسفنج D25 200×70×10 سم')
        ->and($order['lines'][0]['quantity'])->toBe(4)
        ->and((float) $order['lines'][0]['template_length_m'])->toBe(2.0)
        ->and($order['lines'][0]['output_inventory_item_id'])->toBe($this->foam->id)
        ->and($order['lines'][0]['sale_bundle_component_id'])->toBe($components[0]['id']);

    expect(SaleBundleComponent::find($components[0]['id'])->status->value)->toBe('at_cutter')
        ->and(SaleBundleComponent::find($components[2]['id'])->status->value)->toBe('pending');

    // The cutter sees it in its own unit; the showroom does not own it.
    ($this->cutterApi)()->getJson("/api/v1/cutter-work-orders/{$order['id']}")->assertSuccessful();
    ($this->cutterApi)()->getJson("/api/v1/cutter-work-orders/{$order['id']}")
        ->assertJsonPath('lines.0.sale_component.line.sales_order.order_number', $sale['order_number']);
});

test('only pending, sized cut pieces can go to the cutter', function () {
    [$sale, $components] = ($this->sellSofa)('Fatima');

    ($this->showroom)()->postJson("/api/v1/sales/{$sale['id']}/send-to-cutter", ['component_ids' => [$components[2]['id']]])
        ->assertUnprocessable()->assertJsonPath('code', 'NOT_A_CUT_PIECE');

    ($this->showroom)()->postJson("/api/v1/sales/{$sale['id']}/send-to-cutter", ['component_ids' => [$components[0]['id']]])
        ->assertCreated();

    ($this->showroom)()->postJson("/api/v1/sales/{$sale['id']}/send-to-cutter", ['component_ids' => [$components[0]['id']]])
        ->assertUnprocessable()->assertJsonPath('code', 'COMPONENT_NOT_PENDING');
});

test('one cutter order carries pieces from several sales and the cutter own extra lines', function () {
    [$first, $firstComponents] = ($this->sellSofa)('Fatima');
    [$second, $secondComponents] = ($this->sellSofa)('Omar');

    $order = ($this->showroom)()->postJson("/api/v1/sales/{$first['id']}/send-to-cutter", [
        'component_ids' => [$firstComponents[0]['id']],
    ])->json();

    ($this->showroom)()->getJson('/api/v1/sales/open-cutter-orders')
        ->assertSuccessful()->assertJsonPath('data.0.id', $order['id']);

    ($this->showroom)()->postJson("/api/v1/sales/{$second['id']}/send-to-cutter", [
        'component_ids' => [$secondComponents[0]['id'], $secondComponents[1]['id']],
        'cutter_work_order_id' => $order['id'],
    ])->assertCreated()->assertJsonCount(3, 'lines');

    // The cutter adds a line of its own — cutting for stock.
    ($this->cutterApi)()->postJson("/api/v1/cutter-work-orders/{$order['id']}/lines", [
        'requested_spec' => 'Stock cushions 50×50×10', 'quantity' => 3, 'output_inventory_item_id' => $this->foam->id,
    ])->assertCreated();

    $lines = CutterWorkOrderLine::where('cutter_work_order_id', $order['id'])->get();
    expect($lines)->toHaveCount(4)
        ->and($lines->whereNotNull('sale_bundle_component_id'))->toHaveCount(3)
        ->and($lines->whereNull('sale_bundle_component_id'))->toHaveCount(1);
});

test('pieces can only join an open cutter order', function () {
    [$sale, $components] = ($this->sellSofa)('Fatima');
    $closed = CutterWorkOrder::create([
        'operating_unit_id' => $this->cutter->id, 'order_number' => 'CWO-OLD', 'status' => 'confirmed',
    ]);

    ($this->showroom)()->postJson("/api/v1/sales/{$sale['id']}/send-to-cutter", [
        'component_ids' => [$components[0]['id']],
        'cutter_work_order_id' => $closed->id,
    ])->assertUnprocessable()->assertJsonPath('code', 'CUTTER_ORDER_NOT_OPEN');
});

test('completing the cutter order lands the cut pieces reserved on the sale, costed by volume, ledger equal to lots', function () {
    [$sale, $components] = ($this->sellSofa)('Fatima');

    $order = ($this->showroom)()->postJson("/api/v1/sales/{$sale['id']}/send-to-cutter", [
        'component_ids' => [$components[0]['id'], $components[1]['id']],
    ])->json();

    // An extra line for stock rides along.
    ($this->cutterApi)()->postJson("/api/v1/cutter-work-orders/{$order['id']}/lines", [
        'requested_spec' => 'Stock cushion', 'quantity' => 1, 'output_inventory_item_id' => $this->foam->id,
    ])->assertCreated();
    $extra = CutterWorkOrderLine::whereNull('sale_bundle_component_id')->sole();
    ($this->cutterApi)()->putJson("/api/v1/cutter-work-order-lines/{$extra->id}/assign-template", [
        'template_length_m' => 0.5, 'template_width_m' => 0.5, 'template_height_m' => 0.1,
    ])->assertSuccessful();

    // Two blocks: 600 + 400 = 1000 into WIP.
    ($this->cut)($order['id'], [($this->block)('BLK-1', 600), ($this->block)('BLK-2', 400)]);

    $pieces = StockLot::withoutGlobalScopes()->where('lot_number', 'like', "CUT-{$order['order_number']}-%")->get();
    expect($pieces)->toHaveCount(7);  // 4 seats + 2 backs + 1 stock cushion

    // Pieces cut for the sale are set aside for it; the stock cushion is free.
    expect($pieces->where('status', 'reserved'))->toHaveCount(6)
        ->and($pieces->where('status', 'available'))->toHaveCount(1);

    // Cost shared by volume: seats 4×0.14=0.56, backs 2×0.0525=0.105, cushion 0.025 → of 0.69 m³.
    $seat = $pieces->firstWhere('length_m', '2.000');
    expect(round((float) $seat->unit_cost, 2))->toBe(round(1000 * 0.14 / 0.69, 2));

    // Ledger equals lots: 1132 took exactly what the pieces carry.
    $tb = app(AccountingService::class)->trialBalance();
    $byCode = collect($tb['rows'])->keyBy('account_code');
    expect($tb['balanced'])->toBeTrue()
        ->and(round((float) $pieces->sum('unit_cost'), 4))->toBe(1000.0)
        ->and((float) $byCode['1132']['debit'])->toBe(1000.0)
        ->and((float) $byCode['1122']['balance'])->toBe(0.0);

    expect(SaleBundleComponent::find($components[0]['id']))
        ->status->value->toBe('ready')
        ->allocations->toHaveCount(4);
});

test('the showroom delivers cutter-made and stock pieces together, cost leaving the unit that made them', function () {
    [$sale, $components] = ($this->sellSofa)('Fatima');

    $order = ($this->showroom)()->postJson("/api/v1/sales/{$sale['id']}/send-to-cutter", [
        'component_ids' => [$components[0]['id'], $components[1]['id']],
    ])->json();
    ($this->cut)($order['id'], [($this->block)('BLK-1', 690)]);

    $roll = StockLot::create([
        'inventory_item_id' => $this->fabric->id, 'warehouse_id' => $this->storeWarehouse->id,
        'lot_number' => 'FAB-ROLL', 'quantity' => 50, 'unit_cost' => 10, 'status' => 'available',
    ]);
    ($this->showroom)()->postJson("/api/v1/sale-components/{$components[2]['id']}/reserve", [
        'allocations' => [['stock_lot_id' => $roll->id, 'quantity' => 12]],
    ])->assertSuccessful();

    expect(SalesOrder::find($sale['id'])->fulfillment_status->value)->toBe('ready');

    // The whole 690 block went into the pieces (nothing weighed off), plus 12 m × 10 of fabric.
    ($this->showroom)()->postJson("/api/v1/sales/{$sale['id']}/deliver")
        ->assertSuccessful()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('fulfillment_status', 'delivered')
        ->assertJsonPath('total_cost', '810.0000');

    $credits = fn (string $unitId, string $code) => (float) JournalLine::where('operating_unit_id', $unitId)
        ->where('account_id', Account::where('account_code', $code)->value('id'))->sum('credit');

    // Foam left the cutter's cut-piece stock (1132); fabric left the showroom's 111.
    expect($credits($this->cutter->id, '1132'))->toBe(690.0)
        ->and($credits($this->store->id, '111'))->toBe(120.0)
        ->and(StockLot::withoutGlobalScopes()->where('lot_number', 'like', 'CUT-%')->pluck('status')->unique()->all())->toBe(['consumed']);

    $tb = app(AccountingService::class)->trialBalance();
    $byCode = collect($tb['rows'])->keyBy('account_code');
    expect($tb['balanced'])->toBeTrue()
        ->and((float) $byCode['51']['debit'])->toBe(810.0)
        ->and((float) $byCode['1132']['balance'])->toBe(0.0);
});

test('the cutter job sheet names the sale and client of each line and carries no prices', function () {
    [$sale, $components] = ($this->sellSofa)('Fatima');

    $order = ($this->showroom)()->postJson("/api/v1/sales/{$sale['id']}/send-to-cutter", [
        'component_ids' => [$components[0]['id']],
    ])->json();

    $sheet = ($this->cutterApi)()->getJson("/api/v1/cutter-work-orders/{$order['id']}/job-sheet")->assertSuccessful()->json();

    expect($sheet['lines'][0])->toMatchArray([
        'requested_spec' => 'إسفنج D25 200×70×10 سم',
        'quantity' => 4,
        'sale_number' => $sale['order_number'],
        'bundle' => 'جلسة عربية',
        'client' => 'Fatima',
    ]);

    expect(json_encode($sheet))->not->toContain('price')->not->toContain('cost');
});

test('cutting instructions freeze once production starts, and every line must produce something', function () {
    $order = ($this->cutterApi)()->postJson('/api/v1/cutter-work-orders', ['stock_lot_id' => ($this->block)('BLK-1', 500)->id])
        ->assertCreated()->json();

    ($this->cutterApi)()->postJson("/api/v1/cutter-work-orders/{$order['id']}/lines", [
        'requested_spec' => 'Loose piece', 'quantity' => 1,
    ])->assertCreated();
    $line = CutterWorkOrderLine::sole();
    ($this->cutterApi)()->putJson("/api/v1/cutter-work-order-lines/{$line->id}/assign-template", [
        'template_length_m' => 1, 'template_width_m' => 1, 'template_height_m' => 0.1,
    ])->assertSuccessful();

    foreach (['confirmed', 'in_production'] as $status) {
        ($this->cutterApi)()->postJson("/api/v1/cutter-work-orders/{$order['id']}/transition", ['status' => $status])->assertOk();
    }

    ($this->cutterApi)()->postJson("/api/v1/cutter-work-orders/{$order['id']}/lines", [
        'requested_spec' => 'Too late', 'quantity' => 1,
    ])->assertUnprocessable()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    ($this->cutterApi)()->postJson("/api/v1/cutter-work-orders/{$order['id']}/transition", ['status' => 'awaiting_byproduct_weigh_in'])->assertOk();
    ($this->cutterApi)()->postJson("/api/v1/cutter-work-orders/{$order['id']}/weigh-in", ['weight_kg' => 0])->assertCreated();
    ($this->cutterApi)()->postJson("/api/v1/cutter-work-orders/{$order['id']}/transition", ['status' => 'quality_check'])->assertOk();

    ($this->cutterApi)()->postJson("/api/v1/cutter-work-orders/{$order['id']}/transition", ['status' => 'completed'])
        ->assertUnprocessable()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});

test('completing an order leaves unrelated material requests alone', function () {
    $unrelated = MaterialRequest::create([
        'fulfilling_module' => MaterialRequest::MODULE_CUTTER,
        'inventory_item_id' => $this->foam->id,
        'quantity' => 1,
        'status' => MaterialRequest::STATUS_IN_PROGRESS,
        'operating_unit_id' => $this->store->id,
    ]);

    $order = ($this->cutterApi)()->postJson('/api/v1/cutter-work-orders', [])->assertCreated()->json();
    ($this->cutterApi)()->postJson("/api/v1/cutter-work-orders/{$order['id']}/lines", [
        'requested_spec' => 'Piece', 'quantity' => 1, 'output_inventory_item_id' => $this->foam->id,
    ])->assertCreated();
    CutterWorkOrderLine::sole()->update(['template_length_m' => 1, 'template_width_m' => 1, 'template_height_m' => 0.1]);

    ($this->cut)($order['id'], [($this->block)('BLK-1', 500)]);

    expect($unrelated->fresh()->status)->toBe(MaterialRequest::STATUS_IN_PROGRESS);
});
