<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('writes audit logs for model creations, updates, and soft deletes', function (): void {
    // 1. Create a user (should trigger 'create' audit log)
    $user = User::create([
        'name' => 'Audit Test User',
        'email' => 'audit@example.com',
        'password' => Hash::make('password'),
    ]);

    $createLog = AuditLog::where('table_name', 'users')
        ->where('record_id', $user->id)
        ->where('action', 'create')
        ->first();

    expect($createLog)->not->toBeNull();
    expect($createLog->new_values['email'])->toBe('audit@example.com');

    // 2. Update the user (should trigger 'update' audit log with changed fields)
    $user->update([
        'name' => 'Audit Test User Updated',
        'record_version' => 1,
    ]);

    $updateLog = AuditLog::where('table_name', 'users')
        ->where('record_id', $user->id)
        ->where('action', 'update')
        ->first();

    expect($updateLog)->not->toBeNull();
    expect($updateLog->old_values['name'])->toBe('Audit Test User');
    expect($updateLog->new_values['name'])->toBe('Audit Test User Updated');

    // 3. Delete the user (should trigger 'delete' audit log)
    $user->delete();

    $deleteLog = AuditLog::where('table_name', 'users')
        ->where('record_id', $user->id)
        ->where('action', 'delete')
        ->first();

    expect($deleteLog)->not->toBeNull();
    expect($deleteLog->old_values['email'])->toBe('audit@example.com');
});
