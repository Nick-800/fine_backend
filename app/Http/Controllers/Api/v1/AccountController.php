<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ChartOfAccounts;
use App\Models\Company;
use App\Models\JournalLine;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountController extends Controller
{
    use ResolvesReportScope;

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
                    'is_main' => $account->parent_account_id === null,
                    'total_debit' => $debit,
                    'total_credit' => $credit,
                    'balance' => $balance,
                ];
            });

        return response()->json(['data' => $accounts]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $account = Account::with(['parent:id,account_code,name,type'])->findOrFail($id);

        $unitId = $this->resolveReportUnitId($request, $this->unitContext);

        $linesQuery = $account->journalLines();
        if ($unitId) {
            $linesQuery->where('operating_unit_id', $unitId);
        }

        $debit = round((float) (clone $linesQuery)->sum('debit'), 4);
        $credit = round((float) (clone $linesQuery)->sum('credit'), 4);
        $balance = in_array($account->type, ['asset', 'expense'], true)
            ? round($debit - $credit, 4)
            : round($credit - $debit, 4);
        $transactionCount = (clone $linesQuery)->count();

        return response()->json([
            'data' => [
                'id' => $account->id,
                'account_code' => $account->account_code,
                'name' => $account->name,
                'type' => $account->type,
                'currency' => $account->currency,
                'parent_account_id' => $account->parent_account_id,
                'is_main' => $account->parent_account_id === null,
                'parent' => $account->parent,
                'total_debit' => $debit,
                'total_credit' => $credit,
                'balance' => $balance,
                'transaction_count' => $transactionCount,
                'created_at' => $account->created_at?->toISOString(),
            ],
        ]);
    }

    /**
     * The account's statement: every line ever posted against it, newest
     * first. A unit-scoped caller sees only their unit's lines — the same rule
     * the journal list follows. Supports from/to date filtering and text search.
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

        if ($unitId = $this->resolveReportUnitId($request, $this->unitContext)) {
            $query->where('journal_lines.operating_unit_id', $unitId);
        }

        if ($from = $request->query('from')) {
            $query->where('journal_entries.entry_date', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->where('journal_entries.entry_date', '<=', $to);
        }

        if ($search = $request->query('search')) {
            $term = '%'.trim((string) $search).'%';
            $query->where(function ($q) use ($term) {
                $q->where('journal_entries.reference', 'like', $term)
                    ->orWhere('journal_entries.description', 'like', $term)
                    ->orWhere('journal_lines.memo', 'like', $term);
            });
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_code' => 'required|string|max:50|unique:accounts,account_code',
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:asset,liability,equity,revenue,expense',
            'parent_account_id' => 'nullable|uuid|exists:accounts,id',
            'currency' => 'nullable|string|size:3',
        ]);

        $parent = null;
        if (! empty($validated['parent_account_id'])) {
            $parent = Account::findOrFail($validated['parent_account_id']);
            if ($parent->type !== $validated['type']) {
                return response()->json([
                    'message' => 'Account type must match parent account type.',
                    'errors' => [
                        'type' => ['The account type must match parent account type ('.$parent->type.').'],
                    ],
                ], 422);
            }
        }

        $company = Company::first();
        if ($company === null) {
            return response()->json(['message' => 'No company exists.'], 422);
        }

        $coaId = $parent?->chart_of_accounts_id
            ?? ChartOfAccounts::firstOrCreate(
                ['company_id' => $company->id],
                ['name' => 'Main Chart of Accounts']
            )->id;

        $account = Account::create([
            'chart_of_accounts_id' => $coaId,
            'account_code' => $validated['account_code'],
            'name' => $validated['name'],
            'type' => $validated['type'],
            'currency' => strtoupper($validated['currency'] ?? $company->default_currency ?? 'LYD'),
            'parent_account_id' => $validated['parent_account_id'] ?? null,
        ]);

        return response()->json([
            'message' => 'Account created successfully.',
            'data' => [
                'id' => $account->id,
                'account_code' => $account->account_code,
                'name' => $account->name,
                'type' => $account->type,
                'currency' => $account->currency,
                'parent_account_id' => $account->parent_account_id,
                'total_debit' => 0.0,
                'total_credit' => 0.0,
                'balance' => 0.0,
            ],
        ], 201);
    }

    /**
     * ACC-02: a main account (parent_account_id IS NULL) has its code locked
     * and only its name (and currency) is mutable. Sub-accounts are fully
     * editable across code, name, and currency.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $account = Account::findOrFail($id);
        $isMain = $account->parent_account_id === null;

        $rules = [
            'name' => 'required|string|max:255',
        ];
        if (! $isMain) {
            // Sub-accounts may update code, but only when the operator
            // explicitly provides one (rename-only updates skip the field).
            $rules['account_code'] = 'sometimes|required|string|max:50|unique:accounts,account_code,'.$account->id;
        }

        $validated = $request->validate($rules);

        if ($isMain && $request->filled('account_code') && $request->input('account_code') !== $account->account_code) {
            return response()->json([
                'message' => 'Cannot change account code on a main account.',
                'code' => 'MAIN_ACCOUNT_CODE_LOCKED',
            ], 422);
        }

        $payload = ['name' => $validated['name']];
        if (! $isMain && isset($validated['account_code'])) {
            $payload['account_code'] = $validated['account_code'];
        }
        if ($request->filled('currency')) {
            $payload['currency'] = strtoupper((string) $request->input('currency'));
        }

        $account->update($payload);

        return response()->json([
            'message' => 'Account updated successfully.',
            'data' => [
                'id' => $account->id,
                'account_code' => $account->account_code,
                'name' => $account->name,
                'type' => $account->type,
                'currency' => $account->currency,
                'parent_account_id' => $account->parent_account_id,
                'is_main' => $isMain,
            ],
        ]);
    }

    /**
     * ACC-02: delete is allowed only for sub-accounts that have no journal
     * lines and no children. Main accounts can never be deleted.
     */
    public function destroy(string $id): JsonResponse
    {
        $account = Account::findOrFail($id);
        $isMain = $account->parent_account_id === null;

        if ($isMain) {
            return response()->json([
                'message' => 'Main accounts cannot be deleted.',
                'code' => 'MAIN_ACCOUNT_NOT_DELETABLE',
            ], 422);
        }

        if ($account->journalLines()->exists()) {
            return response()->json([
                'message' => 'Account has journal lines and cannot be deleted.',
                'code' => 'ACCOUNT_HAS_TRANSACTIONS',
            ], 422);
        }

        if ($account->children()->exists()) {
            return response()->json([
                'message' => 'Account has child accounts and cannot be deleted.',
                'code' => 'ACCOUNT_HAS_CHILDREN',
            ], 422);
        }

        $account->delete();

        return response()->json(['message' => 'Account deleted successfully.'], 200);
    }
}
