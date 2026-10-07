<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\v1\ReparentAccountRequest;
use App\Models\Account;
use App\Models\ChartOfAccounts;
use App\Models\Company;
use App\Models\JournalLine;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AccountController extends Controller
{
    use ResolvesReportScope;

    public function __construct(
        private readonly CurrentUnitContext $unitContext,
    ) {}

    public function index(): JsonResponse
    {
        $rawAccounts = Account::query()
            ->withSum('journalLines as direct_debit', 'debit')
            ->withSum('journalLines as direct_credit', 'credit')
            ->orderBy('account_code')
            ->get();

        $childrenMap = [];
        $accountsById = [];

        foreach ($rawAccounts as $account) {
            $accountsById[$account->id] = $account;
            $parentId = $account->parent_account_id;
            if ($parentId !== null) {
                $childrenMap[$parentId][] = $account->id;
            }
        }

        $rollupTotals = [];

        $computeRollup = function (string $accountId) use (&$computeRollup, &$rollupTotals, $accountsById, $childrenMap): array {
            if (isset($rollupTotals[$accountId])) {
                return $rollupTotals[$accountId];
            }

            $account = $accountsById[$accountId] ?? null;
            if ($account === null) {
                return ['debit' => 0.0, 'credit' => 0.0];
            }

            $debit = (float) ($account->direct_debit ?? 0);
            $credit = (float) ($account->direct_credit ?? 0);

            if (isset($childrenMap[$accountId])) {
                foreach ($childrenMap[$accountId] as $childId) {
                    $childTotals = $computeRollup($childId);
                    $debit += $childTotals['debit'];
                    $credit += $childTotals['credit'];
                }
            }

            $rollupTotals[$accountId] = [
                'debit' => round($debit, 4),
                'credit' => round($credit, 4),
            ];

            return $rollupTotals[$accountId];
        };

        foreach ($rawAccounts as $account) {
            $computeRollup($account->id);
        }

        $accounts = $rawAccounts->map(function (Account $account) use ($rollupTotals, $childrenMap): array {
            $directDebit = round((float) ($account->direct_debit ?? 0), 4);
            $directCredit = round((float) ($account->direct_credit ?? 0), 4);
            $directBalance = in_array($account->type, ['asset', 'expense'], true)
                ? round($directDebit - $directCredit, 4)
                : round($directCredit - $directDebit, 4);

            $totals = $rollupTotals[$account->id] ?? ['debit' => $directDebit, 'credit' => $directCredit];
            $debit = $totals['debit'];
            $credit = $totals['credit'];

            $balance = in_array($account->type, ['asset', 'expense'], true)
                ? round($debit - $credit, 4)
                : round($credit - $debit, 4);

            return [
                'id' => $account->id,
                'account_code' => $account->account_code,
                'name' => $account->name,
                'type' => $account->type,
                'section' => $account->section,
                'currency' => $account->currency,
                'parent_account_id' => $account->parent_account_id,
                'is_main' => $account->parent_account_id === null,
                'total_debit' => $debit,
                'total_credit' => $credit,
                'balance' => $balance,
                'direct_debit' => $directDebit,
                'direct_credit' => $directCredit,
                'direct_balance' => $directBalance,
                'has_children' => ! empty($childrenMap[$account->id]),
            ];
        });

        return response()->json([
            'data' => $accounts,
            'meta' => ['count' => $accounts->count()],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $account = Account::with(['parent:id,account_code,name,type'])->findOrFail($id);

        $descendantIds = $this->getDescendantAccountIds($account->id);
        $allAccountIds = array_merge([$account->id], $descendantIds);

        $unitId = $this->resolveReportUnitId($request, $this->unitContext);

        // Direct lines
        $directQuery = $account->journalLines();
        if ($unitId) {
            $directQuery->where('operating_unit_id', $unitId);
        }
        $directDebit = round((float) (clone $directQuery)->sum('debit'), 4);
        $directCredit = round((float) (clone $directQuery)->sum('credit'), 4);
        $directBalance = in_array($account->type, ['asset', 'expense'], true)
            ? round($directDebit - $directCredit, 4)
            : round($directCredit - $directDebit, 4);
        $directTransactionCount = (clone $directQuery)->count();

        // Rolled-up lines (self + all descendants)
        $rolledQuery = JournalLine::whereIn('account_id', $allAccountIds);
        if ($unitId) {
            $rolledQuery->where('operating_unit_id', $unitId);
        }
        $totalDebit = round((float) (clone $rolledQuery)->sum('debit'), 4);
        $totalCredit = round((float) (clone $rolledQuery)->sum('credit'), 4);
        $balance = in_array($account->type, ['asset', 'expense'], true)
            ? round($totalDebit - $totalCredit, 4)
            : round($totalCredit - $totalDebit, 4);
        $transactionCount = (clone $rolledQuery)->count();

        return response()->json([
            'data' => [
                'id' => $account->id,
                'account_code' => $account->account_code,
                'name' => $account->name,
                'type' => $account->type,
                'section' => $account->section,
                'currency' => $account->currency,
                'parent_account_id' => $account->parent_account_id,
                'is_main' => $account->parent_account_id === null,
                'parent' => $account->parent,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'balance' => $balance,
                'direct_debit' => $directDebit,
                'direct_credit' => $directCredit,
                'direct_balance' => $directBalance,
                'has_children' => count($descendantIds) > 0,
                'transaction_count' => $transactionCount,
                'direct_transaction_count' => $directTransactionCount,
                'created_at' => $account->created_at?->toISOString(),
            ],
        ]);
    }

    public function ledger(Request $request, string $id): JsonResponse
    {
        $account = Account::findOrFail($id);

        $query = JournalLine::query()
            ->with(['journalEntry:id,reference,entry_date,description,is_manual', 'operatingUnit:id,name', 'account:id,account_code,name'])
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereNull('journal_entries.deleted_at')
            ->orderByDesc('journal_entries.entry_date')
            ->orderByDesc('journal_entries.created_at')
            ->select('journal_lines.*');

        if ($request->boolean('include_children')) {
            $allAccountIds = array_merge([$account->id], $this->getDescendantAccountIds($account->id));
            $query->whereIn('journal_lines.account_id', $allAccountIds);
        } else {
            $query->where('journal_lines.account_id', $account->id);
        }

        // Optional filter by unit dimension on the transaction lines
        $unitId = $this->resolveReportUnitId($request, $this->unitContext);
        if ($unitId !== null) {
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

    /**
     * Recursively collect all descendant account IDs for an account.
     *
     * @return list<string>
     */
    private function getDescendantAccountIds(string $accountId): array
    {
        $childIds = Account::where('parent_account_id', $accountId)->pluck('id')->all();
        $descendants = $childIds;

        foreach ($childIds as $childId) {
            $descendants = array_merge($descendants, $this->getDescendantAccountIds($childId));
        }

        return $descendants;
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_code' => 'required|string|max:50|unique:accounts,account_code',
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:asset,liability,equity,revenue,expense',
            'parent_account_id' => 'nullable|uuid|exists:accounts,id',
            'currency' => 'nullable|string|size:3',
            'section' => 'nullable|string|max:100',
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
                ['name' => 'دليل الحسابات الرئيسي']
            )->id;

        $account = Account::create([
            'chart_of_accounts_id' => $coaId,
            'account_code' => $validated['account_code'],
            'name' => $validated['name'],
            'type' => $validated['type'],
            'section' => $validated['section'] ?? null,
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
     * Move an account to a new parent. Allowed even when the account has
     * journal lines — the `account_id` doesn't change, only the tree
     * position. Existing children of the account come along automatically
     * (they reference the account by id, which doesn't change).
     *
     * Cross-checks (main-account, self-parent, type match, chart match,
     * cycle) live in `ReparentAccountRequest::withValidator`.
     */
    public function reparent(ReparentAccountRequest $request, string $id): JsonResponse
    {
        $account = Account::findOrFail($id);

        $account->parent_account_id = $request->input('parent_account_id');
        $account->save();

        $fresh = $account->fresh('parent');
        $linesQuery = $fresh->journalLines();
        $debit = round((float) $linesQuery->sum('debit'), 4);
        $credit = round((float) $linesQuery->sum('credit'), 4);
        $balance = in_array($fresh->type, ['asset', 'expense'], true)
            ? round($debit - $credit, 4)
            : round($credit - $debit, 4);

        return response()->json([
            'message' => 'تم تغيير الحساب الأب بنجاح',
            'data' => [
                'id' => $fresh->id,
                'account_code' => $fresh->account_code,
                'name' => $fresh->name,
                'type' => $fresh->type,
                'section' => $fresh->section,
                'currency' => $fresh->currency,
                'parent_account_id' => $fresh->parent_account_id,
                'is_main' => $fresh->parent_account_id === null,
                'parent' => $fresh->parent
                    ? [
                        'id' => $fresh->parent->id,
                        'account_code' => $fresh->parent->account_code,
                        'name' => $fresh->parent->name,
                        'type' => $fresh->parent->type,
                    ]
                    : null,
                'total_debit' => $debit,
                'total_credit' => $credit,
                'balance' => $balance,
            ],
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $account = Account::findOrFail($id);
        $isMain = $account->parent_account_id === null;

        $rules = [
            'name' => 'required|string|max:255',
        ];
        if (! $isMain) {
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
        if ($request->has('section')) {
            $payload['section'] = $request->input('section');
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

    public function destroy(Request $request, string $id): JsonResponse
    {
        $account = Account::findOrFail($id);
        $isMain = $account->parent_account_id === null;
        $force = $request->boolean('force');

        if ($isMain && ! $force) {
            return response()->json([
                'message' => 'Main accounts cannot be deleted.',
                'code' => 'MAIN_ACCOUNT_NOT_DELETABLE',
            ], 422);
        }

        if ($account->journalLines()->exists() && ! $force) {
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

    public function wipe(Request $request): JsonResponse
    {
        $force = $request->boolean('force');

        if (! $force && JournalLine::query()->exists()) {
            return response()->json([
                'message' => 'Cannot wipe accounts because journal transactions exist. Use force=1 to override.',
                'code' => 'ACCOUNT_HAS_TRANSACTIONS',
            ], 422);
        }

        $deletedCount = 0;
        DB::transaction(function () use ($force, &$deletedCount) {
            if ($force) {
                JournalLine::query()->delete();
            }

            if (Schema::hasTable('operating_unit_accounts')) {
                DB::table('operating_unit_accounts')->delete();
            }
            if (Schema::hasTable('cash_accounts') && Schema::hasColumn('cash_accounts', 'account_id')) {
                DB::table('cash_accounts')->update(['account_id' => null]);
            }
            if (Schema::hasTable('operating_units') && Schema::hasColumn('operating_units', 'revenue_account_id')) {
                DB::table('operating_units')->update(['revenue_account_id' => null]);
            }
            if (Schema::hasTable('suppliers') && Schema::hasColumn('suppliers', 'account_id')) {
                DB::table('suppliers')->update(['account_id' => null]);
            }
            if (Schema::hasTable('fixed_assets') && Schema::hasColumn('fixed_assets', 'account_id')) {
                DB::table('fixed_assets')->update(['account_id' => null]);
            }
            if (Schema::hasTable('purchase_orders') && Schema::hasColumn('purchase_orders', 'payment_source_account_id')) {
                DB::table('purchase_orders')->update(['payment_source_account_id' => null]);
            }

            // Unlink self-referential parent_account_id
            Account::query()->update(['parent_account_id' => null]);

            // Delete all accounts
            $deletedCount = Account::query()->delete();
        });

        return response()->json([
            'message' => 'All accounts wiped successfully.',
            'deleted_count' => $deletedCount,
        ], 200);
    }
}
