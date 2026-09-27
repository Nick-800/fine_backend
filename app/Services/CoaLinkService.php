<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\ChartOfAccounts;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CoaLinkService
{
    /**
     * Resolve an existing account or provision a new COA sub-account.
     *
     * @param  array{
     *     coa_action?: string|null,
     *     account_id?: string|null,
     *     auto_parent_code?: string|null,
     *     auto_name?: string|null,
     *     new_account?: array{
     *         parent_account_id: string,
     *         account_code: string,
     *         name: string,
     *         currency?: string|null
     *     }|null
     * }  $payload
     * @return string|null The account UUID to link, or null if unlinked.
     */
    public function resolveOrProvisionAccount(array $payload, ?string $companyId = null): ?string
    {
        $coaAction = $payload['coa_action'] ?? null;

        if ($coaAction === 'none') {
            return null;
        }

        if ($coaAction === 'link_existing') {
            return $payload['account_id'] ?? null;
        }

        if ($coaAction === 'create_new' && ! empty($payload['new_account'])) {
            return $this->provisionSubAccount($payload['new_account'], $companyId);
        }

        if ($coaAction === 'auto') {
            // No chart yet (fresh install) → leave unlinked; postings fall
            // back to the parent header instead of refusing the record.
            if (empty($payload['auto_parent_code']) || empty($payload['auto_name'])
                || ! Account::where('account_code', $payload['auto_parent_code'])->exists()) {
                return null;
            }

            return $this->provisionNextSubAccount($payload['auto_parent_code'], $payload['auto_name'], $companyId);
        }

        // Fallback for direct account_id submission
        if (isset($payload['account_id'])) {
            return $payload['account_id'];
        }

        return null;
    }

    /**
     * Provision a sub-account under the parent code with the next free code —
     * the parent code plus a 3-digit running number, the same scheme the
     * desktop CoaAccountSelector suggests (122 → 122001, 122002, …).
     */
    public function provisionNextSubAccount(string $parentCode, string $name, ?string $companyId = null): string
    {
        return DB::transaction(function () use ($parentCode, $name, $companyId): string {
            $parent = Account::where('account_code', $parentCode)->lockForUpdate()->first();

            if ($parent === null) {
                throw new InvalidArgumentException("Parent account '{$parentCode}' does not exist.");
            }

            $highest = Account::where('parent_account_id', $parent->id)
                ->pluck('account_code')
                ->filter(fn (string $code): bool => str_starts_with($code, $parentCode))
                ->map(fn (string $code): int => (int) substr($code, strlen($parentCode)))
                ->max() ?? 0;

            $next = $highest + 1;
            while (Account::where('account_code', $parentCode.str_pad((string) $next, 3, '0', STR_PAD_LEFT))->exists()) {
                $next++;
            }

            return $this->provisionSubAccount([
                'parent_account_id' => $parent->id,
                'account_code' => $parentCode.str_pad((string) $next, 3, '0', STR_PAD_LEFT),
                'name' => $name,
            ], $companyId);
        });
    }

    /**
     * Manually provision a new sub-account under the chosen parent.
     *
     * @param  array{
     *     parent_account_id: string,
     *     account_code: string,
     *     name: string,
     *     currency?: string|null
     * }  $data
     */
    public function provisionSubAccount(array $data, ?string $companyId = null): string
    {
        return DB::transaction(function () use ($data, $companyId): string {
            $parent = Account::findOrFail($data['parent_account_id']);

            $companyId ??= Company::query()->value('id');
            $company = $companyId ? Company::find($companyId) : Company::first();

            $coaId = $parent->chart_of_accounts_id;
            if ($coaId === null && $company !== null) {
                $coaId = ChartOfAccounts::firstOrCreate(
                    ['company_id' => $company->id],
                    ['name' => 'دليل الحسابات الرئيسي']
                )->id;
            }

            // Ensure unique account code
            if (Account::where('account_code', $data['account_code'])->exists()) {
                throw new InvalidArgumentException("Account code '{$data['account_code']}' already exists.");
            }

            $currency = ! empty($data['currency'])
                ? strtoupper($data['currency'])
                : ($parent->currency ?? $company?->default_currency ?? 'LYD');

            $account = Account::create([
                'chart_of_accounts_id' => $coaId,
                'parent_account_id' => $parent->id,
                'account_code' => trim($data['account_code']),
                'name' => trim($data['name']),
                'type' => $parent->type,
                'section' => $parent->section,
                'currency' => $currency,
            ]);

            return $account->id;
        });
    }
}
