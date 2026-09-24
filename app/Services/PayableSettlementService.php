<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\JournalLine;
use App\Models\PayableSettlement;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Paying down the liabilities the operational flows accumulate:
 *
 *   2100 Accounts Payable          — credit purchases (intake, overhead, assets)
 *   2210 Payroll Deductions Payable — withholdings awaiting remittance
 *   2300 Landed Cost Clearing       — customs/freight/transport providers
 *
 * A settlement posts DR payable / CR 1200 Cash and is capped at the
 * account's live outstanding balance — the ledger is the source of truth,
 * so treasury can never pay a liability below zero. Single cash account
 * 1200 is the v1 policy.
 */
final class PayableSettlementService
{
    private const SETTLEABLE = ['21', '221', '23'];

    public function __construct(
        private readonly AccountingService $accountingService,
    ) {}

    /**
     * @param array{company_id: string, account_code: string, amount: float,
     *     operating_unit_id?: string|null, reference?: string|null,
     *     settled_by_user_id?: string|null} $data
     */
    public function settle(array $data): PayableSettlement
    {
        $accountCode = $data['account_code'];

        if (! in_array($accountCode, self::SETTLEABLE, true)) {
            throw new InvalidArgumentException(
                "Account {$accountCode} is not a settleable payable (21, 221 or 23)."
            );
        }

        $amount = round((float) $data['amount'], 4);

        if ($amount <= 0) {
            throw new InvalidArgumentException('A settlement must be greater than zero.');
        }

        return DB::transaction(function () use ($data, $accountCode, $amount) {
            $unitId = $data['operating_unit_id'] ?? null;
            $outstanding = $this->outstanding($accountCode, $unitId);

            if ($amount > $outstanding + 0.0001) {
                $scope = $unitId !== null ? 'this unit' : 'the company';
                throw new InvalidArgumentException(
                    "Cannot settle {$amount}: {$accountCode} carries only {$outstanding} outstanding for {$scope}."
                );
            }

            $accountName = Account::where('account_code', $accountCode)->value('name') ?? $accountCode;

            $settlement = PayableSettlement::create([
                'company_id' => $data['company_id'],
                'operating_unit_id' => $unitId,
                'account_code' => $accountCode,
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
                'settled_by_user_id' => $data['settled_by_user_id'] ?? null,
                'settled_at' => now()->toDateString(),
            ]);

            $this->accountingService->postJournal(
                "Payable settled: {$accountName}",
                [
                    [
                        'account_code' => $accountCode,
                        'debit' => $amount,
                        'operating_unit_id' => $unitId,
                        'memo' => $data['reference'] ?? null,
                    ],
                    [
                        'account_code' => '12', // Cash and Bank
                        'credit' => $amount,
                        'operating_unit_id' => $unitId,
                    ],
                ],
                'PayableSettlement',
                $settlement->id,
                $data['company_id'],
            );

            return $settlement->refresh();
        });
    }

    /**
     * Live outstanding balance of a liability account: credits minus debits,
     * company-wide or for one unit's subledger.
     */
    public function outstanding(string $accountCode, ?string $unitId = null): float
    {
        $query = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.account_code', $accountCode);

        if ($unitId !== null) {
            $query->where('journal_lines.operating_unit_id', $unitId);
        }

        $row = $query->selectRaw('SUM(journal_lines.credit) as credit, SUM(journal_lines.debit) as debit')->first();

        return round((float) ($row->credit ?? 0) - (float) ($row->debit ?? 0), 4);
    }
}
