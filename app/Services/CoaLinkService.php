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

        // Fallback for direct account_id submission
        if (isset($payload['account_id'])) {
            return $payload['account_id'];
        }

        return null;
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
