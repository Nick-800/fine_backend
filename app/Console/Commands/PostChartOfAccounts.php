<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class PostChartOfAccounts extends Command
{
    protected $signature = 'coa:post
        {--url=http://192.168.2.200:8000 : Target backend base URL to post accounts to}
        {--email=owner@erp.com : Login email for target backend}
        {--password=password : Login password for target backend}
        {--token= : Pre-existing Bearer token (optional)}
        {--input=database/data/chart_of_accounts.json : Input JSON file path}
        {--db : Seed/restore directly into the local database instead of HTTP}';

    protected $description = 'Post Chart of Accounts data from a JSON file to a target backend API or local database';

    public function handle(): int
    {
        $inputFile = (string) $this->option('input');
        $fullInputPath = str_starts_with($inputFile, '/')
            ? $inputFile
            : base_path($inputFile);

        if (! file_exists($fullInputPath)) {
            $this->error("Input file not found at {$fullInputPath}. Run 'php artisan coa:fetch' first.");

            return self::FAILURE;
        }

        $rawJson = (string) file_get_contents($fullInputPath);
        $items = json_decode($rawJson, true);
        if (! is_array($items) || empty($items)) {
            $this->error('Input file is empty or invalid JSON.');

            return self::FAILURE;
        }

        if ($this->option('db')) {
            $this->info("Seeding {$fullInputPath} directly into the local database…");
            $this->call(ChartOfAccountsSeeder::class);
            $this->info('Local database seeding completed successfully.');

            return self::SUCCESS;
        }

        $baseUrl = rtrim((string) $this->option('url'), '/');
        $token = $this->option('token');

        $this->info("Connecting to target backend at {$baseUrl}…");

        if (empty($token)) {
            $email = (string) $this->option('email');
            $password = (string) $this->option('password');

            $this->line("Authenticating as {$email}…");
            $loginRes = Http::timeout(10)
                ->withHeaders([
                    'X-Desktop-Version' => '1.0.30',
                    'Accept' => 'application/json',
                ])
                ->post("{$baseUrl}/api/v1/auth/login", [
                    'email' => $email,
                    'password' => $password,
                ]);

            if ($loginRes->failed()) {
                $this->error("Authentication failed: HTTP {$loginRes->status()} - {$loginRes->body()}");

                return self::FAILURE;
            }

            $token = $loginRes->json('access_token');
            if (empty($token)) {
                $this->error('Authentication failed: No access_token returned in login response.');

                return self::FAILURE;
            }
        }

        $this->line('Fetching existing target accounts…');
        $existingRes = Http::timeout(15)
            ->withToken((string) $token)
            ->withHeaders([
                'X-Desktop-Version' => '1.0.30',
                'Accept' => 'application/json',
            ])
            ->get("{$baseUrl}/api/v1/accounts");

        if ($existingRes->failed()) {
            $this->error("Failed to fetch existing accounts: HTTP {$existingRes->status()} - {$existingRes->body()}");

            return self::FAILURE;
        }

        $existingList = $existingRes->json('data') ?? $existingRes->json() ?? [];
        $codeToId = [];
        $existingByCode = [];
        foreach ($existingList as $acc) {
            if (isset($acc['id'], $acc['account_code'])) {
                $code = (string) $acc['account_code'];
                $codeToId[$code] = (string) $acc['id'];
                $existingByCode[$code] = $acc;
            }
        }

        $createdCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;

        $this->line('Posting accounts to target…');

        foreach ($items as $item) {
            $code = (string) ($item['account_code'] ?? $item[0]);
            $name = (string) ($item['name'] ?? $item[1]);
            $type = (string) ($item['type'] ?? $item[2]);
            $currency = (string) ($item['currency'] ?? 'LYD');
            $parentCode = isset($item['parent_code'])
                ? ($item['parent_code'] !== null ? (string) $item['parent_code'] : null)
                : ($item[3] ?? null);

            $parentId = ($parentCode !== null && isset($codeToId[$parentCode]))
                ? $codeToId[$parentCode]
                : null;

            if (isset($codeToId[$code])) {
                $existing = $existingByCode[$code] ?? null;
                $needsUpdate = $existing && (
                    $existing['name'] !== $name ||
                    ($existing['currency'] ?? 'LYD') !== $currency
                );

                if ($needsUpdate) {
                    $accountId = $codeToId[$code];
                    $updateRes = Http::timeout(10)
                        ->withToken((string) $token)
                        ->withHeaders([
                            'X-Desktop-Version' => '1.0.30',
                            'Accept' => 'application/json',
                        ])
                        ->put("{$baseUrl}/api/v1/accounts/{$accountId}", [
                            'name' => $name,
                            'currency' => $currency,
                        ]);

                    if ($updateRes->successful()) {
                        $updatedCount++;
                    } else {
                        $this->warn("Failed to update account {$code}: {$updateRes->body()}");
                    }
                } else {
                    $skippedCount++;
                }

                continue;
            }

            // Create new account on target
            $payload = [
                'account_code' => $code,
                'name' => $name,
                'type' => $type,
                'currency' => $currency,
                'parent_account_id' => $parentId,
            ];

            $createRes = Http::timeout(10)
                ->withToken((string) $token)
                ->withHeaders([
                    'X-Desktop-Version' => '1.0.30',
                    'Accept' => 'application/json',
                ])
                ->post("{$baseUrl}/api/v1/accounts", $payload);

            if ($createRes->successful()) {
                $newId = $createRes->json('data.id') ?? $createRes->json('id');
                if ($newId) {
                    $codeToId[$code] = (string) $newId;
                }
                $createdCount++;
            } else {
                $this->warn("Failed to create account {$code} ({$name}): HTTP {$createRes->status()} - {$createRes->body()}");
            }
        }

        $this->info("Completed posting accounts. Created: {$createdCount}, Updated: {$updatedCount}, Unchanged: {$skippedCount}.");

        return self::SUCCESS;
    }
}
