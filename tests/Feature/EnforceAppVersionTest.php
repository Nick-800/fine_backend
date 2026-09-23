<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::forget('app:min_desktop_version');
    Cache::forget('app:latest_desktop_version');
    config(['app.min_desktop_version' => null]);
});

it('allows API access when no minimum desktop version is configured', function (): void {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'secret',
    ]);

    // Should reach validation/auth failure (422), NOT 426
    $response->assertStatus(422);
});

it('rejects API requests when desktop version is lower than required minimum', function (): void {
    config(['app.min_desktop_version' => '1.0.25']);

    $response = $this->withHeader('X-Desktop-Version', '1.0.24')
        ->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'secret',
        ]);

    $response->assertStatus(426)
        ->assertJson([
            'code' => 'FORCE_UPDATE_REQUIRED',
            'required_version' => '1.0.25',
            'current_version' => '1.0.24',
        ]);
});

it('rejects API requests when X-Desktop-Version header is missing and minimum version is configured', function (): void {
    config(['app.min_desktop_version' => '1.0.25']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'secret',
    ]);

    $response->assertStatus(426)
        ->assertJson([
            'code' => 'FORCE_UPDATE_REQUIRED',
            'required_version' => '1.0.25',
        ]);
});

it('allows API access when X-Desktop-Version matches or exceeds the required version', function (): void {
    config(['app.min_desktop_version' => '1.0.25']);

    $response = $this->withHeader('X-Desktop-Version', '1.0.25')
        ->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'secret',
        ]);

    $response->assertStatus(422); // Reached AuthController, not blocked by middleware
});

it('exempts /api/v1/system/version from 426 rejection so obsolete clients can fetch update instructions', function (): void {
    config(['app.min_desktop_version' => '1.0.25']);

    $response = $this->withHeader('X-Desktop-Version', '1.0.24')
        ->getJson('/api/v1/system/version');

    $response->assertStatus(200)
        ->assertJson([
            'min_desktop_version' => '1.0.25',
            'is_update_required' => true,
        ]);
});

it('can enforce version via artisan command and immediately block older versions', function (): void {
    Artisan::call('app:enforce-version', ['version' => '2.0.0']);

    $response = $this->withHeader('X-Desktop-Version', '1.0.25')
        ->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'secret',
        ]);

    $response->assertStatus(426)
        ->assertJson([
            'code' => 'FORCE_UPDATE_REQUIRED',
            'required_version' => '2.0.0',
        ]);
});

it('automatically enforces newly reported build version without running any commands', function (): void {
    config(['app.auto_enforce_latest_build' => true]);

    // Initial state: 1.0.25 is recorded
    $this->postJson('/api/v1/system/version', [
        'desktop_version' => '1.0.25',
    ])->assertStatus(201);

    // Verify 1.0.25 is now automatically enforced
    expect(Cache::get('app:min_desktop_version'))->toBe('1.0.25');

    // A client running 1.0.24 is now blocked
    $this->withHeader('X-Desktop-Version', '1.0.24')
        ->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'secret',
        ])
        ->assertStatus(426)
        ->assertJson([
            'code' => 'FORCE_UPDATE_REQUIRED',
            'required_version' => '1.0.25',
            'current_version' => '1.0.24',
        ]);

    // Next: New build 1.0.26 is launched and calls /system/version
    $this->postJson('/api/v1/system/version', [
        'desktop_version' => '1.0.26',
    ])->assertStatus(201);

    // Verify 1.0.26 is automatically promoted without any command
    expect(Cache::get('app:min_desktop_version'))->toBe('1.0.26');

    // Client running 1.0.25 is now blocked
    $this->withHeader('X-Desktop-Version', '1.0.25')
        ->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'secret',
        ])
        ->assertStatus(426)
        ->assertJson([
            'code' => 'FORCE_UPDATE_REQUIRED',
            'required_version' => '1.0.26',
            'current_version' => '1.0.25',
        ]);
});

it('recovers enforced version from database after cache is cleared', function (): void {
    config(['app.auto_enforce_latest_build' => true]);

    // Record build in database
    $this->postJson('/api/v1/system/version', [
        'desktop_version' => '1.0.30',
    ])->assertStatus(201);

    // Clear cache completely (simulating server restart or cache:clear)
    Cache::flush();
    expect(Cache::get('app:min_desktop_version'))->toBeNull();

    // Any incoming request automatically queries AppVersion, restores cache, and enforces 1.0.30
    $this->withHeader('X-Desktop-Version', '1.0.25')
        ->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'secret',
        ])
        ->assertStatus(426)
        ->assertJson([
            'code' => 'FORCE_UPDATE_REQUIRED',
            'required_version' => '1.0.30',
        ]);

    expect(Cache::get('app:min_desktop_version'))->toBe('1.0.30');
});

