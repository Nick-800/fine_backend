<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

final class UserRole extends Pivot
{
    use HasUuids;

    protected $table = 'user_roles';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'role_id',
        'operating_unit_id',
    ];
}
