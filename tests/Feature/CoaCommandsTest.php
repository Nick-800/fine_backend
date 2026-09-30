<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ChartOfAccounts;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
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
        '--no-wipe' => true,
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
    $acc11432 = Account::where('account_code', '11432')->first();
    expect($acc11432)->not->toBeNull()
        ->and($acc11432->name)->toBe('سلف الموظفين المقص');

    // Accounts 6 and 7 are root accounts
    $acc6 = Account::where('account_code', '6')->firstOrFail();
    $acc7 = Account::where('account_code', '7')->firstOrFail();
    expect($acc6->parent_account_id)->toBeNull()
        ->and($acc7->parent_account_id)->toBeNull();
});

test('coa:fetch and coa:post send compliant desktop version and support phone login', function () {
    $testOutputPath = base_path('database/data/test_coa_phone.json');
    if (File::exists($testOutputPath)) {
        File::delete($testOutputPath);
    }

    Http::fake([
        'http://mock-remote:8000/api/v1/auth/login' => function ($request) {
            expect($request->header('X-Desktop-Version'))->not->toBeEmpty()
                ->and(version_compare($request->header('X-Desktop-Version')[0], '1.0.31', '>='))->toBeTrue()
                ->and($request['login'])->toBe('0912345678');

            return Http::response(['access_token' => 'token-phone'], 200);
        },
        'http://mock-remote:8000/api/v1/accounts' => Http::response([
            'data' => [
                [
                    'id' => 'uuid-1',
                    'account_code' => '1',
                    'name' => 'Assets',
                    'type' => 'asset',
                    'section' => 'Balance Sheet',
                    'currency' => 'LYD',
                    'parent_account_id' => null,
                ],
            ],
        ], 200),
    ]);

    $this->artisan('coa:fetch', [
        '--url' => 'http://mock-remote:8000',
        '--phone' => '0912345678',
        '--password' => 'secret',
        '--output' => 'database/data/test_coa_phone.json',
    ])->assertSuccessful();

    $content = json_decode(File::get($testOutputPath), true);
    expect($content[0]['section'])->toBe('Balance Sheet');

    File::delete($testOutputPath);
});

test('coa:fetch and coa:post correctly resolve server url from --ip and --port', function () {
    $testOutputPath = base_path('database/data/test_coa_ip.json');
    if (File::exists($testOutputPath)) {
        File::delete($testOutputPath);
    }

    Http::fake([
        'http://192.168.1.55:9000/api/v1/auth/login' => Http::response(['access_token' => 'token-fetch-ip'], 200),
        'http://192.168.1.55:9000/api/v1/accounts' => Http::response([
            'data' => [
                [
                    'id' => 'uuid-ip-1',
                    'account_code' => '1',
                    'name' => 'Assets',
                    'type' => 'asset',
                    'currency' => 'LYD',
                    'parent_account_id' => null,
                ],
            ],
        ], 200),
        'http://192.168.1.66:8000/api/v1/auth/login' => Http::response(['access_token' => 'token-post-ip'], 200),
        'http://192.168.1.66:8000/api/v1/accounts/wipe' => Http::response(['message' => 'All accounts wiped successfully.'], 200),
        'http://192.168.1.66:8000/api/v1/accounts' => Http::response([], 200),
    ]);

    // Test coa:fetch with --ip and custom --port
    $this->artisan('coa:fetch', [
        '--ip' => '192.168.1.55',
        '--port' => '9000',
        '--login' => 'owner@erp.com',
        '--password' => 'secret',
        '--output' => 'database/data/test_coa_ip.json',
    ])->assertSuccessful();

    Http::assertSent(function ($request) {
        return str_starts_with($request->url(), 'http://192.168.1.55:9000');
    });

    // Test coa:post with --ip (defaults to port 8000)
    $this->artisan('coa:post', [
        '--ip' => '192.168.1.66',
        '--token' => 'token-post-ip',
        '--input' => 'database/data/test_coa_ip.json',
        '--force' => true,
    ])->assertSuccessful();

    Http::assertSent(function ($request) {
        return str_starts_with($request->url(), 'http://192.168.1.66:8000');
    });

    File::delete($testOutputPath);
});

test('coa:post wipes existing accounts on target and posts new accounts from JSON', function () {
    $testInputPath = base_path('database/data/test_coa_wipe_post.json');
    File::put($testInputPath, json_encode([
        [
            'account_code' => '1',
            'name' => 'Assets Fresh',
            'type' => 'asset',
            'currency' => 'LYD',
            'parent_code' => null,
        ],
        [
            'account_code' => '11',
            'name' => 'Current Assets Fresh',
            'type' => 'asset',
            'currency' => 'LYD',
            'parent_code' => '1',
        ],
    ]));

    Http::fake([
        'http://mock-wipe-target:8000/api/v1/accounts/wipe' => Http::response([
            'message' => 'All accounts wiped successfully.',
            'deleted_count' => 10,
        ], 200),
        'http://mock-wipe-target:8000/api/v1/accounts' => function ($request) {
            if ($request->method() === 'POST') {
                $code = $request->data()['account_code'];

                return Http::response([
                    'data' => [
                        'id' => "uuid-new-{$code}",
                        'account_code' => $code,
                        'name' => $request->data()['name'],
                    ],
                ], 201);
            }

            return Http::response([], 404);
        },
    ]);

    $this->artisan('coa:post', [
        '--url' => 'http://mock-wipe-target:8000',
        '--token' => 'target-token-wipe',
        '--input' => 'database/data/test_coa_wipe_post.json',
        '--force' => true,
    ])->assertSuccessful();

    Http::assertSent(function ($request) {
        return $request->url() === 'http://mock-wipe-target:8000/api/v1/accounts/wipe'
            && $request->method() === 'POST';
    });

    Http::assertSent(function ($request) {
        return $request->url() === 'http://mock-wipe-target:8000/api/v1/accounts'
            && $request->method() === 'POST'
            && $request['account_code'] === '1'
            && $request['parent_account_id'] === null;
    });

    Http::assertSent(function ($request) {
        return $request->url() === 'http://mock-wipe-target:8000/api/v1/accounts'
            && $request->method() === 'POST'
            && $request['account_code'] === '11'
            && $request['parent_account_id'] === 'uuid-new-1';
    });

    File::delete($testInputPath);
});

test('POST /api/v1/accounts/wipe deletes accounts and unlinks foreign keys', function () {
    $user = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Owner']);
    UserRole::create([
        'user_id' => $user->id,
        'role_id' => $ownerRole->id,
    ]);

    $coa = ChartOfAccounts::firstOrCreate(
        ['company_id' => $this->company->id],
        ['name' => 'Main COA'],
    );

    $acc = Account::create([
        'chart_of_accounts_id' => $coa->id,
        'account_code' => '9999',
        'name' => 'Temporary Account',
        'type' => 'asset',
        'currency' => 'LYD',
    ]);

    expect(Account::where('account_code', '9999')->exists())->toBeTrue();

    $response = $this->actingAs($user)->postJson('/api/v1/accounts/wipe');
    $response->assertOk();

    expect(Account::count())->toBe(0);
});

test('coa:post --db wipes local accounts and seeds from JSON file', function () {
    $testDbInput = base_path('database/data/test_coa_db_post.json');
    File::put($testDbInput, json_encode([
        [
            'account_code' => '1',
            'name' => 'Assets Local Fresh',
            'type' => 'asset',
            'currency' => 'LYD',
            'parent_code' => null,
        ],
        [
            'account_code' => '11',
            'name' => 'Current Assets Local Fresh',
            'type' => 'asset',
            'currency' => 'LYD',
            'parent_code' => '1',
        ],
    ]));

    $coa = ChartOfAccounts::firstOrCreate(
        ['company_id' => $this->company->id],
        ['name' => 'Main COA'],
    );

    Account::create([
        'chart_of_accounts_id' => $coa->id,
        'account_code' => '8888',
        'name' => 'Old Obsolete Account',
        'type' => 'asset',
        'currency' => 'LYD',
    ]);

    expect(Account::where('account_code', '8888')->exists())->toBeTrue();

    $this->artisan('coa:post', [
        '--db' => true,
        '--input' => 'database/data/test_coa_db_post.json',
        '--force' => true,
    ])->assertSuccessful();

    // Obsolete account 8888 was wiped
    expect(Account::where('account_code', '8888')->exists())->toBeFalse();

    // New accounts from JSON exist
    $acc1 = Account::where('account_code', '1')->first();
    $acc11 = Account::where('account_code', '11')->first();
    expect($acc1)->not->toBeNull()
        ->and($acc1->name)->toBe('Assets Local Fresh')
        ->and($acc11)->not->toBeNull()
        ->and($acc11->parent_account_id)->toBe($acc1->id);

    File::delete($testDbInput);
});
