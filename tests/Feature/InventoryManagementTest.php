<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
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
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Foam Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Foam Factory',
        'code' => 'FOAM-01',
        'unit_type' => 'foam_manufactory',
    ]);

    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Main Warehouse',
        'code' => 'WH-MAIN-01',
    ]);

    $this->user = User::factory()->create([
        'must_change_password' => false,
    ]);

    $this->role = Role::create([
        'name' => 'Inventory Manager',
        'slug' => 'inventory_manager',
    ]);

    UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => $this->role->id,
        'operating_unit_id' => $this->unit->id,
    ]);
});

test('can create and list inventory items', function () {
    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/inventory-items', [
            'name' => 'Polyol Standard',
            'sku' => 'RAW-POLY-01',
            'item_type' => 'raw_material',
            'unit_of_measure' => 'kg',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('sku', 'RAW-POLY-01');

    $listResponse = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/inventory-items');

    $listResponse->assertStatus(200)
        ->assertJsonFragment(['sku' => 'RAW-POLY-01']);
});

test('can create stock lots and filter available blocks for cutter selection', function () {
    $item = InventoryItem::create([
        'name' => 'Foam Block 30Density',
        'sku' => 'BLOCK-30D',
        'item_type' => 'foam_block',
        'unit_of_measure' => 'm3',
    ]);

    $lot = StockLot::create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'LOT-FB-101',
        'quantity' => 1.0,
        'length_m' => 2.0,
        'width_m' => 2.0,
        'height_m' => 1.0,
        'unit_cost' => 500.0,
        'grade' => 'standard',
        'status' => 'available',
    ]);

    expect((float) $lot->volume_m3)->toBe(4.0);

    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/stock-lots/available-for-cutting?min_volume_m3=3.0&grade=standard');

    $response->assertStatus(200)
        ->assertJsonFragment(['lot_number' => 'LOT-FB-101']);
});

test('tank stock refill correctly calculates weighted average cost', function () {
    $chemItem = InventoryItem::create([
        'name' => 'TDI Chemical',
        'sku' => 'CHEM-TDI',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'liter',
    ]);

    // Initial Refill: 1000L @ $10/L = $10,000 total
    $firstRefill = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/tank-stocks/refill', [
            'chemical_inventory_item_id' => $chemItem->id,
            'operating_unit_id' => $this->unit->id,
            'refill_quantity' => 1000,
            'refill_unit_cost' => 10.0,
        ]);

    $firstRefill->assertStatus(201)
        ->assertJsonPath('quantity_on_hand', '1000.0000')
        ->assertJsonPath('weighted_avg_unit_cost', '10.0000');

    // Second Refill: 1000L @ $20/L = $20,000 total -> New Total 2000L @ $15/L
    $secondRefill = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/tank-stocks/refill', [
            'chemical_inventory_item_id' => $chemItem->id,
            'operating_unit_id' => $this->unit->id,
            'refill_quantity' => 1000,
            'refill_unit_cost' => 20.0,
        ]);

    $secondRefill->assertStatus(201)
        ->assertJsonPath('quantity_on_hand', '2000.0000')
        ->assertJsonPath('weighted_avg_unit_cost', '15.0000');
});

test('manual stock adjustment requires unit manager approval and posts movement', function () {
    $item = InventoryItem::create([
        'name' => 'Raw Cotton',
        'sku' => 'RAW-COTTON',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'kg',
    ]);

    $lot = StockLot::create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'LOT-COTTON-01',
        'quantity' => 100.0,
        'unit_cost' => 5.0,
        'grade' => 'standard',
        'status' => 'available',
    ]);

    // Create adjustment request for spill loss (-10kg)
    $reqResponse = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/stock-adjustment-requests', [
            'operating_unit_id' => $this->unit->id,
            'stock_lot_id' => $lot->id,
            'reason_code' => 'spill_loss',
            'quantity_delta' => -10.0,
            'notes' => 'Spill during transfer',
        ]);

    $reqResponse->assertStatus(201)
        ->assertJsonPath('status', 'pending');

    $requestId = $reqResponse->json('id');

    // Unit Manager approves request
    $approveResponse = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/stock-adjustment-requests/{$requestId}/approve");

    $approveResponse->assertStatus(200)
        ->assertJsonPath('status', 'approved');

    // Verify StockLot updated
    $lot->refresh();
    expect((float) $lot->quantity)->toBe(90.0);

    // The write-down reached the ledger: 10 kg × 5.0 expensed to variance.
    $entry = JournalEntry::where('source_document_type', 'StockAdjustmentRequest')->sole();
    $lines = $entry->lines()->with('account')->get();

    expect((float) $lines->firstWhere(fn ($l) => $l->account->account_code === '5200')->debit)->toBe(50.0)
        ->and((float) $lines->firstWhere(fn ($l) => $l->account->account_code === '1110')->credit)->toBe(50.0);
});

test('an upward audit adjustment recovers value through the variance account', function () {
    // A bulk item, not a foam block — INV-02 rightly refuses to grow a
    // serialized lot past quantity 1. Slices carry 1132, so this also
    // proves the journal follows the item's own inventory account.
    $item = InventoryItem::create([
        'name' => 'Standard Slice', 'sku' => 'SLICE-AUDIT', 'item_type' => 'slice', 'unit_of_measure' => 'each',
    ]);

    $lot = StockLot::create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'LOT-AUDIT-01',
        'quantity' => 1.0,
        'unit_cost' => 400.0,
        'grade' => 'standard',
        'status' => 'available',
    ]);

    $requestId = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/stock-adjustment-requests', [
            'stock_lot_id' => $lot->id,
            'reason_code' => 'audit_reconciliation',
            'quantity_delta' => 1.0,
        ])->assertStatus(201)->json('id');

    $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/stock-adjustment-requests/{$requestId}/approve")
        ->assertStatus(200);

    // Found stock enters the slice account, recovered from variance.
    $entry = JournalEntry::where('source_document_type', 'StockAdjustmentRequest')->sole();
    $lines = $entry->lines()->with('account')->get();

    expect((float) $lines->firstWhere(fn ($l) => $l->account->account_code === '1132')->debit)->toBe(400.0)
        ->and((float) $lines->firstWhere(fn ($l) => $l->account->account_code === '5200')->credit)->toBe(400.0);
});

test('adjusting a zero-cost scrap lot moves quantity but posts nothing', function () {
    $item = InventoryItem::create([
        'name' => 'Scrap Fill', 'sku' => 'SCRAP-ADJ', 'item_type' => 'byproduct_fill', 'unit_of_measure' => 'kg',
    ]);

    $lot = StockLot::create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'LOT-SCRAP-ADJ',
        'quantity' => 20.0,
        'unit_cost' => 0.0,
        'grade' => 'scrap',
        'status' => 'available',
    ]);

    $requestId = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/stock-adjustment-requests', [
            'stock_lot_id' => $lot->id,
            'reason_code' => 'damage',
            'quantity_delta' => -5.0,
        ])->assertStatus(201)->json('id');

    $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/stock-adjustment-requests/{$requestId}/approve")
        ->assertStatus(200);

    expect((float) $lot->fresh()->quantity)->toBe(15.0)
        ->and(JournalEntry::where('source_document_type', 'StockAdjustmentRequest')->exists())->toBeFalse();
});

test('can retrieve inventory valuation summary and company rollup', function () {
    $item = InventoryItem::create([
        'name' => 'Foam Block Super',
        'sku' => 'BLOCK-SUPER',
        'item_type' => 'foam_block',
        'unit_of_measure' => 'm3',
    ]);

    StockLot::create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'LOT-VAL-01',
        'quantity' => 1.0,
        'unit_cost' => 1000.0,
        'grade' => 'standard',
        'status' => 'available',
    ]);

    $valResponse = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/inventory/valuation');

    $valResponse->assertStatus(200);
    expect((float) $valResponse->json('stock_lot_valuation'))->toBe(1000.0);

    $rollupResponse = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/inventory/valuation/rollup');

    $rollupResponse->assertStatus(200);
    expect((float) $rollupResponse->json('company_total_valuation'))->toBe(1000.0);
});

test('cutter operator can process cut remnant by restocking remnant block with new dimensions (Option C)', function () {
    $item = InventoryItem::create([
        'name' => 'Foam Block 30D',
        'sku' => 'BLOCK-30D-CUT',
        'item_type' => 'foam_block',
        'unit_of_measure' => 'm3',
    ]);

    $parentLot = StockLot::create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'LOT-CUT-PARENT-01',
        'quantity' => 1.0,
        'length_m' => 2.0,
        'width_m' => 2.0,
        'height_m' => 1.0,
        'unit_cost' => 600.0,
        'grade' => 'standard',
        'status' => 'available',
    ]);

    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/stock-lots/{$parentLot->id}/process-cut-remnant", [
            'remnant_action' => 'restock_remnant',
            'remnant_dimensions' => [
                'length_m' => 1.2,
                'width_m' => 2.0,
                'height_m' => 1.0,
            ],
            'byproduct_weight_kg' => 5.5,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('parent_lot.status', 'consumed')
        ->assertJsonPath('remnant_lot.length_m', '1.200')
        ->assertJsonPath('remnant_lot.volume_m3', '2.4000');

    $parentLot->refresh();
    expect($parentLot->status)->toBe('consumed');
});

test('cutter operator can process cut remnant by converting remaining block to byproduct fill (Option C)', function () {
    $item = InventoryItem::create([
        'name' => 'Foam Block 25D',
        'sku' => 'BLOCK-25D-BYPROD',
        'item_type' => 'foam_block',
        'unit_of_measure' => 'm3',
    ]);

    $parentLot = StockLot::create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'LOT-CUT-PARENT-02',
        'quantity' => 1.0,
        'length_m' => 2.0,
        'width_m' => 2.0,
        'height_m' => 1.0,
        'unit_cost' => 450.0,
        'grade' => 'standard',
        'status' => 'available',
    ]);

    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson("/api/v1/stock-lots/{$parentLot->id}/process-cut-remnant", [
            'remnant_action' => 'convert_to_byproduct',
            'byproduct_weight_kg' => 25.0,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('parent_lot.status', 'consumed')
        ->assertJsonPath('remnant_lot', null);

    $parentLot->refresh();
    expect($parentLot->status)->toBe('consumed');
});

test('can update an inventory item', function () {
    $item = InventoryItem::create([
        'name' => 'Original Item',
        'sku' => 'ITEM-ORIG-01',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'kg',
        'primary_uom' => 'barrel',
        'secondary_uom' => 'kg',
    ]);

    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->putJson("/api/v1/inventory-items/{$item->id}", [
            'name' => 'Updated Item Name',
            'sku' => 'ITEM-ORIG-01',
            'unit_of_measure' => 'liter',
            'primary_uom' => 'drum',
            'secondary_uom' => 'liter',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('name', 'Updated Item Name')
        ->assertJsonPath('unit_of_measure', 'liter')
        ->assertJsonPath('primary_uom', 'drum')
        ->assertJsonPath('secondary_uom', 'liter');

    $item->refresh();
    expect($item->name)->toBe('Updated Item Name')
        ->and($item->unit_of_measure)->toBe('liter')
        ->and($item->primary_uom)->toBe('drum');
});

test('updating inventory item with duplicate SKU is rejected', function () {
    $item1 = InventoryItem::create([
        'name' => 'First Item',
        'sku' => 'ITEM-SKU-1',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'kg',
    ]);

    $item2 = InventoryItem::create([
        'name' => 'Second Item',
        'sku' => 'ITEM-SKU-2',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'kg',
    ]);

    $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->putJson("/api/v1/inventory-items/{$item2->id}", [
            'sku' => 'ITEM-SKU-1',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sku']);
});

