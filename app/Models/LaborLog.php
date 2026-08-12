<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LaborLog extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'production_order_id',
        'employee_id',
        'role',
        'hours_logged',
        'hourly_rate_at_log',
        'logged_at',
    ];

    protected $casts = [
        'hours_logged' => 'decimal:2',
        'hourly_rate_at_log' => 'decimal:4',
        'logged_at' => 'datetime',
    ];

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function cost(): float
    {
        return round((float) $this->hours_logged * (float) $this->hourly_rate_at_log, 4);
    }
}
