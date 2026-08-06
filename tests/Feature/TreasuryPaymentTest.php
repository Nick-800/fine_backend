<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Treasury Management Co',
        'default_currency' => 'LYD',
        'overhead_absorption_enabled' => true,
        'transfer_pricing_mode' => 'cost_plus',
        'timezone' => 'UTC',
    ]);

    $this->operatingUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'name' => 'Treasury Branch',
        'unit_type' => 'headquarters',
        'currency' => 'LYD',
        'status' => 'active',
    ]);

    $this->user = User::factory()->create([
        'operating_unit_id' => $this->operatingUnit->id,
        'must_change_password' => false,
    ]);
});

test('can record fx rates and cash accounts', function () {
    $fxResponse = $this->actingAs($this->user)
        ->withHeader('X-Operating-Unit-ID', $this->operatingUnit->id)
        ->postJson('/api/v1/fx-rates', [
            'from_currency' => 'USD',
            'to_currency' => 'LYD',
            'rate' => 5.150000,
        ]);

    $fxResponse->assertStatus(201)
        ->assertJsonPath('data.from_currency', 'USD')
        ->assertJsonPath('data.to_currency', 'LYD')
        ->assertJsonPath('data.rate', 5.15);

    $cashResponse = $this->actingAs($this->user)
        ->withHeader('X-Operating-Unit-ID', $this->operatingUnit->id)
        ->postJson('/api/v1/cash-accounts', [
            'operating_unit_id' => $this->operatingUnit->id,
            'name' => 'Main Treasury Safe',
            'currency' => 'LYD',
            'balance' => 500000.00,
        ]);

    $cashResponse->assertStatus(201)
        ->assertJsonPath('data.name', 'Main Treasury Safe')
        ->assertJsonPath('data.balance', 500000);
});
