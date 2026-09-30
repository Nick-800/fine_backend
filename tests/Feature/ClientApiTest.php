<?php

declare(strict_types=1);

use App\Enums\ClientStatus;
use App\Enums\EntityRoleType;
use App\Enums\EntityType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Test Company', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Standard Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->operatingUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Showroom Unit',
        'unit_type' => 'showroom',
        'currency' => 'LYD',
        'status' => 'active',
    ]);

    $this->role = Role::create([
        'name' => 'Admin',
        'slug' => 'admin',
    ]);

    $this->user = User::factory()->create([
        'must_change_password' => false,
    ]);

    UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => $this->role->id,
        'operating_unit_id' => $this->operatingUnit->id,
    ]);
});

test('user can update client entity data, contact phone, and terms', function () {
    // 1. Create client
    $createResponse = $this->actingAs($this->user)
        ->withHeader('X-Operating-Unit-ID', $this->operatingUnit->id)
        ->postJson('/api/v1/clients', [
            'name' => 'Original Client LLC',
            'entity_type' => 'organization',
            'tax_number' => 'TAX-1111',
            'phone' => '+218911111111',
            'city' => 'Tripoli',
            'address' => 'Gargaresh',
            'operating_unit_id' => $this->operatingUnit->id,
            'credit_limit' => 5000,
            'payment_terms_days' => 15,
            'coa_action' => 'none',
        ]);

    $createResponse->assertStatus(201)
        ->assertJsonPath('data.entity.name', 'Original Client LLC')
        ->assertJsonPath('data.entity.phone', '+218911111111')
        ->assertJsonPath('data.entity.city', 'Tripoli');

    $clientId = $createResponse->json('data.id');
    $recordVersion = $createResponse->json('data.record_version');

    // 2. Update client with new entity details and phone
    $updateResponse = $this->actingAs($this->user)
        ->withHeader('X-Operating-Unit-ID', $this->operatingUnit->id)
        ->putJson("/api/v1/clients/{$clientId}", [
            'record_version' => $recordVersion,
            'name' => 'Al-Madina Contracting Co.',
            'entity_type' => 'organization',
            'tax_number' => 'TAX-9999',
            'phone' => '+218922222222',
            'city' => 'Tajoura',
            'address' => 'Coastal Road Km 12',
            'credit_limit' => 15000,
            'payment_terms_days' => 45,
            'status' => 'suspended',
        ]);

    $updateResponse->assertStatus(200)
        ->assertJsonPath('data.entity.name', 'Al-Madina Contracting Co.')
        ->assertJsonPath('data.entity.tax_number', 'TAX-9999')
        ->assertJsonPath('data.entity.phone', '+218922222222')
        ->assertJsonPath('data.entity.city', 'Tajoura')
        ->assertJsonPath('data.entity.address', 'Coastal Road Km 12')
        ->assertJsonPath('data.credit_limit', '15000.0000')
        ->assertJsonPath('data.payment_terms_days', 45)
        ->assertJsonPath('data.status', 'suspended');

    // 3. Verify database state
    $this->assertDatabaseHas('entities', [
        'name' => 'Al-Madina Contracting Co.',
        'tax_number' => 'TAX-9999',
    ]);

    $this->assertDatabaseHas('entity_contacts', [
        'phone' => '+218922222222',
        'city' => 'Tajoura',
        'address' => 'Coastal Road Km 12',
        'is_primary' => true,
    ]);

    $this->assertDatabaseHas('clients', [
        'id' => $clientId,
        'credit_limit' => 15000,
        'payment_terms_days' => 45,
        'status' => 'suspended',
    ]);
});
