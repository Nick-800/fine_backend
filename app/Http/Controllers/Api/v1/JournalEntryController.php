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
        // Owner sees company-wide; a unit-scoped caller sees their own subledger.
        $unitId = $this->resolveReportUnitId($request, $this->unitContext);

        return response()->json($this->accountingService->trialBalance($unitId));
    }
}
