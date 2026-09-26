<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\ReferenceLookup;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Test Company', 'default_currency' => 'LYD']);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'BP',
        'workflow_set' => '[]',
        'default_role_template' => '[]',
        'default_inventory_config' => '[]',
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Main Unit',
        'code' => 'UNIT-1',
        'unit_type' => 'manufactory',
        'status' => 'active',
    ]);

    $this->ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    $this->cashierRole = Role::create(['name' => 'Cashier', 'slug' => 'pos-cashier']);

    $this->ownerUser = User::factory()->create(['must_change_password' => false]);
    UserRole::create([
        'user_id' => $this->ownerUser->id,
        'role_id' => $this->ownerRole->id,
        'operating_unit_id' => null,
    ]);

    $this->standardUser = User::factory()->create(['must_change_password' => false]);
    UserRole::create([
        'user_id' => $this->standardUser->id,
        'role_id' => $this->cashierRole->id,
        'operating_unit_id' => $this->unit->id,
    ]);
});

test('unauthenticated users cannot access reference lookups', function () {
    $this->getJson('/api/v1/reference-lookups/cities')
        ->assertStatus(401);
});

test('authenticated users can list reference lookups for a category', function () {
    Sanctum::actingAs($this->standardUser);

    ReferenceLookup::create([
        'category' => 'cities',
        'name' => 'طبرق',
        'code' => 'TOB',
        'is_active' => true,
        'fields' => ['country' => 'ليبيا'],
    ]);

    ReferenceLookup::create([
        'category' => 'cities',
        'name' => 'بنغازي',
        'code' => 'BNG',
        'is_active' => false,
        'fields' => ['country' => 'ليبيا'],
    ]);

    ReferenceLookup::create([
        'category' => 'banks',
        'name' => 'مصرف الجمهورية',
        'code' => 'JUM',
        'is_active' => true,
        'fields' => ['branch' => 'طبرق'],
    ]);

    $response = $this->withHeader('X-Operating-Unit-ID', (string) $this->unit->id)
        ->getJson('/api/v1/reference-lookups/cities')
        ->assertStatus(200);

    $data = $response->json();
    expect($data)->toHaveCount(2)
        ->and($data[0]['name'])->toBe('بنغازي')
        ->and($data[1]['name'])->toBe('طبرق');
});

test('listing supports search and active filters', function () {
    Sanctum::actingAs($this->standardUser);

    ReferenceLookup::create([
        'category' => 'cities',
        'name' => 'طبرق',
        'code' => 'TOB',
        'is_active' => true,
    ]);

    ReferenceLookup::create([
        'category' => 'cities',
        'name' => 'طرابلس',
        'code' => 'TIP',
        'is_active' => false,
    ]);

    $response = $this->withHeader('X-Operating-Unit-ID', (string) $this->unit->id)
        ->getJson('/api/v1/reference-lookups/cities?search=طبرق')
        ->assertStatus(200);

    expect($response->json())->toHaveCount(1)
        ->and($response->json()[0]['code'])->toBe('TOB');

    $activeOnly = $this->withHeader('X-Operating-Unit-ID', (string) $this->unit->id)
        ->getJson('/api/v1/reference-lookups/cities?is_active=1')
        ->assertStatus(200);

    expect($activeOnly->json())->toHaveCount(1)
        ->and($activeOnly->json()[0]['code'])->toBe('TOB');
});

test('non-admin users cannot create, update, or delete reference lookups', function () {
    Sanctum::actingAs($this->standardUser);

    $this->withHeader('X-Operating-Unit-ID', (string) $this->unit->id)
        ->postJson('/api/v1/reference-lookups/cities', [
            'name' => 'مصراتة',
            'code' => 'MSR',
        ])
        ->assertStatus(403);
});

test('owner can create a reference lookup with tab-specific fields', function () {
    Sanctum::actingAs($this->ownerUser);

    $response = $this->withHeader('X-Operating-Unit-ID', (string) $this->unit->id)
        ->postJson('/api/v1/reference-lookups/measurement-units', [
            'name' => 'كيلوغرام',
            'code' => 'KG',
            'is_active' => true,
            'notes' => 'وحدة الوزن الأساسية',
            'fields' => [
                'symbol' => 'كجم',
                'dimension' => 'weight',
            ],
        ])
        ->assertStatus(201);

    $response->assertJsonPath('name', 'كيلوغرام');
    $response->assertJsonPath('code', 'KG');
    $response->assertJsonPath('fields.symbol', 'كجم');
    $response->assertJsonPath('fields.dimension', 'weight');

    $this->assertDatabaseHas('reference_lookups', [
        'category' => 'measurement-units',
        'name' => 'كيلوغرام',
        'code' => 'KG',
    ]);
});

test('duplicate code in same category is rejected', function () {
    Sanctum::actingAs($this->ownerUser);

    ReferenceLookup::create([
        'category' => 'currencies',
        'name' => 'الدينار الليبي',
        'code' => 'LYD',
    ]);

    $this->withHeader('X-Operating-Unit-ID', (string) $this->unit->id)
        ->postJson('/api/v1/reference-lookups/currencies', [
            'name' => 'دينار مكرر',
            'code' => 'LYD',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
});

test('same code in different category is allowed', function () {
    Sanctum::actingAs($this->ownerUser);

    ReferenceLookup::create([
        'category' => 'nationalities',
        'name' => 'ليبية',
        'code' => 'LY',
    ]);

    $this->withHeader('X-Operating-Unit-ID', (string) $this->unit->id)
        ->postJson('/api/v1/reference-lookups/currencies', [
            'name' => 'عملة رمزها LY',
            'code' => 'LY',
        ])
        ->assertStatus(201);
});

test('owner can update a reference lookup', function () {
    Sanctum::actingAs($this->ownerUser);

    $entry = ReferenceLookup::create([
        'category' => 'banks',
        'name' => 'مصرف الجمهورية',
        'code' => 'JUM',
        'fields' => ['branch' => 'القديم'],
    ]);

    $response = $this->withHeader('X-Operating-Unit-ID', (string) $this->unit->id)
        ->putJson("/api/v1/reference-lookups/banks/{$entry->id}", [
            'name' => 'مصرف الجمهورية - المحدث',
            'code' => 'JUM',
            'fields' => ['branch' => 'فرع طبرق الرئيسي'],
        ])
        ->assertStatus(200);

    $response->assertJsonPath('name', 'مصرف الجمهورية - المحدث');
    $response->assertJsonPath('fields.branch', 'فرع طبرق الرئيسي');
});

test('owner can toggle active status of a reference lookup', function () {
    Sanctum::actingAs($this->ownerUser);

    $entry = ReferenceLookup::create([
        'category' => 'cities',
        'name' => 'درنة',
        'code' => 'DRN',
        'is_active' => true,
    ]);

    $response = $this->withHeader('X-Operating-Unit-ID', (string) $this->unit->id)
        ->patchJson("/api/v1/reference-lookups/cities/{$entry->id}/toggle-active")
        ->assertStatus(200);

    expect($response->json('is_active'))->toBeFalse();
    expect($entry->fresh()->is_active)->toBeFalse();
});

test('owner can delete a reference lookup', function () {
    Sanctum::actingAs($this->ownerUser);

    $entry = ReferenceLookup::create([
        'category' => 'cities',
        'name' => 'سرت',
        'code' => 'SRT',
    ]);

    $this->withHeader('X-Operating-Unit-ID', (string) $this->unit->id)
        ->deleteJson("/api/v1/reference-lookups/cities/{$entry->id}")
        ->assertStatus(200);

    $this->assertDatabaseMissing('reference_lookups', [
        'id' => $entry->id,
    ]);
});
