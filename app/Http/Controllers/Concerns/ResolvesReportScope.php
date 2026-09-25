<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Support\CurrentUnitContext;
use Illuminate\Http\Request;

/**
 * Accounting reads scope to the caller's unit by default (their subledger).
 * The desktop client always pins a unit context — even for the owner — so a
 * company-wide caller may pass `company_wide=1` to see the whole ledger, or
 * `operating_unit_id` to read a specific other unit. Both escape hatches are
 * only honoured for users holding a company-wide role; a unit manager cannot
 * use them to read other units' books.
 */
trait ResolvesReportScope
{
    private function resolveReportUnitId(Request $request, CurrentUnitContext $context): ?string
    {
        if ($this->hasCompanyWideRole($request)) {
            if ($request->boolean('company_wide')) {
                return null;
            }

            if ($request->filled('operating_unit_id')) {
                return (string) $request->query('operating_unit_id');
            }
        }

        return $context->getUnitId();
    }

    private function hasCompanyWideRole(Request $request): bool
    {
        return (bool) $request->user()?->hasCompanyWideRole();
    }
}
