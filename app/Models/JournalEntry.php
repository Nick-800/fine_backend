<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class JournalEntry extends Model
{
    use Auditable, HasFactory, HasOptimisticLocking, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'reference',
        'entry_date',
        'description',
        'source_document_type',
        'source_document_id',
        'is_manual',
        'created_by_user_id',
        'record_version',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'is_manual' => 'boolean',
        'record_version' => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function totalDebit(): float
    {
        return round((float) $this->lines->sum('debit'), 4);
    }

    public function totalCredit(): float
    {
        return round((float) $this->lines->sum('credit'), 4);
    }

    /**
     * ACC-01. Read back from the stored lines rather than trusting the input
     * that created them, so this can also be used to audit existing entries.
     */
    public function isBalanced(): bool
    {
        return abs($this->totalDebit() - $this->totalCredit()) < 0.0001;
    }
}
