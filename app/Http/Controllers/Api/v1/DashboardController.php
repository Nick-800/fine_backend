<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Http\Controllers\Controller;
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
