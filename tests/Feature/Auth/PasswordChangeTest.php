<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('user created by admin has must_change_password set to true', function () {
    $admin = User::factory()->create();
    $role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $admin->id, 'role_id' => $role->id, 'operating_unit_id' => null]);
    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/users', [
        'name' => 'John Employee',
        'email' => 'john@example.com',
        'password' => 'temporaryPassword123',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.must_change_password', true);

    $user = User::where('email', 'john@example.com')->first();
    expect($user->must_change_password)->toBeTrue();
});

test('user with must_change_password true is blocked from operational routes', function () {
    $user = User::factory()->create([
        'must_change_password' => true,
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/operating-units');

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'Password change required before accessing the application.',
            'code' => 'MUST_CHANGE_PASSWORD',
        ]);
});

test('user can successfully change password and unblock account', function () {
    $user = User::factory()->create([
        'password' => bcrypt('oldPassword123'),
        'must_change_password' => true,
    ]);
    $role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    Sanctum::actingAs($user);

    $changePasswordResponse = $this->postJson('/api/v1/auth/change-password', [
        'current_password' => 'oldPassword123',
        'password' => 'newSecurePassword123',
        'password_confirmation' => 'newSecurePassword123',
    ]);

    $changePasswordResponse->assertStatus(200)
        ->assertJsonPath('user.must_change_password', false);

    expect($user->fresh()->must_change_password)->toBeFalse();

    $response = $this->getJson('/api/v1/operating-units');
    $response->assertStatus(200);
});
