<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChequeStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 08 Wave 2 / TRS-05: Customer cheque event history (سجل حركات الشيك).
 */
final class ChequeEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'cheque_id',
        'from_status',
        'to_status',
        'event_date',
        'journal_entry_id',
        'user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => ChequeStatus::class,
            'to_status' => ChequeStatus::class,
            'event_date' => 'date',
        ];
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
