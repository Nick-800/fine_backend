<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::firstOrCreate(
        ['name' => 'Fine'],
        ['default_currency' => 'LYD'],
    );
});

test('coa:fetch authenticates and saves topologically sorted accounts with parent codes', function () {
    $testOutputPath = base_path('database/data/test_coa_fetch.json');
    if (File::exists($testOutputPath)) {
        File::delete($testOutputPath);
    }

    Http::fake([
        'http://mock-remote:8000/api/v1/auth/login' => Http::response([
            'access_token' => 'mock-token-xyz',
        ], 200),
        'http://mock-remote:8000/api/v1/accounts' => Http::response([
            'data' => [
                [
                    'id' => 'uuid-child-111',
                    'account_code' => '111',
                    'name' => 'الخزائن',
                    'type' => 'asset',
                    'currency' => 'LYD',
                    'parent_account_id' => 'uuid-root-1',
                ],
                [
                    'id' => 'uuid-root-1',
                    'account_code' => '1',
                    'name' => 'الأصول',
                    'type' => 'asset',
                    'currency' => 'LYD',
                    'parent_account_id' => null,
                ],
                [
                    'id' => 'uuid-account-6',
                    'account_code' => '6',
                    'name' => 'المصاريف التشغيلية والإدارية',
                    'type' => 'expense',
                    'currency' => 'LYD',
                    'parent_account_id' => 'uuid-account-5',
                ],
                [
                    'id' => 'uuid-account-5',
                    'account_code' => '5',
                    'name' => 'المصروفات والتكاليف',
                    'type' => 'expense',
                    'currency' => 'LYD',
                    'parent_account_id' => null,
                ],
            ],
        ], 200),
    ]);

    $this->artisan('coa:fetch', [
        '--url' => 'http://mock-remote:8000',
        '--email' => 'test@erp.com',
        '--password' => 'secret',
        '--output' => 'database/data/test_coa_fetch.json',
    ])->assertSuccessful();

    expect(File::exists($testOutputPath))->toBeTrue();

    $content = json_decode(File::get($testOutputPath), true);
    expect($content)->toHaveCount(4);

    // Root accounts appear before child accounts
    expect($content[0]['account_code'])->toBe('1')
        ->and($content[0]['parent_code'])->toBeNull();

    $acc111 = collect($content)->firstWhere('account_code', '111');
    expect($acc111['parent_code'])->toBe('1');

    // Account 6 has parent decoupled to null by default
    $acc6 = collect($content)->firstWhere('account_code', '6');
    expect($acc6['parent_code'])->toBeNull();

    File::delete($testOutputPath);
});

test('coa:fetch preserves raw parent references when --raw flag is provided', function () {
    $testOutputPath = base_path('database/data/test_coa_raw.json');
    if (File::exists($testOutputPath)) {
        File::delete($testOutputPath);
    }

    Http::fake([
        'http://mock-remote:8000/api/v1/auth/login' => Http::response([
            'access_token' => 'mock-token-xyz',
        ], 200),
        'http://mock-remote:8000/api/v1/accounts' => Http::response([
            'data' => [
                [
                    'id' => 'uuid-account-6',
                    'account_code' => '6',
                    'name' => 'المصاريف التشغيلية والإدارية',
                    'type' => 'expense',
                    'currency' => 'LYD',
                    'parent_account_id' => 'uuid-account-5',
                ],
                [
                    'id' => 'uuid-account-5',
                    'account_code' => '5',
                    'name' => 'المصروفات والتكاليف',
                    'type' => 'expense',
                    'currency' => 'LYD',
                    'parent_account_id' => null,
                ],
            ],
        ], 200),
    ]);

    $this->artisan('coa:fetch', [
        '--url' => 'http://mock-remote:8000',
        '--output' => 'database/data/test_coa_raw.json',
        '--token' => 'mock-token-xyz',
        '--raw' => true,
    ])->assertSuccessful();

    $content = json_decode(File::get($testOutputPath), true);
    $acc6 = collect($content)->firstWhere('account_code', '6');
    expect($acc6['parent_code'])->toBe('5');

    File::delete($testOutputPath);
});

test('coa:post creates new accounts and updates modified ones over HTTP', function () {
    $testInputPath = base_path('database/data/test_coa_post.json');
    File::put($testInputPath, json_encode([
        [
            'account_code' => '1',
            'name' => 'الأصول المحدثة',
            'type' => 'asset',
            'currency' => 'LYD',
            'parent_code' => null,
        ],
        [
            'account_code' => '11',
            'name' => 'الأصول المتداولة',
            'type' => 'asset',
            'currency' => 'LYD',
            'parent_code' => '1',
        ],
    ]));

    Http::fake([
        'http://mock-target:8000/api/v1/auth/login' => Http::response([
            'access_token' => 'target-token-123',
        ], 200),
        'http://mock-target:8000/api/v1/accounts' => function ($request) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'data' => [
                        [
                            'id' => 'uuid-existing-1',
                            'account_code' => '1',
                            'name' => 'الأصول القديمة',
                            'type' => 'asset',
                            'currency' => 'LYD',
                            'parent_account_id' => null,
                        ],
                    ],
                ], 200);
            }

            if ($request->method() === 'POST') {
                return Http::response([
                    'data' => [
                        'id' => 'uuid-new-11',
                        'account_code' => $request->data()['account_code'],
                        'name' => $request->data()['name'],
                    ],
                ], 201);
            }

            return Http::response([], 404);
        },
        'http://mock-target:8000/api/v1/accounts/uuid-existing-1' => Http::response([
            'data' => ['id' => 'uuid-existing-1'],
        ], 200),
    ]);

    $this->artisan('coa:post', [
        '--url' => 'http://mock-target:8000',
        '--token' => 'target-token-123',
        '--input' => 'database/data/test_coa_post.json',
    ])->assertSuccessful();

    Http::assertSent(function ($request) {
        return $request->url() === 'http://mock-target:8000/api/v1/accounts/uuid-existing-1'
            && $request->method() === 'PUT'
            && $request['name'] === 'الأصول المحدثة';
    });

    Http::assertSent(function ($request) {
        return $request->url() === 'http://mock-target:8000/api/v1/accounts'
            && $request->method() === 'POST'
            && $request['account_code'] === '11'
            && $request['parent_account_id'] === 'uuid-existing-1';
    });

    File::delete($testInputPath);
});

test('chart of accounts seeder loads catalog from JSON when present', function () {
    $seeder = new ChartOfAccountsSeeder;
    $seeder->run();

    // Accounts seeded from JSON include custom remote accounts like 110432
    $acc110432 = Account::where('account_code', '110432')->first();
    expect($acc110432)->not->toBeNull()
        ->and($acc110432->name)->toBe('سلف الموظفين المقص');

    // Accounts 6 and 7 are root accounts
    $acc6 = Account::where('account_code', '6')->firstOrFail();
    $acc7 = Account::where('account_code', '7')->firstOrFail();
    expect($acc6->parent_account_id)->toBeNull()
        ->and($acc7->parent_account_id)->toBeNull();
});
