<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AppVersion;
use App\Models\JournalLine;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

final class PostChartOfAccounts extends Command
{
    protected $signature = 'coa:post
        {--ip= : Server IP address or host (e.g. 192.168.1.50 or 192.168.1.50:8000)}
        {--port=8000 : Server port (defaults to 8000)}
        {--url= : Target backend base URL (overrides --ip)}
        {--login= : Login identifier (email or phone number) for target backend}
        {--email=owner@erp.com : Login email for target backend}
        {--phone= : Login phone number for target backend}
        {--password=password : Login password for target backend}
        {--token= : Pre-existing Bearer token (optional)}
        {--input=database/data/chart_of_accounts.json : Input JSON file path}
        {--app-version= : Desktop app version to send in X-Desktop-Version header (defaults to latest)}
        {--db : Seed/restore directly into the local database instead of HTTP}
        {--wipe : Wipe all existing accounts before posting (defaults to true)}
        {--no-wipe : Do not wipe existing accounts; update or create instead}
        {--force : Bypass confirmation prompts and transaction safety guards}';

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

        $wipe = ! (bool) $this->option('no-wipe');
        $force = (bool) $this->option('force');

        if ($wipe && $this->input->isInteractive() && ! $force) {
            if (! $this->confirm('Are you sure you want to wipe all existing accounts and restore from JSON?', true)) {
                $this->warn('Aborted by user.');

                return self::SUCCESS;
            }
        }

        if ($this->option('db')) {
            if ($wipe) {
                $this->line('Wiping existing accounts in local database…');
                try {
                    DB::transaction(function () use ($force) {
                        if ($force) {
                            JournalLine::query()->delete();
                        } elseif (JournalLine::query()->exists()) {
                            throw new \RuntimeException('Cannot wipe accounts: journal transactions exist in database. Use --force to override.');
                        }

                        if (Schema::hasTable('operating_unit_accounts')) {
                            DB::table('operating_unit_accounts')->delete();
                        }
                        if (Schema::hasTable('cash_accounts') && Schema::hasColumn('cash_accounts', 'account_id')) {
                            DB::table('cash_accounts')->update(['account_id' => null]);
                        }
                        if (Schema::hasTable('operating_units') && Schema::hasColumn('operating_units', 'revenue_account_id')) {
                            DB::table('operating_units')->update(['revenue_account_id' => null]);
                        }
                        if (Schema::hasTable('suppliers') && Schema::hasColumn('suppliers', 'account_id')) {
                            DB::table('suppliers')->update(['account_id' => null]);
                        }
                        if (Schema::hasTable('fixed_assets') && Schema::hasColumn('fixed_assets', 'account_id')) {
                            DB::table('fixed_assets')->update(['account_id' => null]);
                        }
                        if (Schema::hasTable('purchase_orders') && Schema::hasColumn('purchase_orders', 'payment_source_account_id')) {
                            DB::table('purchase_orders')->update(['payment_source_account_id' => null]);
                        }

                        Account::query()->update(['parent_account_id' => null]);
                        Account::query()->delete();
                    });
                } catch (\Throwable $e) {
                    $this->error("Failed to wipe local accounts: {$e->getMessage()}");

                    return self::FAILURE;
                }
            }

            $this->info("Seeding {$fullInputPath} directly into the local database…");
            $seeder = new ChartOfAccountsSeeder;
            $seeder->setCommand($this);
            $seeder->run($fullInputPath);
            $this->info('Local database seeding completed successfully.');

            return self::SUCCESS;
        }

        $baseUrl = $this->resolveBaseUrl();
        $token = $this->option('token');

        $appVersion = (string) $this->option('app-version');
        if ($appVersion === '') {
            $latestDb = AppVersion::latest()->value('desktop_version');
            $appVersion = $latestDb && version_compare((string) $latestDb, '1.0.40', '>=')
                ? (string) $latestDb
                : '1.0.40';
        }

        $headers = [
            'X-Desktop-Version' => $appVersion,
            'Accept' => 'application/json',
        ];

        $this->info("Connecting to target backend at {$baseUrl} (client version: {$appVersion})…");

        if (empty($token)) {
            $identifier = (string) ($this->option('login') ?: $this->option('phone') ?: $this->option('email'));
            $password = (string) $this->option('password');

            $this->line("Authenticating as {$identifier}…");
            $loginRes = Http::timeout(10)
                ->withHeaders($headers)
                ->post("{$baseUrl}/api/v1/auth/login", [
                    'login' => $identifier,
                    'email' => $identifier,
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

        $codeToId = [];
        $existingByCode = [];

        if ($wipe) {
            $this->line('Wiping existing target accounts…');
            $wipeRes = Http::timeout(20)
                ->withToken((string) $token)
                ->withHeaders($headers)
                ->post("{$baseUrl}/api/v1/accounts/wipe", [
                    'force' => $force,
                ]);

            if ($wipeRes->successful()) {
                $this->info('Target accounts wiped successfully.');
            } else {
                if ($wipeRes->status() === 404) {
                    $this->line('Dedicated wipe endpoint not found on server; falling back to individual account deletion…');
                    $this->wipeTargetAccountsFallback($baseUrl, (string) $token, $headers, $force);
                } else {
                    $this->error("Failed to wipe target accounts: HTTP {$wipeRes->status()} - {$wipeRes->body()}");

                    return self::FAILURE;
                }
            }
        } else {
            $this->line('Fetching existing target accounts…');
            $existingRes = Http::timeout(15)
                ->withToken((string) $token)
                ->withHeaders($headers)
                ->get("{$baseUrl}/api/v1/accounts");

            if ($existingRes->failed()) {
                $this->error("Failed to fetch existing accounts: HTTP {$existingRes->status()} - {$existingRes->body()}");

                return self::FAILURE;
            }

            $existingList = $existingRes->json('data') ?? $existingRes->json() ?? [];
            foreach ($existingList as $acc) {
                if (isset($acc['id'], $acc['account_code'])) {
                    $code = (string) $acc['account_code'];
                    $codeToId[$code] = (string) $acc['id'];
                    $existingByCode[$code] = $acc;
                }
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
            $section = isset($item['section']) ? (string) $item['section'] : null;
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
                    ($existing['currency'] ?? 'LYD') !== $currency ||
                    ($section !== null && ($existing['section'] ?? null) !== $section)
                );

                if ($needsUpdate) {
                    $accountId = $codeToId[$code];
                    $updatePayload = [
                        'name' => $name,
                        'currency' => $currency,
                    ];
                    if ($section !== null) {
                        $updatePayload['section'] = $section;
                    }

                    $updateRes = Http::timeout(10)
                        ->withToken((string) $token)
                        ->withHeaders($headers)
                        ->put("{$baseUrl}/api/v1/accounts/{$accountId}", $updatePayload);

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
            if ($section !== null) {
                $payload['section'] = $section;
            }

            $createRes = Http::timeout(10)
                ->withToken((string) $token)
                ->withHeaders($headers)
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

    protected function resolveBaseUrl(): string
    {
        $url = trim((string) $this->option('url'));
        if ($url !== '') {
            return $this->formatUrlFromHost($url);
        }

        $ip = trim((string) $this->option('ip'));
        $port = trim((string) ($this->option('port') ?: '8000'));

        if ($ip !== '') {
            return $this->formatUrlFromHost($ip, $port);
        }

        if ($this->input->isInteractive()) {
            $input = (string) $this->ask('Server IP address or URL', '192.168.2.200:8000');

            return $this->formatUrlFromHost($input, $port);
        }

        return $this->formatUrlFromHost('192.168.2.200:8000', $port);
    }

    protected function formatUrlFromHost(string $input, string $defaultPort = '8000'): string
    {
        $input = trim($input);
        if ($input === '') {
            $input = '192.168.2.200:8000';
        }

        if (str_starts_with($input, 'http://') || str_starts_with($input, 'https://')) {
            return rtrim($input, '/');
        }

        if (str_contains($input, ':')) {
            return 'http://'.rtrim($input, '/');
        }

        return "http://{$input}:{$defaultPort}";
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function wipeTargetAccountsFallback(string $baseUrl, string $token, array $headers, bool $force): void
    {
        $res = Http::timeout(15)
            ->withToken($token)
            ->withHeaders($headers)
            ->get("{$baseUrl}/api/v1/accounts");

        if ($res->failed()) {
            return;
        }

        $accounts = $res->json('data') ?? $res->json() ?? [];
        if (! is_array($accounts) || empty($accounts)) {
            return;
        }

        $idToParent = [];
        foreach ($accounts as $acc) {
            if (isset($acc['id'])) {
                $idToParent[(string) $acc['id']] = isset($acc['parent_account_id']) ? (string) $acc['parent_account_id'] : null;
            }
        }

        $getDepth = function (string $id, callable $self) use ($idToParent): int {
            $p = $idToParent[$id] ?? null;
            if ($p === null || ! isset($idToParent[$p]) || $p === $id) {
                return 0;
            }

            return 1 + $self($p, $self);
        };

        $depths = [];
        foreach (array_keys($idToParent) as $id) {
            $depths[$id] = $getDepth($id, $getDepth);
        }

        usort($accounts, function (array $a, array $b) use ($depths) {
            $dA = $depths[(string) ($a['id'] ?? '')] ?? 0;
            $dB = $depths[(string) ($b['id'] ?? '')] ?? 0;

            return $dB <=> $dA;
        });

        foreach ($accounts as $acc) {
            if (isset($acc['id'])) {
                $forceParam = $force ? '?force=1' : '';
                Http::timeout(10)
                    ->withToken($token)
                    ->withHeaders($headers)
                    ->delete("{$baseUrl}/api/v1/accounts/{$acc['id']}{$forceParam}");
            }
        }
    }
}
