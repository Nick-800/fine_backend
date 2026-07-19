<?php

declare(strict_types=1);

use App\Exceptions\OptimisticLockConflictException;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('throws optimistic lock exception when saving stale version in database', function (): void {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    expect($user->record_version)->toBe(1);

    // Retrieve two instances (simulate concurrent loading)
    $instanceA = User::find($user->id);
    $instanceB = User::find($user->id);

    // Update A -> succeeds, increments DB version to 2
    $instanceA->name = 'John Doe A';
    $instanceA->save();

    expect($instanceA->record_version)->toBe(2);

    // Update B -> fails because B still holds version 1, but DB is now 2
    $instanceB->name = 'John Doe B';

    expect(fn () => $instanceB->save())->toThrow(OptimisticLockConflictException::class);
});

it('returns HTTP 409 Conflict when updating user via API with stale record_version', function (): void {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $ownerRole = Role::create([
        'name' => 'Owner',
        'slug' => 'owner',
    ]);

    UserRole::create([
        'user_id' => $user->id,
        'role_id' => $ownerRole->id,
        'operating_unit_id' => null, // Company-wide Owner
    ]);

    // Perform successful update via API (sends record_version = 1)
    $response = $this->actingAs($user)
        ->putJson("/api/v1/users/{$user->id}", [
            'name' => 'John Doe Updated',
            'record_version' => 1,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.record_version', 2);

    // Perform concurrent update with stale record_version = 1 -> should fail with 409
    $conflictResponse = $this->actingAs($user)
        ->putJson("/api/v1/users/{$user->id}", [
            'name' => 'John Doe Stale',
            'record_version' => 1,
        ]);

    $conflictResponse->assertStatus(409)
        ->assertJsonPath('message', 'The record has been updated by another user.');
});
