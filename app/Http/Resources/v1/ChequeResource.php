<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use App\Enums\ChequeStatus;
use App\Models\ChequeEvent;
use App\Support\CompanyClock;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 08 Wave 2 / TRS-05: API representation of a cheque.
 */
final class ChequeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof ChequeStatus ? $this->status : ChequeStatus::tryFrom((string) $this->status);

        $today = Carbon::parse(CompanyClock::today())->startOfDay();
        $dueDate = Carbon::parse($this->due_date)->startOfDay();
        $daysToDue = (int) $today->diffInDays($dueDate, false);

        return [
            'id' => $this->id,
            'number' => $this->cheque_number_internal,
            'cheque_number_internal' => $this->cheque_number_internal,
            'direction' => $this->direction,
            'cheque_no' => $this->cheque_no,
            'bank_name' => $this->bank_name,
            'drawer_name' => $this->drawer_name,
            'client' => $this->client ? [
                'id' => $this->client->id,
                'name' => $this->client->entity?->name ?? $this->client->name ?? null,
            ] : null,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'issue_date' => $this->issue_date?->toDateString() ?? $this->issue_date,
            'due_date' => $this->due_date?->toDateString() ?? $this->due_date,
            'received_date' => $this->received_date?->toDateString() ?? $this->received_date,
            'is_certified' => (bool) $this->is_certified,
            'status' => $status?->value ?? $this->status,
            'status_label' => $status?->label(),
            'deposit_cash_account' => $this->depositCashAccount ? [
                'id' => $this->depositCashAccount->id,
                'name' => $this->depositCashAccount->name,
            ] : null,
            'notes' => $this->notes,
            'bounce_reason' => $this->bounce_reason,
            'days_to_due' => $daysToDue,
            'created_by' => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null,
            'created_at' => $this->created_at?->toISOString(),
            'events' => $this->whenLoaded('events', function () {
                return $this->events->map(function (ChequeEvent $event): array {
                    $toStatus = $event->to_status instanceof ChequeStatus
                        ? $event->to_status
                        : ChequeStatus::tryFrom((string) $event->to_status);
                    $fromStatus = $event->from_status instanceof ChequeStatus
                        ? $event->from_status
                        : ($event->from_status !== null ? ChequeStatus::tryFrom((string) $event->from_status) : null);

                    return [
                        'id' => $event->id,
                        'from_status' => $fromStatus?->value ?? $event->from_status,
                        'to_status' => $toStatus?->value ?? $event->to_status,
                        'to_status_label' => $toStatus?->label(),
                        'event_date' => $event->event_date?->toDateString() ?? $event->event_date,
                        'journal_entry' => $event->journalEntry ? [
                            'id' => $event->journalEntry->id,
                            'reference' => $event->journalEntry->reference,
                        ] : null,
                        'user' => $event->user ? [
                            'id' => $event->user->id,
                            'name' => $event->user->name,
                        ] : null,
                        'note' => $event->note,
                    ];
                });
            }),
        ];
    }
}
