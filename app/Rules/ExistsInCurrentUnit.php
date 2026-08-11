<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Existence check that respects operating-unit scoping.
 *
 * Laravel's `exists:` rule issues a raw query and therefore bypasses Eloquent
 * global scopes, so it would happily accept the ID of a record belonging to a
 * unit the caller cannot reach. Resolving through the model instead applies
 * whatever scope that model declares.
 *
 * When no unit is in context — company-wide roles such as Owner — those scopes
 * no-op, so this behaves exactly like a plain existence check.
 *
 * @param  class-string<Model>  $modelClass
 */
final class ExistsInCurrentUnit implements ValidationRule
{
    public function __construct(
        private readonly string $modelClass,
        private readonly ?string $label = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute field must be a valid identifier.');

            return;
        }

        if (! $this->modelClass::query()->whereKey($value)->exists()) {
            $noun = $this->label ?? 'record';

            $fail("The selected {$noun} does not exist or belongs to another operating unit.");
        }
    }
}
