<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Services\AccountingService;
use Illuminate\Database\Seeder;

/**
 * Posts opening balances across the chart of accounts so the seeded
 * environment has working money to run orders against.
 *
 * The chart is metadata-only — actual balances live in journal lines, posted
 * through AccountingService so ACC-01 keeps the trial balance honest.
 *
 * What's seeded (all GL only — no stock):
 *   • 1300 Accounts Receivable          — credit customers (sales orders on account)
 *   • 1131 Finished Goods — Foam Blocks — production output fills this naturally
 *   • 1132 Finished Goods — Cut Pieces  — sales orders can draw against this
 *   • 1133 Finished Goods — Byproduct   — sales orders can draw against this
 *   • 1134 Finished Goods — Furniture   — sales orders can draw against this
 *   • 1400 Fixed Assets                 — depreciation entries need a cost basis
 *   • 1450 Accumulated Depreciation     — credit balance contra-asset
 *   • 2100 Accounts Payable             — credit suppliers (purchase on account)
 *   • 2200 Wages Payable                — payroll module can settle against this
 *
 * Everything offsets against 3100 Retained Earnings, matching the existing
 * treasury + inventory openings in SystemBootstrapSeeder.
 *
 * Stock / inventory items are NOT seeded here. `db:seed:dummy` →
 * DummyDataSeeder → InventoryAndBomSeeder is responsible for creating real FG
 * items + lots when sales orders need to fulfil.
 *
 * Idempotent: every journal is gated behind a unique description keyed in
 * JournalEntry, so re-running on an already-seeded database is a no-op.
 */
class OpeningBalanceSeeder extends Seeder
{
    public function run(): void
    {
        $units = $this->resolveOperatingUnits();

        if ($units === null) {
            return;
        }

        $procurement = $units['procurement'];

        $entries = [
            [
                'description' => 'Opening balance — AR (bootstrap)',
                'lines' => [
                    ['account_code' => '1300', 'debit' => 450_000, 'operating_unit_id' => $procurement->id, 'memo' => 'customer balances carried into the system'],
                    ['account_code' => '3100', 'credit' => 450_000, 'operating_unit_id' => $procurement->id],
                ],
            ],
            [
                'description' => 'Opening balance — FG foam blocks (bootstrap)',
                'lines' => [
                    ['account_code' => '1131', 'debit' => 380_000, 'operating_unit_id' => $units['foam']->id, 'memo' => 'carried from prior production; production flow fills stock'],
                    ['account_code' => '3100', 'credit' => 380_000, 'operating_unit_id' => $units['foam']->id],
                ],
            ],
            [
                'description' => 'Opening balance — FG cut pieces (bootstrap)',
                'lines' => [
                    ['account_code' => '1132', 'debit' => 180_000, 'operating_unit_id' => $units['cutter']->id, 'memo' => 'GL only; db:seed:dummy → InventoryAndBomSeeder adds real lots'],
                    ['account_code' => '3100', 'credit' => 180_000, 'operating_unit_id' => $units['cutter']->id],
                ],
            ],
            [
                'description' => 'Opening balance — FG byproduct fill (bootstrap)',
                'lines' => [
                    ['account_code' => '1133', 'debit' => 60_000, 'operating_unit_id' => $units['foam']->id, 'memo' => 'GL only; db:seed:dummy → InventoryAndBomSeeder adds real lots'],
                    ['account_code' => '3100', 'credit' => 60_000, 'operating_unit_id' => $units['foam']->id],
                ],
            ],
            [
                'description' => 'Opening balance — FG furniture (bootstrap)',
                'lines' => [
                    ['account_code' => '1134', 'debit' => 240_000, 'operating_unit_id' => $units['showroom']->id, 'memo' => 'GL only; db:seed:dummy → InventoryAndBomSeeder adds real lots'],
                    ['account_code' => '3100', 'credit' => 240_000, 'operating_unit_id' => $units['showroom']->id],
                ],
            ],
            [
                'description' => 'Opening balance — Fixed Assets (bootstrap)',
                'lines' => [
                    ['account_code' => '1400', 'debit' => 1_250_000, 'operating_unit_id' => $procurement->id, 'memo' => 'plant equipment carried into the system'],
                    ['account_code' => '1450', 'credit' => 180_000, 'operating_unit_id' => $procurement->id, 'memo' => 'depreciation accrued before system cutover'],
                    ['account_code' => '3100', 'credit' => 1_070_000, 'operating_unit_id' => $procurement->id],
                ],
            ],
            [
                'description' => 'Opening balance — AP (bootstrap)',
                'lines' => [
                    ['account_code' => '2100', 'credit' => 520_000, 'operating_unit_id' => $procurement->id, 'memo' => 'supplier balances carried into the system'],
                    ['account_code' => '3100', 'debit' => 520_000, 'operating_unit_id' => $procurement->id],
                ],
            ],
            [
                'description' => 'Opening balance — Wages Payable (bootstrap)',
                'lines' => [
                    ['account_code' => '2200', 'credit' => 95_000, 'operating_unit_id' => $procurement->id, 'memo' => 'accrued wages owed at cutover'],
                    ['account_code' => '3100', 'debit' => 95_000, 'operating_unit_id' => $procurement->id],
                ],
            ],
        ];

        $accounting = app(AccountingService::class);

        foreach ($entries as $entry) {
            $this->postIfMissing($accounting, $entry['description'], $entry['lines']);
        }
    }

    /**
     * Build or fetch the operating units the journal lines point at.
     *
     * @return array{procurement: OperatingUnit, foam: OperatingUnit, cutter: OperatingUnit, showroom: OperatingUnit}|null
     */
    private function resolveOperatingUnits(): ?array
    {
        $procurement = OperatingUnit::where('name', 'Procurement & Treasury')->first();
        $foam = OperatingUnit::where('name', 'Foam Manufacturer')->first();
        $cutter = OperatingUnit::where('name', 'Cutter')->first();
        $showroom = OperatingUnit::where('name', 'Showroom')->first();

        if ($procurement === null || $foam === null || $cutter === null || $showroom === null) {
            return null;
        }

        return [
            'procurement' => $procurement,
            'foam' => $foam,
            'cutter' => $cutter,
            'showroom' => $showroom,
        ];
    }

    /**
     * @param  array<int, array{account_code: string, debit?: float, credit?: float, operating_unit_id?: string|null, memo?: string|null}>  $lines
     */
    private function postIfMissing(AccountingService $accounting, string $description, array $lines): void
    {
        if (JournalEntry::where('description', $description)->exists()) {
            return;
        }

        if (! $this->allAccountsExist(array_column($lines, 'account_code'))) {
            return;
        }

        $accounting->postJournal($description, $lines, isManual: true);
    }

    /**
     * @param  array<int, string>  $codes
     */
    private function allAccountsExist(array $codes): bool
    {
        $existing = Account::whereIn('account_code', $codes)->pluck('account_code')->all();

        return count($existing) === count(array_unique($codes));
    }
}
