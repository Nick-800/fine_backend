<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Owner']);
});

it('can log in using email via login field', function (): void {
    User::create([
        'name' => 'Email User',
        'email' => 'user@example.com',
        'phone' => null,
        'password' => Hash::make('password123'),
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'login' => 'user@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure(['access_token', 'token_type', 'user']);
});

it('can log in using phone number via login field', function (): void {
    User::create([
        'name' => 'Phone User',
        'email' => null,
        'phone' => '0912345678',
        'password' => Hash::make('password123'),
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'login' => '0912345678',
        'password' => 'password123',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure(['access_token', 'token_type', 'user'])
        ->assertJsonPath('user.phone', '0912345678');
});

it('can log in using legacy email field', function (): void {
    User::create([
        'name' => 'Legacy User',
        'email' => 'legacy@example.com',
        'phone' => '0922345678',
        'password' => Hash::make('password123'),
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'legacy@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(200);
});

it('fails login when credentials do not match', function (): void {
    $response = $this->postJson('/api/v1/auth/login', [
        'login' => '0919999999',
        'password' => 'wrongpass',
    ]);

    $response->assertStatus(422);
});

it('can create a user with only email', function (): void {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => Hash::make('password123'),
        'is_active' => true,
    ]);
    $role = Role::where('slug', 'owner')->first();
    UserRole::create(['user_id' => $admin->id, 'role_id' => $role->id]);

    $response = $this->actingAs($admin)->postJson('/api/v1/users', [
        'name' => 'Email Only',
        'email' => 'emailonly@example.com',
        'password' => 'secret1234',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.email', 'emailonly@example.com')
        ->assertJsonPath('data.phone', null);

    $this->assertDatabaseHas('users', [
        'name' => 'Email Only',
        'email' => 'emailonly@example.com',
        'phone' => null,
    ]);
});

it('can create a user with only phone number (10 digits starting with 09)', function (): void {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => Hash::make('password123'),
        'is_active' => true,
    ]);
    $role = Role::where('slug', 'owner')->first();
    UserRole::create(['user_id' => $admin->id, 'role_id' => $role->id]);

    $response = $this->actingAs($admin)->postJson('/api/v1/users', [
        'name' => 'Phone Only',
        'phone' => '0912345678',
        'password' => 'secret1234',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.email', null)
        ->assertJsonPath('data.phone', '0912345678');

    $this->assertDatabaseHas('users', [
        'name' => 'Phone Only',
        'email' => null,
        'phone' => '0912345678',
    ]);
});

it('rejects user creation when neither email nor phone is provided', function (): void {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => Hash::make('password123'),
        'is_active' => true,
    ]);
    $role = Role::where('slug', 'owner')->first();
    UserRole::create(['user_id' => $admin->id, 'role_id' => $role->id]);

    $response = $this->actingAs($admin)->postJson('/api/v1/users', [
        'name' => 'No Contact',
        'password' => 'secret1234',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'phone']);
});

it('rejects user creation when phone number does not start with 09 or is not 10 digits', function (): void {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => Hash::make('password123'),
        'is_active' => true,
    ]);
    $role = Role::where('slug', 'owner')->first();
    UserRole::create(['user_id' => $admin->id, 'role_id' => $role->id]);

    // Less than 10 digits
    $response1 = $this->actingAs($admin)->postJson('/api/v1/users', [
        'name' => 'Short Phone',
        'phone' => '091234567',
        'password' => 'secret1234',
    ]);
    $response1->assertStatus(422)->assertJsonValidationErrors(['phone']);

    // Doesn't start with 09
    $response2 = $this->actingAs($admin)->postJson('/api/v1/users', [
        'name' => 'Wrong Prefix',
        'phone' => '0812345678',
        'password' => 'secret1234',
    ]);
    $response2->assertStatus(422)->assertJsonValidationErrors(['phone']);

    // Contains non-digit
    $response3 = $this->actingAs($admin)->postJson('/api/v1/users', [
        'name' => 'Alpha Phone',
        'phone' => '091234567a',
        'password' => 'secret1234',
    ]);
    $response3->assertStatus(422)->assertJsonValidationErrors(['phone']);
});

it('rejects duplicate phone numbers across users', function (): void {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => Hash::make('password123'),
        'is_active' => true,
    ]);
    $role = Role::where('slug', 'owner')->first();
    UserRole::create(['user_id' => $admin->id, 'role_id' => $role->id]);

    User::create([
        'name' => 'First User',
        'phone' => '0912345678',
        'password' => Hash::make('pass'),
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/users', [
        'name' => 'Second User',
        'phone' => '0912345678',
        'password' => 'secret1234',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['phone']);
});

it('can update user phone and rejects wiping out both contact methods', function (): void {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => Hash::make('password123'),
        'is_active' => true,
    ]);
    $role = Role::where('slug', 'owner')->first();
    UserRole::create(['user_id' => $admin->id, 'role_id' => $role->id]);

    $user = User::create([
        'name' => 'Target User',
        'email' => 'target@example.com',
        'password' => Hash::make('pass'),
        'is_active' => true,
    ]);

    // Update with phone
    $response = $this->actingAs($admin)->putJson("/api/v1/users/{$user->id}", [
        'phone' => '0915555555',
        'record_version' => $user->record_version,
    ]);
    $response->assertStatus(200)->assertJsonPath('data.phone', '0915555555');

    // Reject wiping out both
    $responseWipe = $this->actingAs($admin)->putJson("/api/v1/users/{$user->id}", [
        'email' => null,
        'phone' => null,
        'record_version' => $user->fresh()->record_version,
    ]);
    $responseWipe->assertStatus(422);
});

it('provisions owner via create-owner console command with phone number', function (): void {
    $this->artisan('user:create-owner', [
        'identifier' => '0919876543',
        'name' => 'Phone Owner',
        '--password' => 'ownerpass123',
    ])->assertSuccessful();

    $this->assertDatabaseHas('users', [
        'name' => 'Phone Owner',
        'phone' => '0919876543',
        'email' => null,
    ]);
});
