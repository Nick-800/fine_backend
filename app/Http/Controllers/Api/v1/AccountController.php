<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalLine;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only chart of accounts. Accounts are seeded, not managed through the
 * API — a ledger whose accounts can be edited from a client is a ledger whose
 * history can silently change meaning.
 */
final class AccountController extends Controller
{
    public function __construct(
        private readonly CurrentUnitContext $unitContext,
    ) {}

    public function index(): JsonResponse
    {
        $accounts = Account::query()
            ->withSum('journalLines as total_debit', 'debit')
            ->withSum('journalLines as total_credit', 'credit')
            ->orderBy('account_code')
            ->get()
            ->map(function (Account $account): array {
                $debit = round((float) ($account->total_debit ?? 0), 4);
                $credit = round((float) ($account->total_credit ?? 0), 4);

                $balance = in_array($account->type, ['asset', 'expense'], true)
                    ? round($debit - $credit, 4)
                    : round($credit - $debit, 4);

                return [
                    'id' => $account->id,
                    'account_code' => $account->account_code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'currency' => $account->currency,
                    'parent_account_id' => $account->parent_account_id,
                    'total_debit' => $debit,
                    'total_credit' => $credit,
                    'balance' => $balance,
                ];
            });

        return response()->json(['data' => $accounts]);
    }

    /**
     * The account's statement: every line ever posted against it, newest
     * first. A unit-scoped caller sees only their unit's lines — the same rule
     * the journal list follows.
     */
    public function ledger(Request $request, string $id): JsonResponse
    {
        $account = Account::findOrFail($id);

        $query = JournalLine::query()
            ->with(['journalEntry:id,reference,entry_date,description,is_manual', 'operatingUnit:id,name'])
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->orderByDesc('journal_entries.entry_date')
            ->orderByDesc('journal_entries.created_at')
            ->select('journal_lines.*');

        if ($unitId = $this->unitContext->getUnitId()) {
            $query->where('journal_lines.operating_unit_id', $unitId);
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }
}
