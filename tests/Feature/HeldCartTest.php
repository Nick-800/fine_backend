<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\HeldCart;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Support\RolePermissions;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Fine Foam Mfg',
        'default_currency' => 'LYD',
        'overhead_absorption_enabled' => false,
        'transfer_pricing_mode' => 'at_cost',
        'timezone' => 'Africa/Tripoli',
    ]);

    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Store Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unitA = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $blueprint->id,
        'name' => 'Showroom A',
        'unit_type' => 'store',
        'currency' => 'LYD',
        'status' => 'active',
    ]);

    $this->unitB = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $blueprint->id,
        'name' => 'Showroom B',
        'unit_type' => 'store',
        'currency' => 'LYD',
        'status' => 'active',
    ]);

    RolePermissions::ensureCatalog();

    $this->cashierRole = Role::firstOrCreate(['slug' => 'pos-cashier'], ['name' => 'POS Cashier']);
    RolePermissions::grantDefaults($this->cashierRole);

    $this->hrRole = Role::firstOrCreate(['slug' => 'hr-manager'], ['name' => 'HR Manager']);
    RolePermissions::grantDefaults($this->hrRole);

    $this->cashierA = User::factory()->create(['must_change_password' => false, 'name' => 'Ahmed Cashier']);
    UserRole::create([
        'user_id' => $this->cashierA->id,
        'role_id' => $this->cashierRole->id,
        'operating_unit_id' => $this->unitA->id,
    ]);

    $this->cashierB = User::factory()->create(['must_change_password' => false, 'name' => 'Basem Cashier']);
    UserRole::create([
        'user_id' => $this->cashierB->id,
        'role_id' => $this->cashierRole->id,
        'operating_unit_id' => $this->unitB->id,
    ]);

    $this->hrUser = User::factory()->create(['must_change_password' => false, 'name' => 'Hanan HR']);
    UserRole::create([
        'user_id' => $this->hrUser->id,
        'role_id' => $this->hrRole->id,
        'operating_unit_id' => $this->unitA->id,
    ]);

    $this->clientA = Client::create([
        'entity_id' => Entity::create(['entity_type' => 'individual', 'name' => 'Tariq Client'])->id,
        'operating_unit_id' => $this->unitA->id,
        'credit_limit' => 2000,
    ]);

    $warehouse = Warehouse::create([
        'operating_unit_id' => $this->unitA->id,
        'name' => 'Store WH A',
        'code' => 'WH-A',
    ]);

    $this->item = InventoryItem::create([
        'name' => 'Mattress King',
        'code' => 'MAT-K1',
        'item_type' => 'furniture_finished_good',
        'unit_of_measure' => 'each',
    ]);

    $this->stockLot = StockLot::create([
        'inventory_item_id' => $this->item->id,
        'warehouse_id' => $warehouse->id,
        'lot_number' => 'MAT-LOT-01',
        'quantity' => 25,
        'unit_cost' => 80,
        'status' => 'available',
    ]);
});

test('can store and list held carts newest first, and unit scoping hides other units carts', function () {
    $response1 = $this->actingAs($this->cashierA)
        ->withHeader('X-Operating-Unit-ID', $this->unitA->id)
        ->postJson('/api/v1/held-carts', [
            'label' => 'Customer stepped to ATM',
            'client_id' => $this->clientA->id,
            'payload' => [
                'lines' => [
                    ['item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 150],
                ],
                'notes' => 'Customer returning in 10 mins',
            ],
            'total' => 300,
            'line_count' => 1,
        ]);

    $response1->assertCreated()
        ->assertJsonPath('data.label', 'Customer stepped to ATM')
        ->assertJsonPath('data.client.id', $this->clientA->id)
        ->assertJsonPath('data.client.name', 'Tariq Client')
        ->assertJsonPath('data.total', 300)
        ->assertJsonPath('data.line_count', 1)
        ->assertJsonPath('data.payload.lines.0.quantity', 2)
        ->assertJsonPath('data.user.id', $this->cashierA->id)
        ->assertJsonPath('data.user.name', 'Ahmed Cashier');

    // Create cart 2 in unitA
    $response2 = $this->actingAs($this->cashierA)
        ->withHeader('X-Operating-Unit-ID', $this->unitA->id)
        ->postJson('/api/v1/held-carts', [
            'label' => 'Phone call in progress',
            'payload' => ['lines' => [['item_id' => $this->item->id, 'quantity' => 1]]],
            'total' => 150,
            'line_count' => 1,
        ])->assertCreated();

    $response2->assertJsonPath('data.client', null);

    // Create cart in unitB
    $this->actingAs($this->cashierB)
        ->withHeader('X-Operating-Unit-ID', $this->unitB->id)
        ->postJson('/api/v1/held-carts', [
            'label' => 'Unit B cart',
            'payload' => ['notes' => 'Unit B only'],
            'total' => 500,
            'line_count' => 3,
        ])->assertCreated();

    // Listing for unitA should have only unitA's 2 carts, newest first
    $listResponse = $this->actingAs($this->cashierA)
        ->withHeader('X-Operating-Unit-ID', $this->unitA->id)
        ->getJson('/api/v1/held-carts')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect($listResponse->json('data.0.label'))->toBe('Phone call in progress')
        ->and($listResponse->json('data.1.label'))->toBe('Customer stepped to ATM');

    // Listing for unitB should have only unitB's cart
    $this->actingAs($this->cashierB)
        ->withHeader('X-Operating-Unit-ID', $this->unitB->id)
        ->getJson('/api/v1/held-carts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.label', 'Unit B cart');
});

test('can delete a held cart in the unit', function () {
    $cart = HeldCart::create([
        'operating_unit_id' => $this->unitA->id,
        'user_id' => $this->cashierA->id,
        'label' => 'Cart to delete',
        'payload' => ['lines' => []],
        'total' => 100,
        'line_count' => 1,
    ]);

    $this->actingAs($this->cashierA)
        ->withHeader('X-Operating-Unit-ID', $this->unitA->id)
        ->deleteJson("/api/v1/held-carts/{$cart->id}")
        ->assertNoContent();

    expect(HeldCart::withoutGlobalScopes()->whereKey($cart->id)->exists())->toBeFalse();
});

test('another units cart cannot be deleted returning 404', function () {
    $unitBCart = HeldCart::create([
        'operating_unit_id' => $this->unitB->id,
        'user_id' => $this->cashierB->id,
        'label' => 'Unit B secret cart',
        'payload' => ['lines' => []],
        'total' => 200,
        'line_count' => 1,
    ]);

    $this->actingAs($this->cashierA)
        ->withHeader('X-Operating-Unit-ID', $this->unitA->id)
        ->deleteJson("/api/v1/held-carts/{$unitBCart->id}")
        ->assertNotFound();

    expect(HeldCart::withoutGlobalScopes()->whereKey($unitBCart->id)->exists())->toBeTrue();
});

test('refuses 31st cart in same unit with 422 HELD_CART_LIMIT', function () {
    for ($i = 1; $i <= 30; $i++) {
        HeldCart::create([
            'operating_unit_id' => $this->unitA->id,
            'user_id' => $this->cashierA->id,
            'label' => "Cart {$i}",
            'payload' => ['lines' => []],
            'total' => 10,
            'line_count' => 1,
        ]);
    }

    $this->actingAs($this->cashierA)
        ->withHeader('X-Operating-Unit-ID', $this->unitA->id)
        ->postJson('/api/v1/held-carts', [
            'label' => 'Cart 31',
            'payload' => ['lines' => []],
            'total' => 10,
            'line_count' => 1,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'HELD_CART_LIMIT');

    expect(HeldCart::where('operating_unit_id', $this->unitA->id)->count())->toBe(30);

    // Another unit can still store carts up to its own limit
    $this->actingAs($this->cashierB)
        ->withHeader('X-Operating-Unit-ID', $this->unitB->id)
        ->postJson('/api/v1/held-carts', [
            'label' => 'Unit B first cart',
            'payload' => ['lines' => []],
            'total' => 10,
            'line_count' => 1,
        ])
        ->assertCreated();
});

test('refuses cart when json-encoded payload exceeds 64 KB', function () {
    $bigPayload = ['blob' => str_repeat('X', 66 * 1024)];

    $this->actingAs($this->cashierA)
        ->withHeader('X-Operating-Unit-ID', $this->unitA->id)
        ->postJson('/api/v1/held-carts', [
            'payload' => $bigPayload,
            'total' => 10,
            'line_count' => 1,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'HELD_CART_TOO_LARGE');

    expect(HeldCart::count())->toBe(0);
});

test('role outside sales group is refused with 403', function () {
    $cart = HeldCart::create([
        'operating_unit_id' => $this->unitA->id,
        'user_id' => $this->cashierA->id,
        'label' => 'Existing Cart',
        'payload' => ['lines' => []],
        'total' => 10,
        'line_count' => 1,
    ]);

    $this->actingAs($this->hrUser)
        ->withHeader('X-Operating-Unit-ID', $this->unitA->id)
        ->getJson('/api/v1/held-carts')
        ->assertStatus(403);

    $this->actingAs($this->hrUser)
        ->withHeader('X-Operating-Unit-ID', $this->unitA->id)
        ->postJson('/api/v1/held-carts', [
            'payload' => ['lines' => []],
            'total' => 10,
            'line_count' => 1,
        ])
        ->assertStatus(403);

    $this->actingAs($this->hrUser)
        ->withHeader('X-Operating-Unit-ID', $this->unitA->id)
        ->deleteJson("/api/v1/held-carts/{$cart->id}")
        ->assertStatus(403);
});

test('held carts post nothing to the ledger and move no stock', function () {
    $initialStock = (float) StockLot::where('id', $this->stockLot->id)->value('quantity');
    $initialEntries = JournalEntry::count();

    $res = $this->actingAs($this->cashierA)
        ->withHeader('X-Operating-Unit-ID', $this->unitA->id)
        ->postJson('/api/v1/held-carts', [
            'label' => 'No ledger impact cart',
            'client_id' => $this->clientA->id,
            'payload' => [
                'lines' => [
                    ['inventory_item_id' => $this->item->id, 'quantity' => 5, 'unit_price' => 100],
                ],
            ],
            'total' => 500,
            'line_count' => 1,
        ])->assertCreated();

    $cartId = $res->json('data.id');

    expect((float) StockLot::where('id', $this->stockLot->id)->value('quantity'))->toBe($initialStock)
        ->and(JournalEntry::count())->toBe($initialEntries);

    $this->actingAs($this->cashierA)
        ->withHeader('X-Operating-Unit-ID', $this->unitA->id)
        ->deleteJson("/api/v1/held-carts/{$cartId}")
        ->assertNoContent();

    expect((float) StockLot::where('id', $this->stockLot->id)->value('quantity'))->toBe($initialStock)
        ->and(JournalEntry::count())->toBe($initialEntries);
});

test('operating unit context is required and cannot come from the body', function () {
    $owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Owner']);
    UserRole::create([
        'user_id' => $owner->id,
        'role_id' => $ownerRole->id,
        'operating_unit_id' => null,
    ]);

    $this->actingAs($owner)
        ->getJson('/api/v1/held-carts')
        ->assertStatus(422)
        ->assertJsonPath('code', 'OPERATING_UNIT_REQUIRED');

    $this->actingAs($owner)
        ->postJson('/api/v1/held-carts', [
            'operating_unit_id' => $this->unitA->id,
            'payload' => ['lines' => []],
            'total' => 10,
            'line_count' => 1,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'OPERATING_UNIT_REQUIRED');
});
