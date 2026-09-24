<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AllocationMethod;
use App\Enums\OverheadExpenseStatus;
use App\Enums\OverheadPaymentSource;
use App\Models\Employee;
use App\Models\OperatingUnit;
use App\Models\OverheadAllocationRule;
use App\Models\OverheadExpense;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Phase 08 §8.5. An overhead expense enters the ledger the moment it is
 * recorded (ACC-02); allocation then distributes a company-level expense
 * across units, and — only when the company runs full-absorption costing —
 * pushes each manufacturing unit's share into its WIP (ACC-06).
 */
final class OverheadService
{
    public function __construct(
        private readonly AccountingService $accountingService,
    ) {}

    /**
     * @param array{company_id: string, operating_unit_id?: string|null, category: string,
     *     description?: string|null, amount: float, expense_date: string, payment_source: string} $data
     */
    public function recordExpense(array $data): OverheadExpense
    {
        return DB::transaction(function () use ($data) {
            $expense = OverheadExpense::create([
                'company_id' => $data['company_id'],
                'operating_unit_id' => $data['operating_unit_id'] ?? null,
                'category' => $data['category'],
                'description' => $data['description'] ?? null,
                'amount' => $data['amount'],
                'expense_date' => $data['expense_date'],
                'payment_source' => $data['payment_source'],
                'status' => OverheadExpenseStatus::Recorded,
            ]);

            $source = OverheadPaymentSource::from($data['payment_source']);
            $label = $expense->operatingUnit?->name ?? 'company-wide';

            $this->accountingService->postJournal(
                "Overhead: {$expense->category->value} ({$label})",
                [
                    [
                        'account_code' => '54', // Overhead Expense
                        'debit' => (float) $expense->amount,
                        'operating_unit_id' => $expense->operating_unit_id,
                        'memo' => $expense->description,
                    ],
                    [
                        'account_code' => $source->accountCode(),
                        'credit' => (float) $expense->amount,
                        'operating_unit_id' => $expense->operating_unit_id,
                    ],
                ],
                'OverheadExpense',
                $expense->id,
                $expense->company_id,
            );

            return $expense->refresh();
        });
    }

    /**
     * Distribute a company-level expense across units.
     *
     * Always posts the reclass (5400 unallocated → 5400 per unit) so unit
     * profitability reflects the shares; additionally absorbs manufacturing
     * units' shares into their WIP when `overhead_absorption_enabled` is on.
     * Units without a WIP account (store, office) keep their share as a
     * period expense either way.
     *
     * @param  array<string, float>  $usage  usage_based: measured usage per unit id
     * @param  array<string, float>  $percentages  manual_percentage: percent per unit id
     */
    public function allocate(
        OverheadExpense $expense,
        ?AllocationMethod $method = null,
        array $usage = [],
        array $percentages = [],
    ): OverheadExpense {
        return DB::transaction(function () use ($expense, $method, $usage, $percentages) {
            $expense = OverheadExpense::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($expense->id);

            if ($expense->status !== OverheadExpenseStatus::Recorded) {
                throw new InvalidArgumentException('This expense has already been allocated.');
            }

            if ($expense->operating_unit_id !== null) {
                throw new InvalidArgumentException(
                    'Only company-level expenses are allocated; a unit expense already belongs to its unit.'
                );
            }

            if ($method === null || ($method === AllocationMethod::ManualPercentage && $percentages === [])) {
                $rule = OverheadAllocationRule::where('company_id', $expense->company_id)
                    ->where('is_active', true)
                    ->latest()
                    ->first();

                if ($method === null) {
                    if ($rule === null) {
                        throw new InvalidArgumentException(
                            'No allocation method given and no active allocation rule is configured.'
                        );
                    }

                    $method = $rule->method;
                }

                if ($method === AllocationMethod::ManualPercentage && $percentages === []) {
                    $percentages = $rule?->percentages ?? [];
                }
            }

            $units = OperatingUnit::where('company_id', $expense->company_id)
                ->where('status', '!=', 'inactive')
                ->orderBy('name')
                ->get();

            if ($units->isEmpty()) {
                throw new InvalidArgumentException('The company has no operating units to allocate across.');
            }

            $shares = $this->computeShares($expense, $units, $method, $usage, $percentages);

            $absorb = (bool) $expense->company->overhead_absorption_enabled;

            $reclassLines = [];
            $absorptionLines = [];

            foreach ($shares as $unitId => $share) {
                if ($share <= 0.0) {
                    continue;
                }

                $unit = $units->firstWhere('id', $unitId);
                $wipAccount = $this->wipAccountFor($unit);
                $absorbed = $absorb && $wipAccount !== null;

                $reclassLines[] = [
                    'account_code' => '54',
                    'debit' => $share,
                    'operating_unit_id' => $unitId,
                    'memo' => $method->value,
                ];

                if ($absorbed) {
                    $absorptionLines[] = [
                        'account_code' => $wipAccount,
                        'debit' => $share,
                        'operating_unit_id' => $unitId,
                        'memo' => "absorbed {$expense->category->value}",
                    ];
                    $absorptionLines[] = [
                        'account_code' => '54',
                        'credit' => $share,
                        'operating_unit_id' => $unitId,
                    ];
                }

                $expense->allocations()->create([
                    'operating_unit_id' => $unitId,
                    'method' => $method,
                    'amount' => $share,
                    'absorbed' => $absorbed,
                ]);
            }

            $reclassLines[] = [
                'account_code' => '54',
                'credit' => round(array_sum(array_column($reclassLines, 'debit')), 4),
                'operating_unit_id' => null,
                'memo' => 'moved to units',
            ];

            $this->accountingService->postJournal(
                "Overhead allocation: {$expense->category->value} ({$method->value})",
                $reclassLines,
                'OverheadExpense',
                $expense->id,
                $expense->company_id,
            );

            if ($absorptionLines !== []) {
                $this->accountingService->postJournal(
                    "Overhead absorption into WIP: {$expense->category->value}",
                    $absorptionLines,
                    'OverheadExpense',
                    $expense->id,
                    $expense->company_id,
                );
            }

            $expense->update([
                'status' => OverheadExpenseStatus::Allocated,
                'allocated_at' => now(),
            ]);

            return $expense->refresh()->load('allocations.operatingUnit');
        });
    }

    /**
     * Per-unit shares to 4dp; the last participating unit absorbs the
     * rounding remainder so the shares always sum back to the expense
     * exactly (same policy as batch cost apportionment).
     *
     * @param  Collection<int, OperatingUnit>  $units
     * @param  array<string, float>  $usage
     * @param  array<string, float>  $percentages
     * @return array<string, float>
     */
    private function computeShares(
        OverheadExpense $expense,
        Collection $units,
        AllocationMethod $method,
        array $usage,
        array $percentages,
    ): array {
        $amount = (float) $expense->amount;
        $unitIds = $units->pluck('id')->all();

        $weights = match ($method) {
            AllocationMethod::EvenSplit => array_fill_keys($unitIds, 1.0),
            AllocationMethod::UsageBased => $this->validatedWeights($usage, $unitIds, 'usage figures'),
            AllocationMethod::HeadcountBased => $this->headcountWeights($unitIds),
            AllocationMethod::ManualPercentage => $this->validatedPercentages($percentages, $unitIds),
        };

        $totalWeight = array_sum($weights);

        if ($totalWeight <= 0.0) {
            throw new InvalidArgumentException('Allocation weights sum to zero; nothing to distribute.');
        }

        $participating = array_keys(array_filter($weights, fn (float $w) => $w > 0.0));
        $lastUnitId = end($participating);

        $shares = [];
        $allocated = 0.0;

        foreach ($participating as $unitId) {
            if ($unitId === $lastUnitId) {
                $shares[$unitId] = round($amount - $allocated, 4);
            } else {
                $share = round($amount * ($weights[$unitId] / $totalWeight), 4);
                $shares[$unitId] = $share;
                $allocated += $share;
            }
        }

        return $shares;
    }

    /**
     * @param  array<string, float>  $weights
     * @param  list<string>  $unitIds
     * @return array<string, float>
     */
    private function validatedWeights(array $weights, array $unitIds, string $label): array
    {
        if ($weights === []) {
            throw new InvalidArgumentException("This method needs {$label} per unit and none were given.");
        }

        foreach ($weights as $unitId => $value) {
            if (! in_array($unitId, $unitIds, true)) {
                throw new InvalidArgumentException("Unit {$unitId} does not belong to this company.");
            }

            if ((float) $value < 0) {
                throw new InvalidArgumentException("Negative {$label} make no sense.");
            }
        }

        return array_map(fn ($v) => (float) $v, $weights);
    }

    /**
     * @param  array<string, float>  $percentages
     * @param  list<string>  $unitIds
     * @return array<string, float>
     */
    private function validatedPercentages(array $percentages, array $unitIds): array
    {
        $weights = $this->validatedWeights($percentages, $unitIds, 'percentages');

        $total = round(array_sum($weights), 2);

        if (abs($total - 100.0) > 0.01) {
            throw new InvalidArgumentException("Manual percentages must sum to 100, got {$total}.");
        }

        return $weights;
    }

    /**
     * @param  list<string>  $unitIds
     * @return array<string, float>
     */
    private function headcountWeights(array $unitIds): array
    {
        $counts = Employee::withoutGlobalScopes()
            ->whereIn('operating_unit_id', $unitIds)
            ->selectRaw('operating_unit_id, COUNT(*) as headcount')
            ->groupBy('operating_unit_id')
            ->pluck('headcount', 'operating_unit_id');

        if ($counts->sum() === 0) {
            throw new InvalidArgumentException('No employees are registered; headcount allocation has nothing to weight by.');
        }

        $weights = [];

        foreach ($unitIds as $unitId) {
            $weights[$unitId] = (float) ($counts[$unitId] ?? 0);
        }

        return $weights;
    }

    /**
     * Which WIP account absorbs this unit's overhead, decided by the
     * production workflow its blueprint runs — unit_type is too coarse
     * (every manufactory is just "manufactory").
     */
    private function wipAccountFor(OperatingUnit $unit): ?string
    {
        $workflows = array_keys($unit->blueprint->workflow_set ?? []);

        return match (true) {
            in_array('production_batch', $workflows, true) => '1121',  // WIP — Foam
            in_array('cutter_work_order', $workflows, true) => '1122', // WIP — Cutter
            in_array('production_order', $workflows, true) => '1123',  // WIP — Furniture
            default => null,
        };
    }
}
