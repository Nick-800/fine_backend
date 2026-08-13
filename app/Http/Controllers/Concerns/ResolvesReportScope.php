<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Support\CurrentUnitContext;
use Illuminate\Http\Request;

/**
 * Accounting reads scope to the caller's unit by default (their subledger).
 * The desktop client always pins a unit context — even for the owner — so a
 * company-wide caller may pass `company_wide=1` to see the whole ledger.
 * The escape hatch is only honoured for users holding a company-wide role;
 * a unit manager cannot use it to read other units' books.
 */
trait ResolvesReportScope
{
    private function resolveReportUnitId(Request $request, CurrentUnitContext $context): ?string
    {
        if ($request->boolean('company_wide') && $this->hasCompanyWideRole($request)) {
            return null;
        }

        return $request->query('operating_unit_id') ?? $context->getUnitId();
    }

    private function hasCompanyWideRole(Request $request): bool
    {
        return $request->user()
            ->roles()
            ->whereNull('user_roles.operating_unit_id')
            ->exists();
    }
}
