<?php

declare(strict_types=1);

use App\Models\Bom;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\LaborRoleRate;
use App\Models\OperatingUnit;
use App\Models\ProductionOrder;
use App\Models\Role;
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
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'Furniture Unit', 'code' => 'FURN-01', 'unit_type' => 'manufactory',
    ]);
    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'WH', 'code' => 'WH-1',
    ]);
    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Furniture Manager', 'slug' => 'furniture_manager']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unit->id]);

    $entity = Entity::create(['entity_type' => 'individual', 'name' => 'Ahmed Tailor']);
    $this->employee = Employee::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'job_title' => 'Tailor',
        'pay_type' => 'hourly',
        'hire_date' => '2025-01-01',
    ]);

    $this->pieceItem = InventoryItem::create([
        'name' => 'Foam Base Piece', 'sku' => 'PIECE-BASE', 'item_type' => 'cut_template_piece', 'unit_of_measure' => 'each',
    ]);
    $this->fabricItem = InventoryItem::create([
        'name' => 'Fabric', 'sku' => 'FABRIC-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'meter',
    ]);
    $this->sofaItem = InventoryItem::create([
        'name' => 'Sofa 3-Seat', 'sku' => 'SOFA-3S', 'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'each',
    ]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $this->lot = fn (InventoryItem $item, string $number, float $qty, float $cost) => StockLot::create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => $number,
        'quantity' => $qty,
        'unit_cost' => $cost,
        'status' => 'available',
    ]);

    // A product with an active BOM: 2 foam pieces + 3m fabric, 2h tailor @ 15.
    $this->makeProduct = function () {
        $product = ($this->api)()->postJson('/api/v1/products', [
            'inventory_item_id' => $this->sofaItem->id,
            'name' => '3-Seat Sofa — Standard',
            'sku' => 'PROD-SOFA-3S',
            'markup_factor' => 1.25,
        ])->json();

        $bom = ($this->api)()->postJson('/api/v1/boms', [
            'product_id' => $product['id'],
            'activate' => true,
        ])->json();

        ($this->api)()->postJson("/api/v1/boms/{$bom['id']}/component-lines", [
            'inventory_item_id' => $this->pieceItem->id, 'quantity' => 2, 'estimated_unit_cost' => 50,
        ])->assertStatus(201);
        ($this->api)()->postJson("/api/v1/boms/{$bom['id']}/component-lines", [
            'inventory_item_id' => $this->fabricItem->id, 'quantity' => 3, 'estimated_unit_cost' => 10,
        ])->assertStatus(201);
        ($this->api)()->postJson("/api/v1/boms/{$bom['id']}/labor-requirements", [
            'role' => 'tailor', 'estimated_hours' => 2, 'hourly_rate' => 15,
        ])->assertStatus(201);

        return [$product, $bom];
    };

    $this->makeOrder = function (int $qty = 1) {
        [$product] = ($this->makeProduct)();

        return ($this->api)()->postJson('/api/v1/production-orders', [
            'order_number' => 'PO-'.fake()->unique()->numberBetween(1000, 9999),
            'product_id' => $product['id'],
            'quantity' => $qty,
        ])->json();
    };

    $this->move = fn (string $orderId, string $status) => ($this->api)()
        ->postJson("/api/v1/production-orders/{$orderId}/transition", ['status' => $status]);
});

test('an order without an active bom is refused', function () {
    $product = ($this->api)()->postJson('/api/v1/products', [
        'inventory_item_id' => $this->sofaItem->id,
        'name' => 'Mattress', 'sku' => 'PROD-MAT',
    ])->json();

    ($this->api)()->postJson('/api/v1/production-orders', [
        'order_number' => 'PO-NOBOM',
        'product_id' => $product['id'],
    ])->assertStatus(422)->assertJsonPath('code', 'NO_ACTIVE_BOM');
});

test('price preview multiplies estimates by the product markup', function () {
    [, $bom] = ($this->makeProduct)();

    $preview = ($this->api)()->getJson("/api/v1/boms/{$bom['id']}/price-preview")
        ->assertStatus(200)->json();

    // material 2×50 + 3×10 = 130; labor 2×15 = 30; total 160 × 1.25 = 200
    expect((float) $preview['estimated_total_cost'])->toBe(160.0)
        ->and((float) $preview['suggested_price'])->toBe(200.0);
});

test('confirming reserves stock and reserved lots are untouchable elsewhere', function () {
    ($this->lot)($this->pieceItem, 'PC-1', 1, 45);
    ($this->lot)($this->pieceItem, 'PC-2', 1, 45);
    ($this->lot)($this->fabricItem, 'FAB-1', 10, 8);

    $order = ($this->makeOrder)();
    ($this->move)($order['id'], 'bom_confirmed')->assertStatus(200);
    ($this->move)($order['id'], 'in_production')->assertStatus(200);

    // FUR-05: every touched lot is now reserved.
    expect(StockLot::where('lot_number', 'PC-1')->value('status'))->toBe('reserved')
        ->and(StockLot::where('lot_number', 'PC-2')->value('status'))->toBe('reserved')
        ->and(StockLot::where('lot_number', 'FAB-1')->value('status'))->toBe('reserved');

    // A second order over the same stock must find nothing available.
    $second = ($this->api)()->postJson('/api/v1/production-orders', [
        'order_number' => 'PO-SECOND',
        'product_id' => $order['product_id'],
        'bom_id' => $order['bom_id'],
    ])->json();

    ($this->move)($second['id'], 'bom_confirmed')->assertStatus(200);
    ($this->move)($second['id'], 'in_production')
        ->assertStatus(422)
        ->assertJsonPath('code', 'INSUFFICIENT_COMPONENT_STOCK');
});

test('a shortfall reserves nothing at all', function () {
    // Only one piece exists; the BOM needs two. The fabric that *is* plentiful
    // must not be left pinned by a build that cannot start.
    ($this->lot)($this->pieceItem, 'PC-ONLY', 1, 45);
    ($this->lot)($this->fabricItem, 'FAB-1', 10, 8);

    $order = ($this->makeOrder)();
    ($this->move)($order['id'], 'bom_confirmed')->assertStatus(200);
    ($this->move)($order['id'], 'in_production')->assertStatus(422);

    expect(StockLot::where('lot_number', 'PC-ONLY')->value('status'))->toBe('available')
        ->and(StockLot::where('lot_number', 'FAB-1')->value('status'))->toBe('available')
        ->and(ProductionOrder::find($order['id'])->status->value)->toBe('bom_confirmed');
});

test('consumption costs the order from real lots and returns bulk surplus', function () {
    ($this->lot)($this->pieceItem, 'PC-1', 1, 45);
    ($this->lot)($this->pieceItem, 'PC-2', 1, 45);
    ($this->lot)($this->fabricItem, 'FAB-1', 10, 8); // only 3m needed

    $order = ($this->makeOrder)();
    ($this->move)($order['id'], 'bom_confirmed');
    ($this->move)($order['id'], 'in_production');
    ($this->move)($order['id'], 'quality_check')->assertStatus(200);

    $fabric = StockLot::where('lot_number', 'FAB-1')->first();

    // The 7m surplus goes back to stock, not into the sofa.
    expect((float) $fabric->quantity)->toBe(7.0)
        ->and($fabric->status)->toBe('available')
        ->and(StockLot::where('lot_number', 'PC-1')->value('status'))->toBe('consumed');

    // Actuals, not estimates: 2×45 + 3×8 = 114 (estimates said 130).
    expect((float) ProductionOrder::find($order['id'])->material_cost)->toBe(114.0);
});

test('labor logs snapshot the rate and later rate edits do not move history', function () {
    ($this->lot)($this->pieceItem, 'PC-1', 1, 45);
    ($this->lot)($this->pieceItem, 'PC-2', 1, 45);
    ($this->lot)($this->fabricItem, 'FAB-1', 10, 8);

    $order = ($this->makeOrder)();
    ($this->move)($order['id'], 'bom_confirmed');
    ($this->move)($order['id'], 'in_production');

    // Rate defaults from the BOM's tailor requirement (15).
    $log = ($this->api)()->postJson("/api/v1/production-orders/{$order['id']}/labor-logs", [
        'employee_id' => $this->employee->id,
        'role' => 'tailor',
        'hours_logged' => 2.5,
    ])->assertStatus(201)->json();

    expect((float) $log['hourly_rate_at_log'])->toBe(15.0);

    // FUR-06: raising the BOM rate afterwards must not rewrite logged work.
    Bom::find($order['bom_id'])->laborRequirements()->update(['hourly_rate' => 99]);

    $logs = ($this->api)()->getJson("/api/v1/production-orders/{$order['id']}/labor-logs")->json();
    expect((float) $logs[0]['hourly_rate_at_log'])->toBe(15.0);
});

test('a versioned labor role rate takes precedence over the BOM rate', function () {
    // Phase 09: labor_role_rates are the source of truth; the BOM's 15 is
    // now only a fallback for roles with no rate history.
    LaborRoleRate::create([
        'role' => 'tailor', 'hourly_rate' => 20, 'effective_from' => now()->subDay()->toDateString(),
    ]);

    ($this->lot)($this->pieceItem, 'PC-1', 1, 45);
    ($this->lot)($this->pieceItem, 'PC-2', 1, 45);
    ($this->lot)($this->fabricItem, 'FAB-1', 10, 8);

    $order = ($this->makeOrder)();
    ($this->move)($order['id'], 'bom_confirmed');
    ($this->move)($order['id'], 'in_production');

    $log = ($this->api)()->postJson("/api/v1/production-orders/{$order['id']}/labor-logs", [
        'employee_id' => $this->employee->id,
        'role' => 'tailor',
        'hours_logged' => 2,
    ])->assertStatus(201)->json();

    expect((float) $log['hourly_rate_at_log'])->toBe(20.0);
});

test('labor cannot be logged before production starts', function () {
    $order = ($this->makeOrder)();

    ($this->api)()->postJson("/api/v1/production-orders/{$order['id']}/labor-logs", [
        'employee_id' => $this->employee->id,
        'role' => 'tailor',
        'hours_logged' => 1,
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});

test('the finished good carries material plus labor and the ledger balances', function () {
    ($this->lot)($this->pieceItem, 'PC-1', 1, 45);
    ($this->lot)($this->pieceItem, 'PC-2', 1, 45);
    ($this->lot)($this->fabricItem, 'FAB-1', 10, 8);

    $order = ($this->makeOrder)();
    ($this->move)($order['id'], 'bom_confirmed');
    ($this->move)($order['id'], 'in_production');

    ($this->api)()->postJson("/api/v1/production-orders/{$order['id']}/labor-logs", [
        'employee_id' => $this->employee->id, 'role' => 'tailor', 'hours_logged' => 2,
    ])->assertStatus(201);

    ($this->move)($order['id'], 'quality_check')->assertStatus(200);
    ($this->move)($order['id'], 'ready_for_collection')->assertStatus(200);

    $fresh = ProductionOrder::find($order['id']);
    $fg = $fresh->finishedStockLot;

    // material 114 + labor 30 = 144
    expect((float) $fresh->labor_cost)->toBe(30.0)
        ->and($fg)->not->toBeNull()
        ->and((float) $fg->unit_cost)->toBe(144.0)
        ->and($fg->lot_number)->toBe('FG-'.$fresh->order_number);

    $tb = app(AccountingService::class)->trialBalance();
    expect($tb['balanced'])->toBeTrue();

    $byCode = collect($tb['rows'])->keyBy('account_code');

    // WIP nets to zero; FG-Furniture holds 144; wages owed 30.
    expect((float) $byCode['1123']['balance'])->toBe(0.0)
        ->and((float) $byCode['1134']['debit'])->toBe(144.0)
        ->and((float) $byCode['2200']['credit'])->toBe(30.0);
});

test('collection issues the finished good and recognises cogs', function () {
    ($this->lot)($this->pieceItem, 'PC-1', 1, 45);
    ($this->lot)($this->pieceItem, 'PC-2', 1, 45);
    ($this->lot)($this->fabricItem, 'FAB-1', 10, 8);

    $order = ($this->makeOrder)();
    foreach (['bom_confirmed', 'in_production', 'quality_check', 'ready_for_collection', 'completed'] as $s) {
        ($this->move)($order['id'], $s)->assertStatus(200);
    }

    $fg = ProductionOrder::find($order['id'])->finishedStockLot;
    expect($fg->status)->toBe('consumed');

    $tb = app(AccountingService::class)->trialBalance();
    $byCode = collect($tb['rows'])->keyBy('account_code');

    // FG emptied back out; the value sits in COGS awaiting Phase 07's revenue.
    expect($tb['balanced'])->toBeTrue()
        ->and((float) $byCode['1134']['balance'])->toBe(0.0)
        ->and((float) $byCode['5100']['debit'])->toBe(114.0);
});

test('cloning a bom copies everything and leaves the original active', function () {
    [$product, $bom] = ($this->makeProduct)();

    $clone = ($this->api)()->postJson("/api/v1/boms/{$bom['id']}/clone")
        ->assertStatus(201)->json();

    expect($clone['version'])->toBe(2)
        ->and($clone['is_active'])->toBeFalse()
        ->and($clone['cloned_from_bom_id'])->toBe($bom['id'])
        ->and($clone['component_lines'])->toHaveCount(2)
        ->and($clone['labor_requirements'])->toHaveCount(1);

    // FUR-01: activating the clone deactivates the original.
    ($this->api)()->postJson("/api/v1/boms/{$clone['id']}/activate")->assertStatus(200);

    $boms = ($this->api)()->getJson("/api/v1/products/{$product['id']}/boms")->json();
    $active = collect($boms)->where('is_active', true);

    expect($active)->toHaveCount(1)
        ->and($active->first()['version'])->toBe(2);
});

test('quality check is refused when production never started', function () {
    // FUR-07 requires reservations before QC; here the order is still at
    // bom_confirmed, so the linear machine itself refuses the jump.
    $order = ($this->makeOrder)();
    ($this->move)($order['id'], 'bom_confirmed');

    ($this->move)($order['id'], 'quality_check')
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});
