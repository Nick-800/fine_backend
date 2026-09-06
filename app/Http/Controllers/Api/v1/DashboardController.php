<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Http\Controllers\Controller;
use App\Models\LandedCostLine;
use App\Models\OperatingUnit;
use App\Models\OverheadAllocation;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 10: owner oversight. Every endpoint aggregates company-wide, so all
 * of them require a company-wide role — a unit manager gets their numbers
 * from their own scoped screens, not from here.
 */
final class DashboardController extends Controller
{
    use ResolvesReportScope;

    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    public function kpis(Request $request): JsonResponse
    {
        return $this->guarded($request, fn () => $this->dashboard->kpis());
    }

    public function unitComparison(Request $request): JsonResponse
    {
        return $this->guarded($request, fn () => $this->dashboard->unitComparison());
    }

    public function inventoryRollup(Request $request): JsonResponse
    {
        return $this->guarded($request, fn () => $this->dashboard->inventoryRollup());
    }

    public function operationalPipeline(Request $request): JsonResponse
    {
        return $this->guarded($request, fn () => $this->dashboard->operationalPipeline());
    }

    public function pendingApprovals(Request $request): JsonResponse
    {
        return $this->guarded($request, fn () => $this->dashboard->pendingApprovals());
    }

    /**
     * Per-user view of allocation-cost items awaiting the caller's decision.
     * Available to any authenticated user (not gated to company-wide roles):
     * the dashboard is for company-wide oversight, but a unit manager still
     * needs to see their own approval queue.
     */
    public function myAllocationApprovals(Request $request): JsonResponse
    {
        $user = $request->user();

        $managedUnitIds = OperatingUnit::query()
            ->where('manager_user_id', $user->id)
            ->pluck('id');

        $overhead = OverheadAllocation::withoutGlobalScopes()
            ->with('overheadExpense')
            ->whereIn('status', ['pending', 'approved'])
            ->whereIn('operating_unit_id', $managedUnitIds)
            ->latest()
            ->get()
            ->map(fn (OverheadAllocation $a) => [
                'id' => $a->id,
                'kind' => 'overhead_allocation',
                'overhead_expense_id' => $a->overhead_expense_id,
                'operating_unit_id' => $a->operating_unit_id,
                'status' => $a->status->value,
                'amount' => (float) $a->amount,
                'category' => $a->overheadExpense?->category,
            ]);

        $landed = LandedCostLine::query()
            ->select('landed_cost_lines.*')
            ->join('import_orders', 'import_orders.id', '=', 'landed_cost_lines.import_order_id')
            ->whereIn('landed_cost_lines.status', ['pending', 'approved'])
            ->whereIn('import_orders.operating_unit_id', $managedUnitIds)
            ->latest('landed_cost_lines.created_at')
            ->get()
            ->map(fn (LandedCostLine $l) => [
                'id' => $l->id,
                'kind' => 'landed_cost_line',
                'import_order_id' => $l->import_order_id,
                'operating_unit_id' => $l->importOrder?->operating_unit_id,
                'status' => $l->status->value,
                'amount' => (float) $l->amount,
                'currency' => $l->currency,
                'type' => $l->type->value,
            ]);

        return response()->json([
            'overhead_allocations' => $overhead,
            'landed_cost_lines' => $landed,
            'total' => $overhead->count() + $landed->count(),
        ]);
    }

    /**
     * @param  callable(): array<string, mixed>  $payload
     */
    private function guarded(Request $request, callable $payload): JsonResponse
    {
        if (! $this->hasCompanyWideRole($request)) {
            return response()->json([
                'message' => 'The dashboard is company-wide oversight; it needs a company-wide role.',
                'code' => 'DASHBOARD_FORBIDDEN',
            ], 403);
        }

        return response()->json($payload());
    }
}
