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
        {identifier? : Email address or phone number of the owner}
        {name? : Display name of the owner}
        {--email= : Email address of the owner}
        {--phone= : Phone number of the owner (10 digits starting with 09)}
        {--name= : Display name of the owner}
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
                $name = (string) $this->ask('Owner display name');
                while (trim($name) === '') {
                    $this->error('Display name cannot be empty.');
                    $name = (string) $this->ask('Owner display name');
                }
            } else {
                $this->error('Display name is required in non-interactive mode.');

                return self::FAILURE;
            }
        }

        // 2. Resolve Email and Phone
        $arg = trim((string) $this->argument('identifier'));
        $email = trim((string) $this->option('email'));
        $phone = trim((string) $this->option('phone'));

        if ($arg !== '') {
            if (filter_var($arg, FILTER_VALIDATE_EMAIL)) {
                $email = $email !== '' ? $email : $arg;
            } elseif (preg_match('/^09[0-9]{8}$/', $arg)) {
                $phone = $phone !== '' ? $phone : $arg;
            } else {
                $this->error("Argument [{$arg}] is neither a valid email address nor a 10-digit phone number starting with 09.");

                return self::FAILURE;
            }
        }

        if ($email === '' && $phone === '') {
            if ($this->input->isInteractive()) {
                $inputIdentifier = (string) $this->ask('Owner email address or phone number (09xxxxxxxx)');
                while (! filter_var($inputIdentifier, FILTER_VALIDATE_EMAIL) && ! preg_match('/^09[0-9]{8}$/', $inputIdentifier)) {
                    $this->error('Please enter a valid email or a 10-digit phone number starting with 09.');
                    $inputIdentifier = (string) $this->ask('Owner email address or phone number (09xxxxxxxx)');
                }

                if (filter_var($inputIdentifier, FILTER_VALIDATE_EMAIL)) {
                    $email = $inputIdentifier;
                    $optPhone = (string) $this->ask('Owner phone number (optional, 10 digits starting with 09, press enter to skip)');
                    while ($optPhone !== '' && ! preg_match('/^09[0-9]{8}$/', $optPhone)) {
                        $this->error('Phone number must be exactly 10 digits and start with 09.');
                        $optPhone = (string) $this->ask('Owner phone number (optional, 10 digits starting with 09, press enter to skip)');
                    }
                    $phone = $optPhone;
                } else {
                    $phone = $inputIdentifier;
                    $optEmail = (string) $this->ask('Owner email address (optional, press enter to skip)');
                    while ($optEmail !== '' && ! filter_var($optEmail, FILTER_VALIDATE_EMAIL)) {
                        $this->error('Invalid email address format.');
                        $optEmail = (string) $this->ask('Owner email address (optional, press enter to skip)');
                    }
                    $email = $optEmail;
                }
            } else {
                $this->error('At least one of email address or phone number is required in non-interactive mode.');

                return self::FAILURE;
            }
        } else {
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error("Invalid email address format: {$email}");

                return self::FAILURE;
            }
            if ($phone !== '' && ! preg_match('/^09[0-9]{8}$/', $phone)) {
                $this->error("Invalid phone number format: {$phone}. Must be exactly 10 digits and start with 09.");

                return self::FAILURE;
            }
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
        $query = User::withTrashed();
        if ($email !== '' && $phone !== '') {
            $user = $query->where('email', $email)->orWhere('phone', $phone)->first();
        } elseif ($email !== '') {
            $user = $query->where('email', $email)->first();
        } else {
            $user = $query->where('phone', $phone)->first();
        }

        if (! $user) {
            $user = new User();
            $user->id = (string) Str::uuid();
        }

        if ($user->trashed()) {
            $user->restore();
            $this->info('Restored soft-deleted account: '.($email ?: $phone));
        }

        $user->name = $name;
        $user->email = $email !== '' ? $email : null;
        $user->phone = $phone !== '' ? $phone : null;
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
            ['ID', 'Name', 'Email', 'Phone', 'Role', 'Company-Wide', 'Active'],
            [
                [
                    $user->id,
                    $user->name,
                    $user->email ?? '—',
                    $user->phone ?? '—',
                    $role->name.' ('.$role->slug.')',
                    $user->hasCompanyWideRole() ? 'Yes (All Units)' : 'No',
                    $user->is_active ? 'Yes' : 'No',
                ],
            ]
        );

        return self::SUCCESS;
    }
}
