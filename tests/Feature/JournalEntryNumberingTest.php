<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\DocumentSequence;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->post = fn (?string $entryDate = null): JournalEntry => app(AccountingService::class)->postJournal(
        'Numbering check',
        [
            ['account_code' => '1131', 'debit' => 10],
            ['account_code' => '1121', 'credit' => 10],
        ],
        entryDate: $entryDate,
    );
});

test('consecutive postings get consecutive references for the entry year', function () {
    $year = now()->format('Y');

    expect(($this->post)()->reference)->toBe("JE-{$year}-00001")
        ->and(($this->post)()->reference)->toBe("JE-{$year}-00002");
});

test('a backdated entry uses its own year sequence', function () {
    $year = now()->format('Y');
    $lastYear = (string) ((int) $year - 1);

    ($this->post)();

    expect(($this->post)("{$lastYear}-06-15")->reference)->toBe("JE-{$lastYear}-00001")
        ->and(($this->post)()->reference)->toBe("JE-{$year}-00002");
});

test('the migration continues each year from the highest existing reference', function () {
    JournalEntry::create([
        'id' => (string) Str::uuid(),
        'company_id' => $this->company->id,
        'reference' => 'JE-2025-00007',
        'entry_date' => '2025-03-01',
        'description' => 'Legacy entry',
    ]);

    $migration = require database_path('migrations/2026_10_17_100000_seed_journal_entry_document_sequences.php');
    $migration->up();

    expect(DocumentSequence::where('document_type', 'JE')->where('year', 2025)->value('last_number'))->toBe(7)
        ->and(($this->post)('2025-12-31')->reference)->toBe('JE-2025-00008');
});
