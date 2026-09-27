<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A per-type, per-year counter behind server-issued document numbers, shared
 * by every unit. Only DocumentNumberService touches it.
 */
final class DocumentSequence extends Model
{
    use HasUuids;

    protected $fillable = [
        'document_type',
        'year',
        'last_number',
    ];

    protected $casts = [
        'year' => 'integer',
        'last_number' => 'integer',
    ];
}
