<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use App\Enums\TreasuryTransferStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 08 Wave 2 / TRS-03: API representation of a transfer between treasuries.
 */
final class TreasuryTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof TreasuryTransferStatus
            ? $this->status
            : TreasuryTransferStatus::tryFrom((string) $this->status);

        return [
            'id' => $this->id,
            'transfer_number' => $this->transfer_number,
            'status' => $status?->value ?? $this->status,
            'status_label' => $status?->label(),
            'from_cash_account' => $this->fromCashAccount ? [
                'id' => $this->fromCashAccount->id,
                'name' => $this->fromCashAccount->name,
                'currency' => $this->fromCashAccount->currency,
                'kind' => $this->fromCashAccount->kind,
            ] : null,
            'to_cash_account' => $this->toCashAccount ? [
                'id' => $this->toCashAccount->id,
                'name' => $this->toCashAccount->name,
                'currency' => $this->toCashAccount->currency,
                'kind' => $this->toCashAccount->kind,
            ] : null,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'fx_rate' => $this->fx_rate !== null ? (float) $this->fx_rate : null,
            'amount_lyd' => $this->amount_lyd !== null ? (float) $this->amount_lyd : null,
            'transfer_date' => $this->transfer_date?->toDateString() ?? $this->transfer_date,
            'notes' => $this->notes,
            'operating_unit_id' => $this->operating_unit_id,
            'created_by' => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null,
            'decided_by' => $this->decidedBy ? [
                'id' => $this->decidedBy->id,
                'name' => $this->decidedBy->name,
            ] : null,
            'decided_at' => $this->decided_at?->toISOString(),
            'decision_note' => $this->decision_note,
            'journal_entry' => $this->journalEntry ? [
                'id' => $this->journalEntry->id,
                'reference' => $this->journalEntry->reference,
            ] : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
