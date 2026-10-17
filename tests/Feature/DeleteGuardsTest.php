<?php

declare(strict_types=1);

use App\Enums\EntityRoleType;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\EntityRole;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\ProductionBatch;
use App\Models\ReferenceLookup;
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
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Store', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Showroom', 'unit_type' => 'store', 'currency' => 'LYD', 'status' => 'active',
    ]);
    $this->warehouse = Warehouse::create(['operating_unit_id' => $this->unit->id, 'name' => 'Main', 'code' => 'WH-1']);

    $owner = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $owner->id, 'role_id' => $role->id, 'operating_unit_id' => null]);
    $this->api = fn () => $this->actingAs($owner);

    $this->makeItem = fn (string $code) => InventoryItem::create([
        'name' => "Item {$code}", 'code' => $code, 'item_type' => 'raw_material', 'unit_of_measure' => 'kg',
    ]);

    $this->makeClient = function (string $name): Client {
        $entity = Entity::create(['name' => $name, 'entity_type' => 'organization']);
        EntityRole::create(['entity_id' => $entity->id, 'role_type' => EntityRoleType::Client->value]);

        return Client::create([
            'entity_id' => $entity->id, 'operating_unit_id' => $this->unit->id,
            'credit_limit' => 1000, 'current_balance' => 0,
        ]);
    };
});

test('an item with stock on hand cannot be deleted', function () {
    $item = ($this->makeItem)('CHEM-1');
    StockLot::create([
        'warehouse_id' => $this->warehouse->id, 'inventory_item_id' => $item->id,
        'lot_number' => 'L-1', 'quantity' => 5, 'unit_cost' => 10, 'status' => 'available',
    ]);

    ($this->api)()->deleteJson("/api/v1/inventory-items/{$item->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'ITEM_IN_USE')
        ->assertJsonPath('referenced_by', 'stock_lots');

    expect(InventoryItem::find($item->id))->not->toBeNull();
});

test('an unused item can be deleted', function () {
    $item = ($this->makeItem)('CHEM-2');

    ($this->api)()->deleteJson("/api/v1/inventory-items/{$item->id}")->assertOk();

    expect(InventoryItem::find($item->id))->toBeNull();
});

test('the base currency cannot be deleted', function () {
    $lyd = ReferenceLookup::create(['category' => 'currencies', 'name' => 'دينار ليبي', 'code' => 'LYD', 'is_active' => true]);

    ($this->api)()->deleteJson("/api/v1/reference-lookups/currencies/{$lyd->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'BASE_CURRENCY');
});

test('a currency used by a treasury cannot be deleted, an unused one can', function () {
    $usd = ReferenceLookup::create(['category' => 'currencies', 'name' => 'دولار', 'code' => 'USD', 'is_active' => true]);
    $eur = ReferenceLookup::create(['category' => 'currencies', 'name' => 'يورو', 'code' => 'EUR', 'is_active' => true]);

    CashAccount::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'USD box', 'kind' => 'cash', 'currency' => 'USD',
        'account_id' => Account::where('account_code', '121102')->value('id'),
    ]);

    ($this->api)()->deleteJson("/api/v1/reference-lookups/currencies/{$usd->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'CURRENCY_IN_USE');

    ($this->api)()->deleteJson("/api/v1/reference-lookups/currencies/{$eur->id}")->assertOk();

    expect(ReferenceLookup::find($usd->id))->not->toBeNull()
        ->and(ReferenceLookup::find($eur->id))->toBeNull();
});

test('a client with history cannot be deleted', function () {
    $client = ($this->makeClient)('Busy Client');
    ProductionBatch::create([
        'operating_unit_id' => $this->unit->id, 'operation_number' => 901, 'bun_width_m' => 2.4,
        'status' => 'planned', 'requested_by_client_id' => $client->id,
    ]);

    ($this->api)()->deleteJson("/api/v1/clients/{$client->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'CLIENT_HAS_TRANSACTIONS');

    expect(Client::withoutGlobalScopes()->find($client->id))->not->toBeNull();
});

test('a client without history is deleted along with its client role', function () {
    $client = ($this->makeClient)('Fresh Client');

    ($this->api)()->deleteJson("/api/v1/clients/{$client->id}")->assertOk();

    expect(Client::withoutGlobalScopes()->find($client->id))->toBeNull()
        ->and(EntityRole::where('entity_id', $client->entity_id)->where('role_type', 'client')->exists())->toBeFalse()
        ->and(Entity::find($client->entity_id))->not->toBeNull();
});
