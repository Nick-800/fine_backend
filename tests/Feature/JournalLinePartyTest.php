<?php

declare(strict_types=1);

use App\Exceptions\SalesRuleException;
use App\Models\Account;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\UnitBlueprint;
use App\Services\AccountingService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Furniture Unit', 'code' => 'FURN-01', 'unit_type' => 'manufactory',
    ]);
    $entity = Entity::create(['name' => 'شركة البناء الراقي', 'entity_type' => 'organization', 'phone' => '0910000001']);
    $this->client = Client::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'credit_limit' => 50000.0,
        'current_balance' => 0.0,
    ]);

    $this->parent = Account::where('account_code', '122')->firstOrFail();
    $this->child = Account::create([
        'chart_of_accounts_id' => $this->parent->chart_of_accounts_id,
        'account_code' => '122001',
        'name' => 'Client sub-account',
        'type' => $this->parent->type,
        'section' => $this->parent->section,
        'currency' => $this->parent->currency,
        'parent_account_id' => $this->parent->id,
    ]);
});

function postSale(string $debitCode, ?string $clientId, string $creditCode = '41', ?string $creditClient = null): JournalEntry
{
    return app(AccountingService::class)->postJournal('Sale', [
        ['account_code' => $debitCode, 'debit' => 100, 'client_id' => $clientId],
        ['account_code' => $creditCode, 'credit' => 100, 'client_id' => $creditClient],
    ]);
}

test('stores client_id on a receivable line', function () {
    postSale('122', $this->client->id);

    $line = JournalLine::where('account_id', $this->parent->id)->firstOrFail();
    expect($line->client_id)->toBe($this->client->id);
});

test('refuses client_id on a non-receivable account', function () {
    try {
        postSale('122', null, '41', $this->client->id);
        $this->fail('Expected SalesRuleException');
    } catch (SalesRuleException $e) {
        expect($e->errorCode)->toBe('PARTY_ON_NON_RECEIVABLE');
    }

    expect(JournalEntry::count())->toBe(0);
});

test('refuses mismatched sub-account', function () {
    $this->client->update(['account_id' => $this->child->id]);

    try {
        postSale('122', $this->client->id);
        $this->fail('Expected SalesRuleException');
    } catch (SalesRuleException $e) {
        expect($e->errorCode)->toBe('PARTY_ACCOUNT_MISMATCH');
    }

    expect(JournalEntry::count())->toBe(0);
});

test('accepts descendant of 122', function () {
    $this->client->update(['account_id' => $this->child->id]);

    postSale('122001', $this->client->id);

    expect(JournalLine::where('account_id', $this->child->id)->value('client_id'))->toBe($this->client->id);
});
