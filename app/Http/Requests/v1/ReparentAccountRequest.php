<?php

declare(strict_types=1);

namespace App\Http\Requests\v1;

use App\Models\Account;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class ReparentAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'parent_account_id' => 'required|uuid|exists:accounts,id',
        ];
    }

    /**
     * Cross-checks the parent after the basic exists-rule passes:
     * - Main accounts cannot be reparented (they are the chart roots).
     * - Self-parent is always blocked.
     * - Target parent must share `type` and `chart_of_accounts_id`.
     * - Target parent must not be a descendant of this account (cycle).
     *
     * Errors are pushed as `code` so the FE can render specific UI states.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $accountId = $this->route('id') ?? $this->route('account');
            if (! $accountId) {
                return;
            }

            $account = Account::query()->whereKey($accountId)->first();
            if ($account === null) {
                return;
            }

            $parentId = $this->input('parent_account_id');
            $parent = Account::query()->whereKey($parentId)->first();
            if ($parent === null) {
                return;
            }

            if ($account->parent_account_id === null) {
                $v->errors()->add(
                    'parent_account_id',
                    'MAIN_ACCOUNT_NOT_REPARENTABLE: لا يمكن تغيير الأب لحساب رئيسي (جذر الشجرة).',
                );

                return;
            }

            if ((string) $parent->id === (string) $account->id) {
                $v->errors()->add(
                    'parent_account_id',
                    'PARENT_SAME_ACCOUNT: لا يمكن للحساب أن يكون أباً لنفسه.',
                );

                return;
            }

            if ((string) $parent->type !== (string) $account->type) {
                $v->errors()->add(
                    'parent_account_id',
                    "PARENT_WRONG_TYPE: نوع الحساب الأب ({$parent->type}) لا يطابق نوع الحساب الحالي ({$account->type}).",
                );

                return;
            }

            if ((string) $parent->chart_of_accounts_id !== (string) $account->chart_of_accounts_id) {
                $v->errors()->add(
                    'parent_account_id',
                    'PARENT_WRONG_CHART: الحساب الأب ينتمي إلى دليل حسابات مختلف.',
                );

                return;
            }

            if ($this->isDescendant($parent->id, $account->id)) {
                $v->errors()->add(
                    'parent_account_id',
                    'PARENT_CYCLE: الحساب الأب الجديد من أحفاد الحساب الحالي — سيُنشأ حلقة.',
                );

                return;
            }
        });
    }

    /**
     * Walk the ancestor chain from `$candidateParentId` upwards. If we ever
     * land on `$accountId`, then the proposed parent is a descendant of the
     * account being reparented — making it a new parent would create a cycle.
     *
     * Uses a recursive CTE so the walk completes in a single query.
     */
    private function isDescendant(string $candidateParentId, string $accountId): bool
    {
        $sql = <<<'SQL'
            WITH RECURSIVE ancestors AS (
                SELECT id, parent_account_id
                FROM accounts
                WHERE id = ?
                UNION ALL
                SELECT a.id, a.parent_account_id
                FROM accounts a
                INNER JOIN ancestors anc ON a.id = anc.parent_account_id
            )
            SELECT 1 FROM ancestors WHERE id = ? LIMIT 1
        SQL;

        $hit = \DB::selectOne($sql, [$candidateParentId, $accountId]);

        return $hit !== null;
    }
}
