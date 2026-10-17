<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Account;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * BR-GL-001: money is posted to, and documents are linked to, leaf accounts
 * only. A header such as 12 or 41 is a roll-up of its children; a balance
 * placed on it directly disappears from every child report.
 *
 * Validates an account given by id (default) or by account_code. Codes in
 * $allowedHeaders are exempt — the receivable root 122 takes client-tagged
 * lines by design (debtor statements read them by client_id).
 */
final class LeafAccount implements ValidationRule
{
    /**
     * @param  list<string>  $allowedHeaders
     */
    public function __construct(
        private readonly string $column = 'id',
        private readonly array $allowedHeaders = [],
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $account = Account::query()->where($this->column, $value)->first();

        // Existence is the job of the exists:/required rules next to this one.
        if ($account === null) {
            return;
        }

        if (in_array($account->account_code, $this->allowedHeaders, true)) {
            return;
        }

        if ($account->children()->exists()) {
            $fail("Account {$account->account_code} ({$account->name}) is a header with sub-accounts; choose one of its sub-accounts.");
        }
    }
}
