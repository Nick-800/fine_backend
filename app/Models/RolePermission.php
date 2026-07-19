<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

final class RolePermission extends Pivot
{
    use HasUuids;

    protected $table = 'role_permissions';

    public $incrementing = false;

    protected $keyType = 'string';
}
