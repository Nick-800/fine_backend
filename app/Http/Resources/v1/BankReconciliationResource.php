<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use App\Enums\BankReconciliationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 08 Wave 2 / TRS-04: API representation of a bank reconciliation.
 */
final class BankReconciliationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof BankReconciliationStatus
            ? $this->status
            : BankReconciliationStatus::tryFrom((string) $this->status);

        return [
            'id' => $this->id,
            'cash_account' => $this->cashAccount ? [
                'id' => $this->cashAccount->id,
                'name' => $this->cashAccount->name,
                'currency' => $this->cashAccount->currency,
            ] : null,
            'statement_date' => $this->statement_date?->toDateString() ?? $this->statement_date,
            'statement_balance' => (float) $this->statement_balance,
            'status' => $status?->value ?? $this->status,
            'status_label' => $status?->label(),
            'notes' => $this->notes,
            'created_by' => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null,
            'finished_by' => $this->finishedBy ? [
                'id' => $this->finishedBy->id,
                'name' => $this->finishedBy->name,
            ] : null,
            'finished_at' => $this->finished_at?->toISOString(),
        ];
    }
}
