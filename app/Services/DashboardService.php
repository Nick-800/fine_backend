<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CreditApprovalRequest;
use App\Models\CutterWorkOrder;
use App\Models\ImportOrder;
use App\Models\InternalRestockRequest;
use App\Models\JournalLine;
use App\Models\LeaveRequest;
use App\Models\OperatingUnit;
use App\Models\PayrollRun;
use App\Models\ProductionBatch;
use App\Models\ProductionOrder;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 10 §10.4: company-wide aggregations for the owner dashboard. Money
 * figures come from journal lines — the same source as the reports, so the
 * dashboard can never disagree with the books. Everything is read-only.
 */
final class DashboardService
{
    public function __construct(
        private readonly FinancialReportService $reports,
        private readonly InventoryValuationService $valuation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function kpis(): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $today = now()->toDateString();

        $income = $this->reports->incomeStatement($monthStart, $today);

        $revenue = (float) $income['revenue']['total'];
        $cogsRow = collect($income['expenses']['rows'])->firstWhere('account_code', '5100');
        $cogs = (float) ($cogsRow['balance'] ?? 0.0);

        // FX exposure: foreign-currency commitments still in flight, per
        // currency (§10.4 — status before complete).
        $exposure = ImportOrder::withoutGlobalScopes()
            ->where('status', '!=', 'complete')
            ->get()
            ->groupBy('currency')
            ->map(fn ($orders) => round((float) $orders->sum(
                fn (ImportOrder $o) => (float) $o->negotiated_price * (float) $o->quantity
            ), 4))
            ->toArray();

        return [
            'period' => ['from' => $monthStart, 'to' => $today],
            'revenue_mtd' => $revenue,
            'cogs_mtd' => round($cogs, 4),
            'gross_margin_pct' => $revenue > 0 ? round(($revenue - $cogs) / $revenue * 100, 2) : null,
            'net_profit_mtd' => (float) $income['net_income'],
            'cash_position' => $this->accountBalance('1200'),
            'fx_exposure' => $exposure,
            'pending_approvals' => $this->pendingCounts(),
        ];
    }

    /**
     * MTD profit and current inventory value side by side, per unit.
     *
     * @return array<string, mixed>
     */
    public function unitComparison(): array
    {
        $profitability = $this->reports->unitProfitability(
            now()->startOfMonth()->toDateString(),
            now()->toDateString(),
        );

        $rowsByUnit = collect($profitability['rows'])->keyBy('operating_unit_id');

        $rows = OperatingUnit::orderBy('name')->get()->map(function (OperatingUnit $unit) use ($rowsByUnit) {
            $profit = $rowsByUnit->get($unit->id);

            return [
                'operating_unit_id' => $unit->id,
                'unit_name' => $unit->name,
                'revenue' => (float) ($profit['revenue'] ?? 0),
                'expenses' => (float) ($profit['expenses'] ?? 0),
                'net' => (float) ($profit['net'] ?? 0),
                'inventory_value' => $this->valuation->getUnitValuation($unit->id)['total_valuation'],
            ];
        })->all();

        $unallocated = $rowsByUnit->get(null);

        return [
            'period' => $profitability['from'].' — '.$profitability['to'],
            'rows' => $rows,
            'unallocated_net' => (float) ($unallocated['net'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inventoryRollup(): array
    {
        $units = OperatingUnit::orderBy('name')->get()->map(fn (OperatingUnit $unit) => [
            'unit_name' => $unit->name,
            ...$this->valuation->getUnitValuation($unit->id),
        ])->all();

        return [
            'units' => $units,
            'company' => $this->valuation->getCompanyRollup(),
        ];
    }

    /**
     * Status breakdown per operational domain (§10.4 bottom row).
     *
     * @return array<string, array{total: int, statuses: array<string, int>}>
     */
    public function operationalPipeline(): array
    {
        return [
            'import_orders' => $this->statusBreakdown(ImportOrder::withoutGlobalScopes()),
            'foam_batches' => $this->statusBreakdown(ProductionBatch::withoutGlobalScopes()),
            'cutter_work_orders' => $this->statusBreakdown(CutterWorkOrder::withoutGlobalScopes()),
            'furniture_orders' => $this->statusBreakdown(ProductionOrder::withoutGlobalScopes()),
            'sales_orders' => $this->statusBreakdown(SalesOrder::withoutGlobalScopes()),
        ];
    }

    /**
     * Everything awaiting a decision, with enough context to act from the
     * inbox (§10.4 sidebar). The action endpoints already exist per module.
     *
     * @return array<string, mixed>
     */
    public function pendingApprovals(): array
    {
        $credit = CreditApprovalRequest::with(['salesOrder.client.entity'])
            ->where('status', 'pending')
            ->latest()
            ->get()
            ->map(fn (CreditApprovalRequest $r) => [
                'id' => $r->id,
                'order_number' => $r->salesOrder?->order_number,
                'client_name' => $r->salesOrder?->client?->entity?->name,
                'amount_over_limit' => (float) $r->amount_over_limit,
            ]);

        $restock = InternalRestockRequest::withoutGlobalScopes()
            ->with(['requestingUnit', 'sourceUnit'])
            ->where('status', 'pending_approval')
            ->latest()
            ->get()
            ->map(fn (InternalRestockRequest $r) => [
                'id' => $r->id,
                'request_number' => $r->request_number,
                'requesting_unit' => $r->requestingUnit?->name,
                'source_unit' => $r->sourceUnit?->name,
            ]);

        $payroll = PayrollRun::where('status', 'pending_approval')
            ->orderByDesc('period')
            ->get()
            ->map(fn (PayrollRun $r) => [
                'id' => $r->id,
                'period' => $r->period,
                'total_net' => (float) $r->total_net,
            ]);

        $leave = LeaveRequest::withoutGlobalScopes()
            ->with('employee.entity')
            ->where('status', 'pending')
            ->latest()
            ->get()
            ->map(fn (LeaveRequest $r) => [
                'id' => $r->id,
                'employee_name' => $r->employee?->entity?->name,
                'start_date' => $r->start_date?->toDateString(),
                'end_date' => $r->end_date?->toDateString(),
                'leave_type' => $r->leave_type->value,
            ]);

        return [
            'credit_approvals' => $credit,
            'restock_requests' => $restock,
            'payroll_runs' => $payroll,
            'leave_requests' => $leave,
            'total' => $credit->count() + $restock->count() + $payroll->count() + $leave->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function pendingCounts(): array
    {
        return [
            'credit' => CreditApprovalRequest::where('status', 'pending')->count(),
            'restock' => InternalRestockRequest::withoutGlobalScopes()->where('status', 'pending_approval')->count(),
            'payroll' => PayrollRun::where('status', 'pending_approval')->count(),
            'leave' => LeaveRequest::withoutGlobalScopes()->where('status', 'pending')->count(),
        ];
    }

    private function accountBalance(string $code): float
    {
        $row = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.account_code', $code)
            ->selectRaw('SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->first();

        return round((float) ($row->debit ?? 0) - (float) ($row->credit ?? 0), 4);
    }

    /**
     * @return array{total: int, statuses: array<string, int>}
     */
    private function statusBreakdown(Builder $query): array
    {
        $counts = $query
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($v) => (int) $v)
            ->toArray();

        return [
            'total' => array_sum($counts),
            'statuses' => $counts,
        ];
    }
}
