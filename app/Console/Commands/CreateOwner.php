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
        {email? : Email address of the owner}
        {name? : Display name of the owner}
        {--email= : Email address of the owner (option)}
        {--name= : Display name of the owner (option)}
        {--password= : Account password (defaults to "password" if omitted)}';

    protected $description = 'Provision or restore any owner user with full company-wide authority';

    public function handle(): int
    {
        $role = Role::where('slug', 'owner')->first();

        if ($role === null) {
            $this->error('The "owner" role does not exist in the database. Please run migrations/seeders first.');

            return self::FAILURE;
        }

        // 1. Resolve Name
        $name = (string) ($this->argument('name') ?: $this->option('name'));
        if (trim($name) === '') {
            if ($this->input->isInteractive()) {
                $name = (string) $this->ask('Owner display name (e.g. حازم or John Doe)');
                while (trim($name) === '') {
                    $this->error('Display name cannot be empty.');
                    $name = (string) $this->ask('Owner display name');
                }
            } else {
                $this->error('Display name is required in non-interactive mode.');

                return self::FAILURE;
            }
        }

        // 2. Resolve Email
        $email = (string) ($this->argument('email') ?: $this->option('email'));
        if (trim($email) === '') {
            if ($this->input->isInteractive()) {
                $email = (string) $this->ask('Owner email address');
                while (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->error('Invalid email address format.');
                    $email = (string) $this->ask('Owner email address');
                }
            } else {
                $this->error('Email address is required in non-interactive mode.');

                return self::FAILURE;
            }
        } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address format: {$email}");

            return self::FAILURE;
        }

        // 3. Resolve Password
        $password = $this->option('password');
        if ($password === null || $password === '') {
            if ($this->input->isInteractive()) {
                $entered = (string) $this->secret('Owner password (leave blank for default: "password")');
                $password = $entered !== '' ? $entered : 'password';
            } else {
                $password = 'password';
            }
        }

        // 4. Provision or restore user
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

        // 5. Ensure company-wide owner role assignment (operating_unit_id: null)
        UserRole::updateOrCreate(
            [
                'user_id' => $user->id,
                'role_id' => $role->id,
            ],
            [
                'operating_unit_id' => null,
            ]
        );

        $this->newLine();
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
