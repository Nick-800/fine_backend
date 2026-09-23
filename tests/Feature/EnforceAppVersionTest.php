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
