<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class FetchChartOfAccounts extends Command
{
    protected $signature = 'coa:fetch
        {--url=http://192.168.2.200:8000 : The remote backend base URL}
        {--email=owner@erp.com : Login email}
        {--password=password : Login password}
        {--token= : Pre-existing Bearer token (optional)}
        {--output=database/data/chart_of_accounts.json : Output JSON file path}
        {--raw : Keep exact remote parent references without decoupling accounts 6 and 7}';

    protected $description = 'Fetch Chart of Accounts data from remote backend and save to a JSON file';

    public function handle(): int
    {
        $baseUrl = rtrim((string) $this->option('url'), '/');
        $outputFile = (string) $this->option('output');
        $token = $this->option('token');
        $raw = (bool) $this->option('raw');

        // Resolve relative output path against base_path
        $fullOutputPath = str_starts_with($outputFile, '/')
            ? $outputFile
            : base_path($outputFile);

        $this->info("Connecting to remote backend at {$baseUrl}…");

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

        $this->line('Fetching accounts…');
        $accountsRes = Http::timeout(15)
            ->withToken((string) $token)
            ->withHeaders([
                'X-Desktop-Version' => '1.0.30',
                'Accept' => 'application/json',
            ])
            ->get("{$baseUrl}/api/v1/accounts");

        if ($accountsRes->failed()) {
            $this->error("Failed to fetch accounts: HTTP {$accountsRes->status()} - {$accountsRes->body()}");

            return self::FAILURE;
        }

        $items = $accountsRes->json('data') ?? $accountsRes->json();
        if (! is_array($items) || empty($items)) {
            $this->error('No accounts found in remote response.');

            return self::FAILURE;
        }

        $idToCode = [];
        foreach ($items as $acc) {
            if (isset($acc['id'], $acc['account_code'])) {
                $idToCode[$acc['id']] = (string) $acc['account_code'];
            }
        }

        $catalog = [];
        foreach ($items as $acc) {
            $code = (string) $acc['account_code'];
            $parentId = $acc['parent_account_id'] ?? null;
            $parentCode = ($parentId !== null && isset($idToCode[$parentId]))
                ? $idToCode[$parentId]
                : null;

            // Decouple accounts 6 and 7 from parent 5 unless --raw is explicitly requested
            if (! $raw && in_array($code, ['6', '7'], true)) {
                $parentCode = null;
            }

            $catalog[$code] = [
                'account_code' => $code,
                'name' => (string) $acc['name'],
                'type' => (string) $acc['type'],
                'currency' => (string) ($acc['currency'] ?? 'LYD'),
                'parent_code' => $parentCode,
            ];
        }

        // Topologically sort accounts: roots first, followed by descendants by depth
        $getDepth = function (string|int $code, callable $self) use ($catalog): int {
            $codeStr = (string) $code;
            $parentCode = $catalog[$codeStr]['parent_code'] ?? null;
            if ($parentCode === null || ! isset($catalog[$parentCode]) || $parentCode === $codeStr) {
                return 0;
            }

            return 1 + $self($parentCode, $self);
        };

        $depths = [];
        foreach (array_keys($catalog) as $code) {
            $codeStr = (string) $code;
            $depths[$codeStr] = $getDepth($codeStr, $getDepth);
        }

        uasort($catalog, function (array $a, array $b) use ($depths) {
            $depthA = $depths[$a['account_code']] ?? 0;
            $depthB = $depths[$b['account_code']] ?? 0;

            if ($depthA !== $depthB) {
                return $depthA <=> $depthB;
            }

            return strnatcmp($a['account_code'], $b['account_code']);
        });

        $dir = dirname($fullOutputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $jsonContent = json_encode(array_values($catalog), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($fullOutputPath, $jsonContent);

        $count = count($catalog);
        $this->info("Successfully fetched {$count} accounts and saved to {$outputFile}.");

        return self::SUCCESS;
    }
}
