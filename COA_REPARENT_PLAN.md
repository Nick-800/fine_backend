# COA × Account Reparent — Feature Plan

Branch: `feature/coa-reparent` (off `COA`)
Repos: `fine_backend` + `fine-desktop` (paired)

## Goal

Let an operator change the parent of any sub-account, even if it has journal
lines, with the existing children of the account coming along automatically.
Today `AccountController::update()` doesn't accept `parent_account_id`, so
reparenting is impossible.

## The "go with it" part

Free by schema: `accounts.parent_account_id → accounts.id`. Reparenting Account
A from X to Y just flips A's `parent_account_id`. A's children still point at
A's `id`, which doesn't change. They move with A automatically — no code change
needed for that.

## API

`PATCH /api/v1/accounts/{id}/parent`

Body:
```json
{ "parent_account_id": "uuid" }
```

Validation (structured error codes):
- `MAIN_ACCOUNT_NOT_REPARENTABLE` (422) — `parent_account_id IS NULL` on the account
- `PARENT_SAME_ACCOUNT` (422) — target parent is the account itself (always blocked)
- `PARENT_WRONG_TYPE` (422) — target parent's `type` differs from this account's
- `PARENT_WRONG_CHART` (422) — target parent is in a different `chart_of_accounts_id`
- `PARENT_CYCLE` (422) — target parent is a descendant of this account (recursive CTE walk)

## Behavior decisions (per user)

- **Empty branch is allowed**: reparenting A away from X may leave X with no
  children. The tree just doesn't show the moved node under X anymore.
- **Self-parent is always blocked** — even on a no-movement account. Indicates
  a UI bug or operator mistake.
- **Movement is NOT a guard**: accounts with `journal_lines` are reparentable
  per the user's request. Historical journals keep referencing the same
  `account_id`; the only thing that changes is the tree position.

## Cycle detection

Recursive CTE walking up the parent chain from the proposed parent. If the
chain ever lands on the account's own id, abort with `PARENT_CYCLE`. Single
SQL query, runs in milliseconds even on a 10k-node chart.

## Files

### `fine_backend`

- **NEW** `app/Http/Requests/v1/ReparentAccountRequest.php`
- **EDIT** `app/Http/Controllers/Api/v1/AccountController.php` — `reparent()` method
- **EDIT** `routes/api.php` — `Route::patch('/accounts/{id}/parent', ...)`
- **NEW** `tests/Feature/ReparentAccountTest.php`
- **NEW** `COA_REPARENT_PLAN.md` (root)

### `fine-desktop`

- **EDIT** `src/api/endpoints/accounting.ts` — `reparentAccount()` method
- **EDIT** `src/hooks/useAccounting.ts` — `useReparentAccount()` mutation hook
- **NEW** `src/components/accounting/ChangeAccountParentDialog.tsx`
- **EDIT** `src/pages/accounting/ChartOfAccountsPage.tsx` — embed dialog trigger
- **NEW** `COA_REPARENT_PLAN.md` (root)

## Verification

- `vendor/bin/pint --dirty --format agent`
- `php artisan test --compact --filter=ReparentAccount`
- `npx tsc --noEmit`
- `npm run lint` (0 errors)

## Commits

### `fine_backend`

1. `chore(coa): scaffold coa-reparent planning doc`
2. `feat(coa): allow reparenting an account to a new parent of the same type`
3. `test(coa): cover account reparenting including cycle + cross-chart + movement guards`

### `fine-desktop`

1. `chore(coa): scaffold coa-reparent planning doc`
2. `feat(coa): add "change parent" action to the chart of accounts page`

## Operational note (for PR description)

Reparenting is allowed for accounts with journal lines. This is intentional.
Historical journals reference the account by `account_id`, which doesn't
change — only the position in the tree changes. The trial balance, ledger,
and reports all use `account_id` directly, so they remain correct. The tree
view in the UI will simply render the account under its new parent.
