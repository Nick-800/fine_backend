<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Services\FiscalPeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The fiscal calendar (FR-GL-044, ACC-11): years with their months, month
 * locks by the accounting manager, reopening by the owner.
 */
class FiscalPeriodController extends Controller
{
    public function __construct(private readonly FiscalPeriodService $periods) {}

    public function index(): JsonResponse
    {
        $company = Company::query()->firstOrFail();

        $years = FiscalYear::query()
            ->where('company_id', $company->id)
            ->with(['periods.lockedBy:id,name', 'periods.reopenedBy:id,name', 'closedBy:id,name', 'reopenedBy:id,name', 'closingEntry:id,reference'])
            ->orderByDesc('start_date')
            ->get();

        $counts = $this->entryCountsByMonth($company->id);

        return response()->json([
            'fiscal_year_start_month' => (int) $company->fiscal_year_start_month,
            'data' => $years->map(fn (FiscalYear $year): array => [
                ...$year->toArray(),
                'closing_entry' => $year->closingEntry ? [
                    'id' => $year->closingEntry->id,
                    'reference' => $year->closingEntry->reference,
                ] : null,
                'periods' => $year->periods->map(fn (FiscalPeriod $p): array => [
                    ...$p->toArray(),
                    'label' => $p->label(),
                    'entry_count' => $counts[$p->label()] ?? 0,
                ])->all(),
            ])->all(),
        ]);
    }

    public function storeYear(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $year = $this->periods->ensureYear(Company::query()->firstOrFail()->id, (int) $validated['start_year']);

        return response()->json($year->load('periods'), 201);
    }

    public function lock(Request $request, string $id): JsonResponse
    {
        $request->validate(['include_earlier' => ['sometimes', 'boolean']]);

        $locked = $this->periods->lock(FiscalPeriod::findOrFail($id), $request->user(), $request->boolean('include_earlier'));

        return response()->json([
            'message' => 'Locked.',
            'locked' => array_map(fn (FiscalPeriod $p): string => $p->label(), $locked),
        ]);
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $period = $this->periods->reopen(FiscalPeriod::findOrFail($id), $request->user(), $validated['reason']);

        return response()->json($period);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fiscal_year_start_month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $company = $this->periods->updateStartMonth(Company::query()->firstOrFail(), (int) $validated['fiscal_year_start_month']);

        return response()->json(['fiscal_year_start_month' => (int) $company->fiscal_year_start_month]);
    }

    /**
     * Live entries per 'YYYY-MM'. Bucketed in PHP so it works the same on
     * SQLite, MySQL and PostgreSQL date storage.
     *
     * @return array<string, int>
     */
    private function entryCountsByMonth(string $companyId): array
    {
        $counts = [];

        DB::table('journal_entries')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->selectRaw('entry_date, COUNT(*) as n')
            ->groupBy('entry_date')
            ->get()
            ->each(function (object $row) use (&$counts): void {
                $key = CarbonImmutable::parse($row->entry_date)->format('Y-m');
                $counts[$key] = ($counts[$key] ?? 0) + (int) $row->n;
            });

        return $counts;
    }
}
