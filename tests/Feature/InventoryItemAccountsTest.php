<?php

declare(strict_types=1);

use App\Enums\InventoryEventType;
use App\Models\Account;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryItemAccount;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);
    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'WH', 'code' => 'WH-1',
    ]);

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Foam Manager', 'slug' => 'foam-manager']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unit->id]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $this->fabric = InventoryItem::create([
        'name' => 'Fabric', 'code' => 'FAB-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'meter',
    ]);

    // Convenience: lookup known accounts from the seeded chart.
    $this->inventoryAccount = Account::where('account_code', '111')->sole();
    $this->salesAccount = Account::where('account_code', '41001')->first()
        ?? Account::where('account_code', '41')->first()
        ?? Account::where('account_code', '40201')->sole();
    $this->cogsAccount = Account::where('account_code', '51')->sole();
});

/**
 * Link one event_type on the given item to the given account code.
 */
function linkEvent(InventoryItem $item, InventoryEventType $event, Account $account): void
{
    InventoryItemAccount::create([
        'inventory_item_id' => $item->id,
        'event_type' => $event->value,
        'account_id' => $account->id,
    ]);
}

test('list endpoint returns all 12 events with null accounts when nothing is linked', function () {
    $response = ($this->api)()->getJson("/api/v1/inventory-items/{$this->fabric->id}/accounts");

    $response->assertOk();
    $payload = $response->json('data');
    expect($payload)->toHaveCount(12)
        ->and(collect($payload)->pluck('event_type')->all())
        ->toBe(array_column(InventoryEventType::cases(), 'value'));

    foreach ($payload as $row) {
        expect($row['account'])->toBeNull();
    }
    expect($response->json('meta.total_events'))->toBe(12)
        ->and($response->json('meta.linked_count'))->toBe(0);
});

test('upsert creates a per-event account link', function () {
    $response = ($this->api)()->postJson("/api/v1/inventory-items/{$this->fabric->id}/accounts", [
        'event_type' => 'purchases',
        'account_id' => $this->inventoryAccount->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.event_type', 'purchases')
        ->assertJsonPath('data.account.account_code', '111');

    expect(InventoryItemAccount::where('inventory_item_id', $this->fabric->id)
        ->where('event_type', 'purchases')->count())->toBe(1);
});

test('upsert updates an existing per-event link in place', function () {
    $alt = Account::create([
        'chart_of_accounts_id' => $this->inventoryAccount->chart_of_accounts_id,
        'parent_account_id' => $this->inventoryAccount->id,
        'account_code' => '111001',
        'name' => 'Fabric override',
        'type' => 'asset',
        'section' => 'current_assets',
        'currency' => 'LYD',
    ]);

    ($this->api)()->postJson("/api/v1/inventory-items/{$this->fabric->id}/accounts", [
        'event_type' => 'purchases',
        'account_id' => $this->inventoryAccount->id,
    ])->assertOk();

    ($this->api)()->postJson("/api/v1/inventory-items/{$this->fabric->id}/accounts", [
        'event_type' => 'purchases',
        'account_id' => $alt->id,
    ])->assertOk()->assertJsonPath('data.account.account_code', '111001');

    expect(InventoryItemAccount::where('inventory_item_id', $this->fabric->id)
        ->where('event_type', 'purchases')->count())->toBe(1);
});

test('upsert rejects an unknown event_type', function () {
    ($this->api)()->postJson("/api/v1/inventory-items/{$this->fabric->id}/accounts", [
        'event_type' => 'not_a_real_event',
        'account_id' => $this->inventoryAccount->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['event_type']);
});

test('upsert rejects a missing account_id', function () {
    ($this->api)()->postJson("/api/v1/inventory-items/{$this->fabric->id}/accounts", [
        'event_type' => 'purchases',
    ])->assertStatus(422)->assertJsonValidationErrors(['account_id']);
});

test('delete removes the link', function () {
    linkEvent($this->fabric, InventoryEventType::Purchases, $this->inventoryAccount);

    $row = InventoryItemAccount::where('inventory_item_id', $this->fabric->id)
        ->where('event_type', 'purchases')->sole();

    ($this->api)()->deleteJson("/api/v1/inventory-items/{$this->fabric->id}/accounts/{$row->id}")
        ->assertOk();

    expect(InventoryItemAccount::where('inventory_item_id', $this->fabric->id)->count())->toBe(0);
});

test('deleting the inventory item cascades its account rows', function () {
    linkEvent($this->fabric, InventoryEventType::Purchases, $this->inventoryAccount);
    linkEvent($this->fabric, InventoryEventType::Sales, $this->salesAccount);

    expect(InventoryItemAccount::where('inventory_item_id', $this->fabric->id)->count())->toBe(2);

    // Force delete (not soft delete) so the cascadeOnDelete actually fires.
    $this->fabric->forceDelete();

    expect(InventoryItemAccount::where('inventory_item_id', $this->fabric->id)->count())->toBe(0);
});

test('an account that is still linked cannot be deleted (restrictOnDelete)', function () {
    linkEvent($this->fabric, InventoryEventType::Purchases, $this->inventoryAccount);

    expect(fn () => $this->inventoryAccount->delete())
        ->toThrow(QueryException::class);
});

test('accountFor returns the linked account and null otherwise', function () {
    expect($this->fabric->accountFor(InventoryEventType::Purchases))->toBeNull();

    linkEvent($this->fabric, InventoryEventType::Purchases, $this->inventoryAccount);

    $resolved = $this->fabric->accountFor(InventoryEventType::Purchases);
    expect($resolved)->not->toBeNull()
        ->and($resolved->account_code)->toBe('111');
});

test('inventory item is exposed with eager-loaded account rows on show and index', function () {
    linkEvent($this->fabric, InventoryEventType::Purchases, $this->inventoryAccount);
    linkEvent($this->fabric, InventoryEventType::Sales, $this->salesAccount);

    $show = ($this->api)()->getJson("/api/v1/inventory-items/{$this->fabric->id}")->assertOk();
    expect($show->json('accounts'))->toHaveCount(2);

    $index = ($this->api)()->getJson('/api/v1/inventory-items')->assertOk();
    $row = collect($index->json('data'))->firstWhere('id', $this->fabric->id);
    expect($row['accounts'])->toHaveCount(2);
});
