<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalEntryController extends Controller
{
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
        if ($unitId = $this->unitContext->getUnitId()) {
            $query->whereHas('lines', fn ($q) => $q->where('operating_unit_id', $unitId));
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
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
        $unitId = $request->query('operating_unit_id') ?? $this->unitContext->getUnitId();

        return response()->json($this->accountingService->trialBalance($unitId));
    }
}
