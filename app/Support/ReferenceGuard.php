<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-delete reference checks: find the first table that still points at a
 * record, so a delete can be refused instead of orphaning rows or letting
 * foreign keys silently null out or cascade.
 *
 * Global scopes are bypassed on purpose (raw table queries): a reference from
 * another operating unit still blocks the delete.
 */
final class ReferenceGuard
{
    /**
     * @param  array<string, string|list<string>>  $references  table => column(s) holding the value
     */
    public static function firstReference(array $references, string $value): ?string
    {
        foreach ($references as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ((array) $columns as $column) {
                if (DB::table($table)->where($column, $value)->exists()) {
                    return $table;
                }
            }
        }

        return null;
    }
}
