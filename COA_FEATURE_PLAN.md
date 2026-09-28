# COA × Inventory Items — Feature Plan

Branch: `feature/coa-inventory-items` (off `COA`)
Repos: `fine_backend` + `fine-desktop` (paired)

## Goal

1. Link `inventory_items` to a sub-account in the chart of accounts so each item
   can carry its own GL bucket. Mirrors the existing pattern used for clients,
   suppliers, fixed assets, and purchase-order payment source.
2. Add a tree-filter search box to the Chart of Accounts page so operators can
   find an account by code or name in a chart with many sub-accounts.

## Why

- The chart has dozens of asset sub-accounts (`111`, `1121`, `1131`, …). With
  per-item linkage, an operator can pin a chemical, foam block, or finished
  good to its own COA sub-account for granular reporting.
- Reuses `CoaLinkService::resolveOrProvisionAccount()` and the
  `CoaAccountSelector` component — no new mechanism, just extends the pattern.
- The default parent code for `create_new` sub-accounts is determined by the
  item's `item_type`, mirroring `app/Support/InventoryAccounts.php` so it stays
  in lockstep with intake / sale / adjustment postings.

## Type → default COA parent

| `item_type`                         | `account_code` | Arabic name                          |
| ----------------------------------- | -------------- | ------------------------------------ |
| `raw_material`                      | `111`          | مخزون المواد الخام والكيماويات        |
| `packaging`                         | `111`          | مخزون المواد الخام والكيماويات        |
| `barrel`                            | `111`          | مخزون المواد الخام والكيماويات        |
| `pallet`                            | `111`          | مخزون المواد الخام والكيماويات        |
| `foam_block`                        | `1131`         | إنتاج تام — قوالب الإسفنج            |
| `cut_template_piece`                | `1132`         | إنتاج تام — القطع المقصوصة           |
| `slice`                             | `1132`         | إنتاج تام — القطع المقصوصة           |
| `byproduct_fill`                    | `1133`         | إنتاج تام — حشوات وبقايا الإنتاج    |
| `furniture_finished_good`           | `1134`         | إنتاج تام — الأثاث والمفروشات        |

When the operator picks `coa_action = create_new`, the suggested parent follows
this table. The FE mapping is a small mirror of `InventoryAccounts::forItemType()`.

## Backend changes

### `fine_backend`

- **Migration** `2026_09_28_xxxxxx_add_account_id_to_inventory_items_table.php`
  - nullable `foreignUuid('account_id')->after('category_id')->constrained('accounts')->nullOnDelete();`
- **`app/Models/InventoryItem.php`**
  - add `'account_id'` to `$fillable`
  - add `account(): BelongsTo` relation
- **`app/Http/Controllers/Api/v1/InventoryItemController.php`**
  - inject `CoaLinkService`
  - `store()` and `update()`: extend inline validation with the COA triplet
    (`account_id` / `coa_action` / `new_account.*` — mirror `StoreSupplierRequest`)
  - resolve via `CoaLinkService::resolveOrProvisionAccount()`
  - `index()` / `show()` eager-load `account`
- **Tests** `tests/Feature/InventoryItemCoaLinkTest.php`
  - `coa_action=none` → `account_id = null`
  - `coa_action=link_existing` → linked
  - `coa_action=create_new` → new sub-account provisioned and linked
  - validation rejections (missing `account_id` when `link_existing`, missing
    `new_account.*` when `create_new`, duplicate account_code)

### Frontend changes

### `fine-desktop`

- **`src/api/endpoints/inventory.ts`**
  - add `account?: { id; account_code; name; type; currency } | null` to `InventoryItem`
  - add `account_id` / `coa_action` / `new_account` to `InventoryItemPayload`
- **`src/pages/inventory/InventoryItemFormPage.tsx`**
  - state: `coaAction`, `selectedAccountId`, `newAccount`
  - hydrate from `editingItem.account_id` on edit
  - render `CoaAccountSelector` as the last question in the `GuidedForm`
    - `entityTypeLabel="الصنف"`
    - `defaultEntityName={name}`
    - `preferredParentCode` driven by the type → code map above
    - `useEntityNameDirectly={false}` so per-item sub-accounts get a clear name
- **`src/pages/inventory/InventoryItemsPage.tsx`**
  - add a column showing the linked account chip when present
- **`src/pages/inventory/WarehouseItemDetailPage.tsx`**
  - show the linked-account chip in the metadata block
- **`src/pages/accounting/ChartOfAccountsPage.tsx`**
  - new sticky `Search` input at the top of the left tree card
  - `filterTree(tree, term)`: keeps nodes whose `account_code` or `name`
    matches; auto-includes ancestors; auto-expands them while the search is
    active; restores previous `expandedIds` on clear
  - yellow highlight on matched substrings
  - empty state when nothing matches
  - the right-panel ledger `sideSearch` is untouched

## Out of scope

- No backend change to `StockLotService` — automatic GL postings continue to
  use the canonical `InventoryAccounts::forItemType()` code. Per-item
  `account_id` is purely an opt-in reporter view at this stage.
- No new FormRequest classes for inventory items (matches the controller's
  current inline-validation style).
- No change to `useAccounts` server-side filtering — the chart tree filter is
  client-side per the agreed scope.

## Verification

- `vendor/bin/pint --dirty --format agent`
- `php artisan test --compact --filter=InventoryItem`
- `npx tsc --noEmit`
- `npm run lint` (0 errors; warnings ≤ baseline)

## Commit sequence

### Backend

1. `chore(coa): scaffold coa-inventory-items planning doc`
2. `feat(coa): link inventory_items to chart of accounts`
3. `test(coa): cover inventory_items ↔ account linkage`

### Frontend

1. `chore(coa): scaffold coa-inventory-items planning doc`
2. `feat(coa): link inventory items to chart of accounts in form + list + detail`
3. `fix(coa): add tree search filter to chart of accounts page`
