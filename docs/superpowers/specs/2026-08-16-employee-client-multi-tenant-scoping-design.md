# Multi-Tenant Scoping Polish: Employee & Client Models

- **Date:** 2026-08-16
- **Status:** Implemented (2026-08-20 — with the company-wide-role gate below)
- **Scope:** `App\Models\Employee`, `App\Models\Client`, `EmployeeController`, `ClientController`, and `OperatingUnitIsolationTest`.

---

## 1. Overview & Business Rationale

The FINE ERP architecture enforces multi-tenancy at the operating unit level using the `BelongsToOperatingUnit` trait and `OperatingUnitScope`. Models with this trait are automatically constrained to the active unit specified in the `X-Operating-Unit-ID` request header (`CurrentUnitContext`).

Currently, operational models such as `ProductionBatch`, `CutterWorkOrder`, `ProductionOrder`, `SalesOrder`, `Attendance`, `LeaveRequest`, `StockAdjustmentRequest`, and `Warehouse` use `BelongsToOperatingUnit`.

However, `Employee` and `Client`, which both have direct `operating_unit_id` columns and are strictly unit-bound entities, were missing the `BelongsToOperatingUnit` trait.

This specification details adding `BelongsToOperatingUnit` to `Employee` and `Client`, adjusting controller index querying for administrative unit override, and adding isolation test coverage in `OperatingUnitIsolationTest.php`.

---

## 2. Proposed Architectural Changes

### 2.1 Model Updates (`fine_backend/app/Models/`)

#### 2.1.1 `App\Models\Employee`
- Add `use App\Models\Traits\BelongsToOperatingUnit;`
- Attach `BelongsToOperatingUnit` trait to `Employee` class.

#### 2.1.2 `App\Models\Client`
- Add `use App\Models\Traits\BelongsToOperatingUnit;`
- Attach `BelongsToOperatingUnit` trait to `Client` class.

---

### 2.2 Controller Scoping Handling (`fine_backend/app/Http/Controllers/Api/v1/`)

#### 2.2.1 `EmployeeController::index()`
- If `$request->has('operating_unit_id')` **and the caller holds a company-wide role** (`User::hasCompanyWideRole()`), bypass only the unit scope with `$query->withoutGlobalScope(OperatingUnitScope::class)->where('operating_unit_id', …)` to allow company-wide administrators to query specific unit staff. For unit-scoped callers the parameter is ignored — it must not become a scope bypass. Lifting only the unit scope keeps SoftDeletes intact.

#### 2.2.2 `ClientController::index()`
- Same gate as 2.2.1: the drill-down filter is honoured only for company-wide roles; unit callers stay inside their ambient unit scope.

#### 2.2.3 `ResolvesReportScope` (added 2026-08-20)
- The accounting reports' `operating_unit_id` query override carries the same gate — a unit manager cannot read another unit's ledger by passing the parameter.

---

### 2.3 Automated Test Coverage (`tests/Feature/OperatingUnitIsolationTest.php`)

Add dedicated multi-tenant isolation tests:
1. `a unit-scoped user cannot read another unit employee` (expects 404).
2. `a unit-scoped user cannot modify another unit employee` (expects 404).
3. `a unit-scoped user cannot delete another unit employee` (expects 404).
4. `a unit-scoped user cannot read another unit client` (expects 404).
5. `a unit-scoped user cannot modify another unit client` (expects 404).
6. `a unit-scoped user cannot delete another unit client` (expects 404).
7. `employee and client listings exclude other units by default`.
8. `a company-wide owner without unit header sees across all units`.

---

## 3. Verification Plan

1. **Isolation Feature Tests:** `php artisan test --compact --filter=OperatingUnitIsolationTest`
2. **Entity Management Feature Tests:** `php artisan test --compact --filter=EntityManagementTest`
3. **Full Test Suite:** `php artisan test --compact`
4. **Pint Code Formatter:** `vendor/bin/pint --format agent`
