<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class JournalEntryController extends Controller
{
    use ResolvesReportScope;

    public function __construct(
        public AccountingService $accountingService,
        public CurrentUnitContext $unitContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = JournalEntry::with(['lines.account'])->latest('entry_date');

        if (filled($request->query('from'))) {
            $query->whereDate('entry_date', '>=', $request->query('from'));
        }

        if (filled($request->query('to'))) {
            $query->whereDate('entry_date', '<=', $request->query('to'));
        }

        if ($request->boolean('manual_only')) {
            $query->where('is_manual', true);
        }

        // Filtered through lines, since an entry itself is company-level while its
        // lines carry the unit.
        if ($unitId = $this->resolveReportUnitId($request, $this->unitContext)) {
            $query->whereHas('lines', fn ($q) => $q->where('operating_unit_id', $unitId));
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    /**
     * ACC-04: manual entries exist for corrections only, and only from someone
     * accountable for the whole ledger. The entry itself still goes through
     * postJournal, so a manual correction can no more unbalance the books than
     * an auto-posted event can.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('owner') && ! $user->hasRole('accounting-manager')) {
            return response()->json([
                'message' => 'Only an accounting manager or the owner can post manual journal entries.',
                'code' => 'MANUAL_JOURNAL_FORBIDDEN',
            ], 403);
        }

        $validated = $request->validate([
            'description' => 'required|string|max:500',
            'entry_date' => 'sometimes|nullable|date|before_or_equal:today',
            'lines' => 'required|array|min:2',
            'lines.*.account_code' => 'required|string',
            'lines.*.debit' => 'sometimes|numeric|min:0',
            'lines.*.credit' => 'sometimes|numeric|min:0',
            'lines.*.operating_unit_id' => 'sometimes|nullable|uuid|exists:operating_units,id',
            'lines.*.memo' => 'sometimes|nullable|string|max:500',
        ]);

        try {
            $entry = $this->accountingService->postJournal(
                $validated['description'],
                $validated['lines'],
                isManual: true,
                userId: $user->id,
                entryDate: $validated['entry_date'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVALID_JOURNAL',
            ], 422);
        }

        return response()->json($entry->load('createdBy'), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(
            JournalEntry::with(['lines.account', 'lines.operatingUnit', 'createdBy'])->findOrFail($id)
        );
    }

    /**
     * Every journal produced by one business event (ACC-03) — the audit path from
     * a batch, import order or work order to its financial effect.
     */
    public function forDocument(string $type, string $documentId): JsonResponse
    {
        $entries = JournalEntry::with(['lines.account'])
            ->where('source_document_type', $type)
            ->where('source_document_id', $documentId)
            ->latest('entry_date')
            ->get();

        return response()->json($entries);
    }

    public function trialBalance(Request $request): JsonResponse
    {
        // ACC-03: trial balance respects the same scope filter as
        // GET /api/v1/accounts.
        $auth = $this->resolveReportUnitId($request, $this->unitContext);
        $scope = (string) $request->query('scope', $auth !== null ? 'unit' : 'global');

        if ($scope === 'company' && $auth !== null) {
            return response()->json([
                'message' => 'Company-wide scope is restricted to accounting roles.',
                'code' => 'COMPANY_WIDE_FORBIDDEN',
            ], 403);
        }

        if ($scope === 'unit') {
            $unitIdParam = $request->query('unit_id', $auth);
            if (! $unitIdParam) {
                return response()->json([
                    'message' => 'unit_id is required for scope=unit.',
                    'code' => 'UNIT_ID_REQUIRED',
                ], 422);
            }
            if ($auth !== null && (string) $auth !== (string) $unitIdParam) {
                return response()->json([
                    'message' => 'Cross-unit scope forbidden.',
                    'code' => 'CROSS_UNIT_SCOPE',
                ], 403);
            }
            $unitIdForBalance = $unitIdParam;
        } else {
            // scope=global or scope=company → union (no operating_unit_id filter
            // in the trial balance service; existing callers that pass a unit_id
            // through resolveReportUnitId continue to work, and explicitly
            // scope=global forces $unitIdForBalance = null).
            $unitIdForBalance = null;
        }

        return response()->json($this->accountingService->trialBalance($unitIdForBalance));
    }
}
