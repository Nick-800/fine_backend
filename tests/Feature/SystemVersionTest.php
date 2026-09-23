<?php

declare(strict_types=1);

use App\Models\AppVersion;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\SystemBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('allows guests to record desktop version and returns both desktop and backend versions', function (): void {
    config(['app.version' => '1.0.0']);

    $response = $this->postJson('/api/v1/system/version', [
        'desktop_version' => '1.0.24',
        'platform' => 'win32',
    ]);

    $response->assertStatus(201)
        ->assertJson([
            'desktop_version' => '1.0.24',
            'backend_version' => '1.0.0',
            'platform' => 'win32',
        ]);

    $this->assertDatabaseHas('app_versions', [
        'desktop_version' => '1.0.24',
        'backend_version' => '1.0.0',
        'platform' => 'win32',
    ]);
});

it('allows guests to fetch current backend version', function (): void {
    config(['app.version' => '1.2.3']);

    $response = $this->getJson('/api/v1/system/version');

    $response->assertStatus(200)
        ->assertJson([
            'backend_version' => '1.2.3',
        ]);
});

it('records authenticated user and operating unit when provided', function (): void {
    $this->seed(SystemBootstrapSeeder::class);
    config(['app.version' => '1.0.0']);

    $user = User::where('email', 'owner@erp.com')->firstOrFail();
    $unit = OperatingUnit::firstOrFail();

    $response = $this->actingAs($user, 'sanctum')
        ->withHeader('X-Operating-Unit-ID', $unit->id)
        ->postJson('/api/v1/system/version', [
            'desktop_version' => '1.0.24',
            'platform' => 'linux',
        ]);

    $response->assertStatus(201);

    $this->assertDatabaseHas('app_versions', [
        'desktop_version' => '1.0.24',
        'backend_version' => '1.0.0',
        'user_id' => $user->id,
        'operating_unit_id' => $unit->id,
        'platform' => 'linux',
    ]);
});

it('restricts listing version records to owner role', function (): void {
    $this->seed(SystemBootstrapSeeder::class);

    $unit = OperatingUnit::firstOrFail();

    // Guest cannot view version history
    $this->getJson('/api/v1/system/versions')
        ->assertStatus(401);

    // Non-owner staff receives 403 Forbidden
    $staff = User::where('email', 'cashier@erp.com')->firstOrFail();

    $this->actingAs($staff, 'sanctum')
        ->withHeader('X-Operating-Unit-ID', $unit->id)
        ->getJson('/api/v1/system/versions')
        ->assertStatus(403);

    // Owner can view version history
    $owner = User::where('email', 'owner@erp.com')->firstOrFail();

    AppVersion::create([
        'desktop_version' => '1.0.24',
        'backend_version' => '1.0.0',
    ]);

    $response = $this->actingAs($owner, 'sanctum')
        ->withHeader('X-Operating-Unit-ID', $unit->id)
        ->getJson('/api/v1/system/versions');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'desktop_version', 'backend_version', 'created_at'],
            ],
        ]);
});
