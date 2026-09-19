<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AllocationPaymentStatus;
use App\Models\LandedCostLine;
use App\Models\OperatingUnit;
use App\Models\OverheadAllocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Two-step approval: pending -> approved (manager) -> paid (manager).
 * Only the operating unit's manager can act; absence of a manager is fatal so
 * the caller can surface it before any state change.
 */
final class AllocationPaymentService
{
    public function approve(Model $allocation, User $user, ?string $note = null): Model
    {
        return $this->transition($allocation, $user, AllocationPaymentStatus::Approved, $note);
    }

    public function markPaid(Model $allocation, User $user, ?string $note = null): Model
    {
        return $this->transition($allocation, $user, AllocationPaymentStatus::Paid, $note);
    }

    private function transition(
        Model $allocation,
        User $user,
        AllocationPaymentStatus $next,
        ?string $note,
    ): Model {
        $unit = $this->resolveOperatingUnit($allocation);
        $this->assertCanDecide($allocation, $unit, $user);

        $current = $allocation->status instanceof AllocationPaymentStatus
            ? $allocation->status
            : AllocationPaymentStatus::from((string) $allocation->status);

        if (! $current->canTransitionTo($next)) {
            throw new InvalidAllocationTransitionException(
                "Cannot transition allocation from {$current->value} to {$next->value}."
            );
        }

        $updates = [
            'status' => $next,
            'confirmation_note' => $note,
        ];

        if ($next === AllocationPaymentStatus::Approved) {
            $updates['approved_by_user_id'] = $user->id;
            $updates['approved_at'] = now();
        }

        if ($next === AllocationPaymentStatus::Paid) {
            $updates['paid_by_user_id'] = $user->id;
            $updates['paid_at'] = now();
        }

        $allocation->update($updates);

        return $allocation->refresh();
    }

    private function resolveOperatingUnit(Model $allocation): OperatingUnit
    {
        if ($allocation instanceof OverheadAllocation) {
            return $allocation->operatingUnit
                ?? OperatingUnit::findOrFail($allocation->operating_unit_id);
        }

        if ($allocation instanceof LandedCostLine) {
            $order = $allocation->importOrder
                ?? $allocation->importOrder()->firstOrFail();

            return $order->operatingUnit
                ?? OperatingUnit::findOrFail($order->operating_unit_id);
        }

        throw new InvalidAllocationTransitionException('Unsupported allocation model.');
    }

    private function assertCanDecide(Model $allocation, OperatingUnit $unit, User $user): void
    {
        $isFinancialApprover = $user->hasRole('owner')
            || $user->hasRole('admin')
            || $user->hasRole('accounting-manager')
            || $user->hasRole('treasury-officer');

        $isUnitManager = $unit->manager_user_id !== null && $unit->manager_user_id === $user->id;

        if ($allocation instanceof LandedCostLine) {
            if ($isFinancialApprover || $isUnitManager) {
                return;
            }

            throw new AllocationNotResponsibleException(
                'Only an authorized financial officer (accountant, treasury officer, owner) or the unit manager can approve or mark paid this landed cost.'
            );
        }

        if ($isFinancialApprover || $isUnitManager) {
            return;
        }

        if ($unit->manager_user_id === null) {
            throw new AllocationNoResponsibleUserException(
                "Operating unit {$unit->name} has no responsible user assigned."
            );
        }

        throw new AllocationNotResponsibleException(
            'Only the operating unit manager can approve or mark paid this allocation.'
        );
    }
}
