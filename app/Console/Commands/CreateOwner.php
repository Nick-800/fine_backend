<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class CreateOwner extends Command
{
    protected $signature = 'user:create-owner
        {--email=hazemfast@gmail.com : Email address of the owner}
        {--name=حازم : Display name of the owner}
        {--password=password : Account password}';

    protected $description = 'Provision or restore an owner user with full company-wide authority';

    public function handle(): int
    {
        $email = (string) $this->option('email');
        $name = (string) $this->option('name');
        $password = (string) $this->option('password');

        $role = Role::where('slug', 'owner')->first();

        if ($role === null) {
            $this->error('The "owner" role does not exist in the database. Please run migrations/seeders first.');

            return self::FAILURE;
        }

        // Handle new, existing, or soft-deleted users cleanly
        $user = User::withTrashed()->firstOrNew(['email' => $email]);

        if ($user->trashed()) {
            $user->restore();
            $this->info("Restored soft-deleted account: {$email}");
        }

        if (! $user->exists) {
            $user->id = (string) Str::uuid();
        }

        $user->name = $name;
        $user->password = $password;
        $user->is_active = true;
        $user->must_change_password = false;
        $user->save();

        UserRole::firstOrCreate(
            [
                'user_id' => $user->id,
                'role_id' => $role->id,
                'operating_unit_id' => null,
            ],
            ['id' => (string) Str::uuid()]
        );

        $this->info('Owner user provisioned successfully:');
        $this->table(
            ['ID', 'Name', 'Email', 'Role', 'Company-Wide', 'Active'],
            [
                [
                    $user->id,
                    $user->name,
                    $user->email,
                    $role->name.' ('.$role->slug.')',
                    $user->hasCompanyWideRole() ? 'Yes (All Units)' : 'No',
                    $user->is_active ? 'Yes' : 'No',
                ],
            ]
        );

        return self::SUCCESS;
    }
}
