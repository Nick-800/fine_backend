<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PosDailyClose;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Support\CurrentUnitContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The register day: what the counter sold and what money came in, measured
 * in the company's own timezone — a sale at 01:00 Tripoli time belongs to
 * that day, not to the previous UTC one.
 */
class PosController extends Controller
{
    public function __construct(public CurrentUnitContext $unitContext) {}

    /**
     * Cash-drawer reconciliation: the day's sales by payment method, and the
     * money actually received by method and by treasury.
     */
    public function dailyReport(Request $request): JsonResponse
    {
        [$date, $start, $end, $timezone] = $this->dayWindow($request->query('date'));

        $sales = $this->soldBetween($start, $end);
        $payments = SalePayment::with('cashAccount')->whereBetween('received_at', [$start, $end])->get();

        return response()->json([
            'date' => $date,
            'timezone' => $timezone,
            'sales_count' => $sales->count(),
            'total' => round((float) $sales->sum('total_amount'), 4),
            'total_cost' => round((float) $sales->sum('total_cost'), 4),
            'by_method' => $sales->groupBy('payment_method')->map(fn ($group) => [
                'count' => $group->count(),
                'total' => round((float) $group->sum('total_amount'), 4),
            ]),
            'collected' => $payments->groupBy('method')->map(fn ($group) => [
                'count' => $group->count(),
                'total' => round((float) $group->sum('amount'), 4),
            ]),
            'by_cash_account' => $payments->groupBy('cash_account_id')->map(fn ($group) => [
                'cash_account_id' => $group->first()->cash_account_id,
                'name' => $group->first()->cashAccount?->name,
                'kind' => $group->first()->cashAccount?->kind,
                'total' => round((float) $group->sum('amount'), 4),
            ])->values(),
            'expected_cash' => round((float) $payments->where('method', 'cash')->sum('amount'), 4),
        ]);
    }

    /**
     * The recorded register close for a date, if the drawer was counted.
     */
    public function showDailyClose(Request $request): JsonResponse
    {
        [$date] = $this->dayWindow($request->query('date'));

        return response()->json([
            'data' => PosDailyClose::with('closedBy')->whereDate('close_date', $date)->first(),
        ]);
    }

    /**
     * Persist the Z-report drawer count. Expected cash is derived server-side
     * from the cash actually received that day — sales paid in cash plus cash
     * collections on receivables. The client only supplies what was counted.
     */
    public function dailyClose(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $unitId = $this->unitContext->getUnitId();

        if ($unitId === null) {
            return response()->json([
                'message' => 'Select an operating unit before closing the register.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        [$date, $start, $end] = $this->dayWindow($validated['date'] ?? null);

        if (PosDailyClose::whereDate('close_date', $date)->exists()) {
            return response()->json([
                'message' => 'This register day is already closed.',
                'code' => 'POS_DAY_ALREADY_CLOSED',
            ], 422);
        }

        $sales = $this->soldBetween($start, $end);

        $expected = round((float) SalePayment::whereBetween('received_at', [$start, $end])
            ->where('method', 'cash')
            ->sum('amount'), 4);
        $counted = round((float) $validated['counted_cash'], 4);

        $close = PosDailyClose::create([
            'operating_unit_id' => $unitId,
            'close_date' => $date,
            'expected_cash' => $expected,
            'counted_cash' => $counted,
            'difference' => round($counted - $expected, 4),
            'sales_count' => $sales->count(),
            'total_sales' => round((float) $sales->sum('total_amount'), 4),
            'notes' => $validated['notes'] ?? null,
            'closed_by_user_id' => $request->user()->id,
        ]);

        return response()->json($close->load('closedBy'), 201);
    }

    /**
     * Client sales that actually happened in the window (not ones waiting on,
     * or refused, credit approval; not internal transfers).
     *
     * @return Collection<int, SalesOrder>
     */
    private function soldBetween(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return SalesOrder::where('channel', 'pos')
            ->where('buyer_type', 'client')
            ->whereNotIn('status', ['pending_approval', 'rejected'])
            ->whereBetween('created_at', [$start, $end])
            ->get();
    }

    /**
     * The requested local day (default: today) as a UTC window.
     *
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable, 3: string}
     */
    private function dayWindow(?string $date): array
    {
        $timezone = $this->unitContext->unit()?->company?->timezone
            ?? Company::query()->value('timezone')
            ?? 'Africa/Tripoli';

        $day = $date !== null
            ? CarbonImmutable::parse($date, $timezone)
            : CarbonImmutable::now($timezone);

        return [
            $day->toDateString(),
            $day->startOfDay()->utc(),
            $day->endOfDay()->utc(),
            $timezone,
        ];
    }
}
