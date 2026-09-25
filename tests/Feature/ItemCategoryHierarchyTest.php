<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);

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

test('creating a root category derives its code from the segment alone', function () {
    $root = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/item-categories', [
            'name' => 'قوالب إسفنج',
            'code_segment' => '01',
            'child_code_length' => 2,
        ]);

    $root->assertStatus(201)
        ->assertJsonPath('code_segment', '01')
        ->assertJsonPath('code', '01')
        ->assertJsonPath('parent_id', null);
});

test('creating a child category derives its code from the parent code plus its own segment', function () {
    $root = ItemCategory::create(['name' => 'قوالب إسفنج', 'code_segment' => '01']);

    $child = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/item-categories', [
            'parent_id' => $root->id,
            'name' => 'قالب 14-12',
            'code_segment' => '01',
        ]);

    $child->assertStatus(201)
        ->assertJsonPath('parent_id', $root->id)
        ->assertJsonPath('code', '0101');
});

test('rejects a segment whose resulting code is already taken', function () {
    $root = ItemCategory::create(['name' => 'Root', 'code_segment' => '01']);
    ItemCategory::create(['name' => 'Existing Child', 'code_segment' => '01', 'parent_id' => $root->id]);

    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/item-categories', [
            'parent_id' => $root->id,
            'name' => 'Duplicate Child',
            'code_segment' => '01',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['code_segment']);
});

test('editing a category segment recomputes its own code and cascades to descendants', function () {
    $root = ItemCategory::create(['name' => 'Root', 'code_segment' => '01']);
    $child = ItemCategory::create(['name' => 'Child', 'code_segment' => '01', 'parent_id' => $root->id]);
    $grandchild = ItemCategory::create(['name' => 'Grandchild', 'code_segment' => '01', 'parent_id' => $child->id]);

    expect($child->fresh()->code)->toBe('0101');
    expect($grandchild->fresh()->code)->toBe('010101');

    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->putJson("/api/v1/item-categories/{$root->id}", [
            'code_segment' => '02',
        ]);

    $response->assertStatus(200)->assertJsonPath('code', '02');

    expect($child->fresh()->code)->toBe('0201');
    expect($grandchild->fresh()->code)->toBe('020101');
});

test('root_only filter returns only top-level categories and parent_id filter returns children', function () {
    $root = ItemCategory::create(['name' => 'Root', 'code_segment' => '01']);
    $child = ItemCategory::create(['name' => 'Child', 'code_segment' => '01', 'parent_id' => $root->id]);

    $rootList = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/item-categories?root_only=1');

    $rootList->assertStatus(200)
        ->assertJsonFragment(['id' => $root->id])
        ->assertJsonMissing(['id' => $child->id]);

    $childList = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson("/api/v1/item-categories?parent_id={$root->id}");

    $childList->assertStatus(200)
        ->assertJsonFragment(['id' => $child->id])
        ->assertJsonMissing(['id' => $root->id]);
});

test('can update a category name without touching its code', function () {
    $category = ItemCategory::create(['name' => 'Root', 'code_segment' => '01']);

    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->putJson("/api/v1/item-categories/{$category->id}", [
            'name' => 'Root Renamed',
            'child_code_length' => 3,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('name', 'Root Renamed')
        ->assertJsonPath('code', '01')
        ->assertJsonPath('child_code_length', 3);
});

test('cannot delete a category that still has children', function () {
    $root = ItemCategory::create(['name' => 'Root', 'code_segment' => '01']);
    ItemCategory::create(['name' => 'Child', 'code_segment' => '01', 'parent_id' => $root->id]);

    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->deleteJson("/api/v1/item-categories/{$root->id}");

    $response->assertStatus(422)
        ->assertJsonPath('code', 'CATEGORY_HAS_CHILDREN');

    expect(ItemCategory::find($root->id))->not->toBeNull();
});

test('cannot delete a category that still has inventory items', function () {
    $category = ItemCategory::create(['name' => 'Root', 'code_segment' => '01']);
    InventoryItem::create([
        'category_id' => $category->id,
        'name' => 'Foam Block',
        'code' => 'BLOCK-01',
        'item_type' => 'foam_block',
        'unit_of_measure' => 'm3',
    ]);

    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->deleteJson("/api/v1/item-categories/{$category->id}");

    $response->assertStatus(422)
        ->assertJsonPath('code', 'CATEGORY_HAS_ITEMS');
});

test('can delete a leaf category with no children or items', function () {
    $category = ItemCategory::create(['name' => 'Root', 'code_segment' => '01']);

    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->deleteJson("/api/v1/item-categories/{$category->id}");

    $response->assertStatus(200);
    expect(ItemCategory::find($category->id))->toBeNull();
});
