<?php

declare(strict_types=1);

use App\Enums\ClientStatus;
use App\Models\Account;
use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\EntityContact;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\UnitBlueprint;
use App\Services\AccountingService;
use App\Services\ReceivablesLedgerService;
use App\Support\CurrentUnitContext;
use App\Support\DebtorStatementFilters;
use App\Support\ReceivableAccounts;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-05 12:00');

    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Furniture Unit', 'code' => 'FURN-01', 'unit_type' => 'manufactory',
    ]);
    $this->unitB = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Foam Unit', 'code' => 'FOAM-01', 'unit_type' => 'manufactory',
    ]);
    $this->client = ledgerClient('شركة البناء الراقي', $this->unit->id);

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

    $this->service = app(ReceivablesLedgerService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function ledgerClient(string $name, string $unitId, array $attributes = []): Client
{
    $entity = Entity::create(['name' => $name, 'entity_type' => 'organization']);

    return Client::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $unitId,
        'credit_limit' => 50000.0,
        'current_balance' => 0.0,
        ...$attributes,
    ]);
}

/**
 * Posts one receivable line against counter-account 41.
 */
function ledgerPost(string $date, ?string $clientId, float $debit = 0, float $credit = 0, string $code = '122', ?string $unitId = null, string $description = 'Entry'): void
{
    app(AccountingService::class)->postJournal($description, [
        ['account_code' => $code, 'debit' => $debit, 'credit' => $credit, 'client_id' => $clientId, 'operating_unit_id' => $unitId],
        ['account_code' => '41', 'debit' => $credit, 'credit' => $debit, 'operating_unit_id' => $unitId],
    ], entryDate: $date);
}

function septemberFilters(array $overrides = []): DebtorStatementFilters
{
    return new DebtorStatementFilters(...['from' => '2026-09-01', 'to' => '2026-09-30', ...$overrides]);
}

test('builds opening, ordered lines, running and closing', function () {
    ledgerPost('2026-08-01', $this->client->id, debit: 4000);
    ledgerPost('2026-09-10', $this->client->id, debit: 2500, description: 'Sale');
    ledgerPost('2026-09-20', $this->client->id, credit: 1500, description: 'Payment');

    $report = $this->service->debtorStatements(septemberFilters());

    expect($report['period'])->toBe(['from' => '2026-09-01', 'to' => '2026-09-30'])
        ->and($report['generated_at'])->toBe(now()->toIso8601String())
        ->and($report['debtors'])->toHaveCount(1)
        ->and($report['unassigned'])->toBeNull();

    $debtor = $report['debtors'][0];

    expect($debtor['client']['id'])->toBe($this->client->id)
        ->and($debtor['client']['name'])->toBe('شركة البناء الراقي')
        ->and($debtor['opening_balance'])->toBe(4000.0)
        ->and($debtor['lines'])->toHaveCount(2)
        ->and(array_column($debtor['lines'], 'running_balance'))->toBe([6500.0, 5000.0])
        ->and($debtor['lines'][0]['date'])->toBe('2026-09-10')
        ->and($debtor['lines'][0]['description'])->toBe('Sale')
        ->and($debtor['lines'][0]['debit'])->toBe(2500.0)
        ->and($debtor['lines'][1]['credit'])->toBe(1500.0)
        ->and($debtor['total_debit'])->toBe(2500.0)
        ->and($debtor['total_credit'])->toBe(1500.0)
        ->and($debtor['closing_balance'])->toBe(5000.0)
        ->and(array_keys($debtor))->toBe([
            'client', 'opening_balance', 'lines', 'total_debit', 'total_credit', 'closing_balance', 'aging', 'overdue_amount',
        ])
        ->and(array_keys($debtor['lines'][0]))->toBe([
            'date', 'reference', 'description', 'source_type', 'source_id', 'journal_entry_id', 'debit', 'credit', 'running_balance',
        ])
        ->and(array_keys($debtor['client']))->toBe([
            'id', 'name', 'phone', 'city', 'status', 'credit_limit', 'payment_terms_days',
        ]);

    expect($report['totals'])->toMatchArray([
        'opening' => 4000.0, 'debit' => 2500.0, 'credit' => 1500.0, 'closing' => 5000.0, 'debtors_count' => 1,
    ]);
});

test('aging is FIFO against to', function () {
    ledgerPost('2026-06-27', $this->client->id, debit: 10000); // 95 days before to
    ledgerPost('2026-08-16', $this->client->id, debit: 5000);  // 45 days
    ledgerPost('2026-09-20', $this->client->id, debit: 3000);  // 10 days
    ledgerPost('2026-09-25', $this->client->id, credit: 12000);

    $debtor = $this->service->debtorStatements(septemberFilters())['debtors'][0];

    expect($debtor['aging'])->toBe(['current' => 3000.0, 'days_31_60' => 3000.0, 'days_61_90' => 0.0, 'over_90' => 0.0])
        ->and($debtor['overdue_amount'])->toBe(3000.0)
        ->and($debtor['closing_balance'])->toBe(6000.0);
});

test('overdue honours client payment terms', function () {
    $this->client->update(['payment_terms_days' => 60]);
    ledgerPost('2026-08-16', $this->client->id, debit: 5000); // 45 days: inside terms
    ledgerPost('2026-06-27', $this->client->id, debit: 1000); // 95 days: past terms

    $report = $this->service->debtorStatements(septemberFilters());

    expect($report['debtors'][0]['overdue_amount'])->toBe(1000.0)
        ->and($report['totals']['overdue'])->toBe(1000.0)
        ->and($report['totals']['aging'])->toBe(['current' => 0.0, 'days_31_60' => 5000.0, 'days_61_90' => 0.0, 'over_90' => 1000.0]);
});

test('includes fully paid in period, excludes idle zero', function () {
    $clientB = ledgerClient('عميل ب', $this->unit->id);
    $clientC = ledgerClient('عميل ج', $this->unit->id);
    ledgerPost('2026-09-05', $clientB->id, debit: 1000);
    ledgerPost('2026-09-06', $clientB->id, credit: 1000);
    ledgerPost('2026-07-01', $clientC->id, debit: 700);
    ledgerPost('2026-07-02', $clientC->id, credit: 700);

    $debtors = $this->service->debtorStatements(septemberFilters())['debtors'];
    $ids = array_column(array_column($debtors, 'client'), 'id');

    expect($ids)->toBe([$clientB->id])
        ->and($debtors[0]['closing_balance'])->toBe(0.0)
        ->and($debtors[0]['lines'])->toHaveCount(2);
});

test('idle client with an open balance is included without lines', function () {
    ledgerPost('2026-07-05', $this->client->id, debit: 900); // 87 days before to

    $debtor = $this->service->debtorStatements(septemberFilters())['debtors'][0];

    expect($debtor['lines'])->toBe([])
        ->and($debtor['opening_balance'])->toBe(900.0)
        ->and($debtor['closing_balance'])->toBe(900.0)
        ->and($debtor['aging']['over_90'])->toBe(0.0)
        ->and($debtor['aging']['days_61_90'])->toBe(900.0);
});

test('merges bare 122 history with sub-account lines into one section', function () {
    ledgerPost('2026-08-20', $this->client->id, debit: 1200);
    $this->client->update(['account_id' => $this->child->id]);
    ledgerPost('2026-09-12', $this->client->id, debit: 800, code: '122001');
    ledgerPost('2026-09-14', $this->client->id, credit: 500, code: '122001');

    $report = $this->service->debtorStatements(septemberFilters(['from' => '2026-08-01']));

    expect($report['debtors'])->toHaveCount(1)
        ->and($report['debtors'][0]['lines'])->toHaveCount(3)
        ->and($report['debtors'][0]['closing_balance'])->toBe(1500.0);
});

test('unassigned section reconciles with the subtree', function () {
    ledgerPost('2026-08-01', $this->client->id, debit: 3000);
    ledgerPost('2026-09-03', null, debit: 450);
    ledgerPost('2026-08-15', null, debit: 200, code: '122001');
    ledgerPost('2026-09-18', $this->client->id, credit: 1000, code: '122');

    $without = $this->service->debtorStatements(septemberFilters());
    $report = $this->service->debtorStatements(septemberFilters(['includeUnassigned' => true]));

    expect($without['unassigned'])->toBeNull()
        ->and($report['unassigned'])->not->toBeNull()
        ->and(array_key_exists('client', $report['unassigned']))->toBeFalse()
        ->and($report['unassigned']['opening_balance'])->toBe(200.0)
        ->and($report['unassigned']['lines'])->toHaveCount(1)
        ->and($report['unassigned']['closing_balance'])->toBe(650.0)
        ->and($report['totals']['closing'])->toBe(2000.0);

    $subtreeNet = (float) JournalLine::query()
        ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
        ->whereIn('journal_lines.account_id', ReceivableAccounts::subtreeIds())
        ->whereDate('journal_entries.entry_date', '<=', '2026-09-30')
        ->selectRaw('SUM(journal_lines.debit) - SUM(journal_lines.credit) as net')
        ->value('net');

    expect(round($report['totals']['closing'] + $report['unassigned']['closing_balance'], 4))->toBe(round($subtreeNet, 4));
});

test('same-day lines follow posting order', function () {
    ledgerPost('2026-09-15', $this->client->id, debit: 1000, description: 'Sale');
    ledgerPost('2026-09-15', $this->client->id, credit: 1000, description: 'Payment');
    ledgerPost('2026-09-15', $this->client->id, debit: 400, description: 'Sale 2');

    $lines = $this->service->debtorStatements(septemberFilters())['debtors'][0]['lines'];

    expect(array_column($lines, 'description'))->toBe(['Sale', 'Payment', 'Sale 2'])
        ->and(array_column($lines, 'running_balance'))->toBe([1000.0, 0.0, 400.0]);
});

test('overpaid idle client is excluded, overpaid with activity is included', function () {
    $idle = ledgerClient('دائن خامل', $this->unit->id);
    ledgerPost('2026-07-01', $idle->id, debit: 100);
    ledgerPost('2026-07-02', $idle->id, credit: 300);

    ledgerPost('2026-09-02', $this->client->id, debit: 100);
    ledgerPost('2026-09-03', $this->client->id, credit: 250);

    $debtors = $this->service->debtorStatements(septemberFilters())['debtors'];

    expect($debtors)->toHaveCount(1)
        ->and($debtors[0]['client']['id'])->toBe($this->client->id)
        ->and($debtors[0]['closing_balance'])->toBe(-150.0)
        ->and($debtors[0]['aging'])->toBe(['current' => 0.0, 'days_31_60' => 0.0, 'days_61_90' => 0.0, 'over_90' => 0.0])
        ->and($debtors[0]['overdue_amount'])->toBe(0.0);
});

test('debtors sort by closing balance then name', function () {
    $alpha = ledgerClient('أ عميل', $this->unit->id);
    $beta = ledgerClient('ب عميل', $this->unit->id);
    ledgerPost('2026-09-02', $this->client->id, debit: 100);
    ledgerPost('2026-09-02', $beta->id, debit: 500);
    ledgerPost('2026-09-02', $alpha->id, debit: 500);

    $names = array_column(array_column($this->service->debtorStatements(septemberFilters())['debtors'], 'client'), 'name');

    expect($names)->toBe(['أ عميل', 'ب عميل', 'شركة البناء الراقي']);
});

test('filters by client ids, status, city and min balance', function () {
    $suspended = ledgerClient('عميل موقوف', $this->unit->id, ['status' => ClientStatus::Suspended]);
    $small = ledgerClient('عميل صغير', $this->unit->id);
    EntityContact::create(['entity_id' => $this->client->entity_id, 'phone' => '0911', 'city' => 'بنغازي', 'is_primary' => false]);
    EntityContact::create(['entity_id' => $this->client->entity_id, 'phone' => '0922', 'city' => 'مصراتة', 'is_primary' => true]);
    EntityContact::create(['entity_id' => $suspended->entity_id, 'phone' => '0933', 'city' => 'طرابلس', 'is_primary' => true]);

    ledgerPost('2026-09-02', $this->client->id, debit: 5000);
    ledgerPost('2026-09-02', $suspended->id, debit: 3000);
    ledgerPost('2026-09-02', $small->id, debit: 50);

    $idsFor = fn (array $overrides): array => array_column(array_column(
        $this->service->debtorStatements(septemberFilters($overrides))['debtors'], 'client'), 'id');

    expect($idsFor(['clientIds' => [$small->id]]))->toBe([$small->id])
        ->and($idsFor(['statuses' => ['suspended']]))->toBe([$suspended->id])
        ->and($idsFor(['city' => 'بنغازي']))->toBe([$this->client->id])
        ->and($idsFor(['minBalance' => 1000.0]))->toBe([$this->client->id, $suspended->id]);

    $client = $this->service->debtorStatements(septemberFilters(['clientIds' => [$this->client->id]]))['debtors'][0]['client'];

    expect($client['phone'])->toBe('0922')
        ->and($client['city'])->toBe('مصراتة')
        ->and($client['status'])->toBe('active')
        ->and($client['credit_limit'])->toBe(50000.0)
        ->and($client['payment_terms_days'])->toBe(30);
});

test('unit filter excludes lines from other units', function () {
    ledgerPost('2026-09-02', $this->client->id, debit: 1000, unitId: $this->unit->id);
    ledgerPost('2026-09-03', $this->client->id, debit: 700, unitId: $this->unitB->id);

    $debtor = $this->service->debtorStatements(septemberFilters(['operatingUnitId' => $this->unit->id]))['debtors'][0];

    expect($debtor['lines'])->toHaveCount(1)
        ->and($debtor['closing_balance'])->toBe(1000.0);
});

test('sees clients outside the current unit context', function () {
    $other = ledgerClient('عميل وحدة أخرى', $this->unitB->id);
    ledgerPost('2026-09-02', $other->id, debit: 1000);
    app(CurrentUnitContext::class)->setUnit($this->unit);

    $debtors = $this->service->debtorStatements(septemberFilters())['debtors'];

    expect(array_column(array_column($debtors, 'client'), 'id'))->toBe([$other->id]);
});

test('query count does not grow with clients', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->debtorStatements(septemberFilters(['includeUnassigned' => true]));
        $this->service->balances('2026-09-30', null);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    ledgerPost('2026-09-02', $this->client->id, debit: 100);
    EntityContact::create(['entity_id' => $this->client->entity_id, 'phone' => '1', 'city' => 'x', 'is_primary' => true]);
    $baseline = $count();

    foreach (range(1, 4) as $i) {
        $client = ledgerClient("عميل {$i}", $this->unit->id);
        EntityContact::create(['entity_id' => $client->entity_id, 'phone' => "{$i}", 'city' => 'x', 'is_primary' => true]);
        ledgerPost('2026-09-02', $client->id, debit: 100 * $i);
        ledgerPost('2026-08-02', $client->id, credit: 10);
    }

    expect($count())->toBe($baseline);
});

test('balances returns all-time totals and aging per client as of a date', function () {
    $other = ledgerClient('عميل آخر', $this->unit->id);
    ledgerPost('2026-06-27', $this->client->id, debit: 10000);
    ledgerPost('2026-09-20', $this->client->id, debit: 3000);
    ledgerPost('2026-09-25', $this->client->id, credit: 4000);
    ledgerPost('2026-10-02', $this->client->id, debit: 999); // after asOf
    ledgerPost('2026-09-01', $other->id, debit: 50, unitId: $this->unitB->id);
    ledgerPost('2026-09-01', null, debit: 777); // untagged

    $balances = $this->service->balances('2026-09-30', null);

    expect(array_keys($balances))->toEqualCanonicalizing([$this->client->id, $other->id])
        ->and($balances[$this->client->id])->toBe([
            'total_debit' => 13000.0,
            'total_credit' => 4000.0,
            'net_balance' => 9000.0,
            'aging' => ['current' => 3000.0, 'days_31_60' => 0.0, 'days_61_90' => 0.0, 'over_90' => 6000.0],
        ]);

    expect(array_keys($this->service->balances('2026-09-30', $this->unit->id)))->toBe([])
        ->and(array_keys($this->service->balances('2026-09-30', null, [$other->id])))->toBe([$other->id]);
});
