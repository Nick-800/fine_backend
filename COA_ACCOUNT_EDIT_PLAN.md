# COA × Account Edit + Parent Picker + Audit Log — Feature Plan

Branch: `feature/coa-account-edit-parent` (off `main`)
Repos: `fine_backend` + `fine-desktop` (paired)

## Goal

Three connected improvements to the chart-of-accounts edit experience:

1. **Inline parent picker on the edit dialog** (`تعديل الحساب`) — the operator can change the parent account directly from the same form that edits name / code / currency. A confirmation modal appears before the actual reparent.
2. **Allow reparenting to main accounts** — the FE-side filter currently hides main accounts from the picker. Backend already permits this; the fix is purely on the FE.
3. **Audit log on account detail page** — every create / update / reparent / delete on an account is automatically captured (the existing `AuditObserver` is wired to ~30 models but not yet to `Account`) and shown on `/accounting/accounts/{id}` as a timeline using the same `AuditLogTimeline` + `AuditLogFiltersBar` components that power `OperatingUnitDetailPage` and `UserDetailPage`.

Plus: remove the now-redundant standalone `ChangeAccountParentDialog` and its trigger buttons (chart-page right panel + `AccountDetailsDialog` header). The edit dialog is the only place to reparent.

## Where the audit logs come from (no new endpoints)

```
GET /api/v1/audit-logs/{tableName}/{recordId}     ← already exists
GET /api/v1/audit-logs                            ← already exists
GET /api/v1/audit-logs/accounts/{accountId}       ← works as soon as Account emits them
```

Endpoint registered in `routes/api.php:120`, handled by `AuditLogController::show()`. Supports filters: `action`, `from`, `to`. The FE side already has `getAuditLogs(...)`, `useAuditLog(...)`, `<AuditLogTimeline>`, `<AuditLogFiltersBar>`, `<AuditLogDiff>` — all reused from existing usage in `OperatingUnitDetailPage` and `UserDetailPage`.

The only missing piece: **`Account` was never registered with the observer** in `AppServiceProvider::boot()`. Adding `Account::observe(AuditObserver::class)` is sufficient — the observer works generically on any registered Eloquent model.

## What each Account operation writes once the observer is on

| Operation | Controller method | Audit action | new_values |
|---|---|---|---|
| Create | `AccountController::store()` | `created` | full row |
| Update name/code/currency | `AccountController::update()` | `updated` | `{name, account_code, currency, parent_account_id, record_version}` |
| Reparent | `AccountController::reparent()` | `updated` | `{parent_account_id}` |
| Delete | `AccountController::destroy()` | `deleted` | `null` (old_values = full row) |

## UX flow on the edit dialog (full)

```
1. Operator opens /accounting/accounts/{id} → AccountDetailPage
2. Sees: header, ledger, edit/delete buttons, then a new "سجل النشاط" timeline
3. Clicks "تعديل" → edit dialog opens
4. Sees 4 fields now: code, name, currency, الحساب الأب (NEW, SearchableSelect)
   - الحساب الأب shows current parent
   - Disabled with explanatory text when account.is_main
   - Same-type candidates, excludes self + all descendants
   - Allows main accounts as candidates (per user request)
5. Operator picks a new parent (or keeps current)
6. Clicks حفظ التعديلات
7. If parent changed AND non-main:
   - Confirmation modal:
     "هل تريد نقل الحساب '[code] - [name]' من الحساب '[old parent]' إلى '[new parent]'؟
      الفروع الفرعية ستنتقل معه تلقائيًا."
   - On confirm → PATCH /accounts/{id}/parent
   - On cancel → nothing happens, dialog stays open
8. Always: PUT /accounts/{id} (name + code + currency) — fires before the reparent
9. Both errors surface separately via toasts
10. On full success → dialog closes

## Files

### `fine_backend`
- **EDIT** `app/Providers/AppServiceProvider.php` — add `Account::observe(AuditObserver::class);` in `boot()`
- **NEW** `tests/Feature/AccountAuditLogTest.php` — 4 cases (create/update/reparent/delete each write an audit row)
- **NEW** `COA_ACCOUNT_EDIT_PLAN.md` (root)

### `fine-desktop`
- **EDIT** `src/api/endpoints/accounting.ts` — add `parent_account_id?: string` to `UpdateAccountPayload`
- **EDIT** `src/hooks/useAccounting.ts` — new `useSaveAccountEdits` hook that fires `useUpdateAccount` then `useReparentAccount` when needed
- **EDIT** `src/components/accounting/AccountEditDialog.tsx` — new parent picker + confirmation modal
- **EDIT** `src/pages/accounting/AccountDetailPage.tsx` — new "سجل النشاط" timeline section using `useAuditLog` + existing components
- **EDIT** `src/pages/accounting/ChartOfAccountsPage.tsx` — remove standalone dialog wiring (cleanup)
- **EDIT** `src/components/accounting/AccountDetailsDialog.tsx` — remove standalone dialog wiring (cleanup)
- **DELETE** `src/components/accounting/ChangeAccountParentDialog.tsx` (dead code after edit dialog owns the picker)
- **NEW** `COA_ACCOUNT_EDIT_PLAN.md` (root)

## Verification

- `vendor/bin/pint --dirty --format agent`
- `php artisan test --compact --filter=AccountAuditLog`
- `php artisan test --compact` — full suite, 573+new pass
- `npx tsc --noEmit`
- `npm run lint` (0 errors)

## Commits

### `fine_backend`
1. `chore(coa): scaffold coa-account-edit-parent planning doc`
2. `feat(coa): register Account model with AuditObserver`
3. `test(coa): cover Account audit log on create/update/reparent/delete`

### `fine-desktop`
1. `chore(coa): scaffold coa-account-edit-parent planning doc`
2. `feat(coa): inline parent picker in edit dialog + allow reparenting to main accounts`
3. `feat(coa): show audit log timeline on account detail page`

## Out of scope

- No new backend endpoints (audit-logs endpoint already exists)
- No changes to reparent validation rules (cycle / type / chart / self-parent guards stay as-is)
- No audit-log retention / archival policy
- No bulk-edit or undo
