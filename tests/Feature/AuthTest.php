<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('disallows public self registration endpoint', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'New User',
        'email' => 'newuser@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(404);
});

it('can log in with correct credentials', function (): void {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'user@example.com',
        'password' => Hash::make('password'),
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'user@example.com',
        'password' => 'password',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'access_token',
            'token_type',
            'user' => ['id', 'name', 'email', 'is_active', 'record_version'],
        ]);
});

it('cannot log in with incorrect credentials', function (): void {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'nonexistent@example.com',
        'password' => 'wrongpassword',
    ]);

    $response->assertStatus(422);
});

it('cannot log in when deactivated', function (): void {
    $user = User::create([
        'name' => 'Deactivated User',
        'email' => 'deactivated@example.com',
        'password' => Hash::make('password'),
        'is_active' => false,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'deactivated@example.com',
        'password' => 'password',
    ]);

    $response->assertStatus(403);
});

it('can retrieve current user details', function (): void {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'user@example.com',
        'password' => Hash::make('password'),
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/auth/me');

    $response->assertStatus(200)
        ->assertJsonPath('data.email', 'user@example.com');
});

it('can log out successfully', function (): void {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'user@example.com',
        'password' => Hash::make('password'),
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/auth/logout');

    $response->assertStatus(200)
        ->assertJsonPath('message', 'Logged out successfully.');
});
