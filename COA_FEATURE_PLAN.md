# COA × Inventory Items — Feature Plan (v2)

Branch: `feature/coa-inventory-items` (off `COA`)
Repos: `fine_backend` + `fine-desktop` (paired)

> **v2 revision (from screenshot of the legacy item card).** The legacy system
> allows ~12 distinct per-event account overrides per item. v1 of this plan
> only added one `account_id`. This v2 replaces that with a related table and
> makes posting services require the override — no canonical fallback.

## Goal

1. Let an operator pin each inventory item to a specific chart-of-accounts
   sub-account for each accounting event (purchases, sales, returns, COGS,
   waste, discounts, transport-in, sales commission, …).
2. Require that override before any posting touches the item — the system
   refuses to buy, sell, return, write-off, or adjust an item without the
   matching event account.
3. Add a tree-filter search box to the Chart of Accounts page.

## Why

- The legacy screen (`الحسابات` tab on the item card) maps each item to ~12
  events, with per-warehouse variants. Operators expect that level of control.
- Posting flows already accept per-entity overrides for suppliers, clients,
  fixed assets, and payment sources. This brings inventory items up to the
  same standard.
- A related table (not JSON) keeps referential integrity on `account_id`.

## The 12 events

| `event_type`        | Arabic label                | When this is required                          |
| ------------------- | --------------------------- | ---------------------------------------------- |
| `opening`           | بضاعة أول المدة             | Opening-balance seeding                        |
| `ending`            | بضاعة آخر المدة             | Period-end inventory valuation                 |
| `purchases`         | حساب المشتريات              | Intake (`StockLotService::createLot`)          |
| `sales`             | حساب المبيعات               | Sale (`SalesOrderService` revenue leg)         |
| `purchase_returns`  | حساب مردود المشتريات        | Purchase return                                |
| `sales_returns`     | حساب مردودة مبيعات          | Sale return                                    |
| `cogs`              | تكلفة البضاعة المباعة       | Sale (`SalesOrderService` COGS leg)            |
| `waste`             | حساب إهلاك / هالك           | Stock adjustment write-off                     |
| `earned_discount`   | حساب خصم مكتسب              | Supplier-side discount earned                  |
| `granted_discount`  | حساب خصم ممنوح              | Customer-side discount given                   |
| `transport_in`      | حساب غبور المشتريات         | Transportation-in leg                          |
| `sales_commission`  | حساب عمولة المبيعات         | Sales commission leg                           |

## Storage

### `inventory_item_accounts` (new table)

| Column              | Type         | Notes                                  |
| ------------------- | ------------ | -------------------------------------- |
| `id`                | UUID PK      |                                        |
| `inventory_item_id` | UUID FK      | → `inventory_items`, `cascadeOnDelete`  |
| `event_type`        | string       | enum-cast on the model                 |
| `account_id`        | UUID FK      | → `accounts`, `restrictOnDelete`       |
| `created_at`        | timestamp    |                                        |
| `updated_at`        | timestamp    |                                        |

`UNIQUE(inventory_item_id, event_type)` — one row per (item, event), item-global
only (no warehouse dimension per agreed scope).

## Behavior

- **Resolver:** `InventoryItem::accountFor(InventoryEventType $event): ?Account`
  returns the override row's account, or `null` if none.
- **Posting guard:** every posting service that consumes an event calls
  `$item->accountFor($event)`. If `null`, it returns
  `422 INVENTORY_ACCOUNT_NOT_LINKED` with a message naming the event and the
  item. **No canonical fallback** — operators must map every relevant event.
- **Existing items:** migration is purely additive; no data seeded. Existing
  items with stock or active history will refuse new postings until an
  operator maps the relevant events. This is a hard deploy-time requirement;
  it is documented in the PR description and `HANDOFF.md` note.

## Backend changes (`fine_backend`)

### Files

- **NEW** `app/Enums/InventoryEventType.php` — string-backed enum, 12 cases,
  each with Arabic label
- **NEW** `database/migrations/2026_09_28_xxxxxx_create_inventory_item_accounts_table.php`
- **NEW** `app/Models/InventoryItemAccount.php`
- **EDIT** `app/Models/InventoryItem.php` — add `accounts(): HasMany`,
  `accountFor(InventoryEventType): ?Account`
- **EDIT** `app/Http/Controllers/Api/v1/InventoryItemController.php` — add
  `accounts`, `upsertAccount`, `deleteAccount`; eager-load `accounts` on
  `index()`/`show()`
- **EDIT** `app/Services/StockLotService.php` — guard `createLot` with
  `Purchases` event
- **EDIT** `app/Services/SalesOrderService.php` — guard sale legs with
  `Sales` and `Cogs` events
- **EDIT** `app/Services/StockAdjustmentService.php` — guard write-off with
  `Waste`
- **EDIT** other services where the other events post (purchase return,
  sales return, discount legs, commission, transport-in, opening seeding)
- **NEW** `tests/Feature/InventoryItemAccountsTest.php` — cover happy paths,
  422 guards, upsert/delete endpoints, `restrictOnDelete`, `cascadeOnDelete`

### Endpoints

| Method | Path                                       | Body                                                |
| ------ | ------------------------------------------ | --------------------------------------------------- |
| GET    | `/api/v1/inventory-items/{id}/accounts`     | — (returns array of `{id, event_type, account}`)    |
| POST   | `/api/v1/inventory-items/{id}/accounts`    | `{event_type, account_id}` (upsert by composite)    |
| DELETE | `/api/v1/inventory-items/{id}/accounts/{rowId}` | —                                               |

## Frontend changes (`fine-desktop`)

### Files

- **NEW** `src/api/endpoints/inventoryItemAccounts.ts` — types + api object
- **EDIT** `src/api/endpoints/inventory.ts` — add `accounts` to `InventoryItem`
- **NEW** `src/components/inventory/InventoryItemAccountsEditor.tsx` — grid of
  12 rows with `SearchableSelect<Account>` and per-row save/delete
- **EDIT** `src/pages/inventory/InventoryItemFormPage.tsx` — add a
  "الحسابات" section embedding the editor
- **EDIT** `src/pages/inventory/InventoryItemsPage.tsx` — add a "linked
  events" indicator column (green check when all 12 mapped, warning + count
  otherwise)
- **EDIT** `src/pages/inventory/WarehouseItemDetailPage.tsx` — render the
  full account map in the metadata block
- **EDIT** `src/pages/accounting/ChartOfAccountsPage.tsx` — add tree-search
  filter with yellow highlight

### UI behavior

- Editor rows mark "required for posting" events with a small dot/tag
- Save button is disabled until every required event for the item's
  `item_type` is mapped
- Server `422 INVENTORY_ACCOUNT_NOT_LINKED` messages are surfaced via the
  existing `apiErrorPayload()` → `toastStore` pipeline

## Out of scope

- No warehouse dimension on overrides (per agreed scope)
- No canonical fallback in posting services
- No migration backfill — operators fill them
- No new FormRequest classes for inventory items (matches existing
  inline-validation style)
- No change to `useAccounts` server-side filtering — chart tree filter is
  client-side

## Verification

- `vendor/bin/pint --dirty --format agent`
- `php artisan test --compact --filter=InventoryItem`
- `npx tsc --noEmit`
- `npm run lint` (0 errors; warnings ≤ baseline)

## Commit sequence

### `fine_backend`

1. `chore(coa): revise plan to v2 — per-event account map`
2. `feat(coa): link inventory_items to chart of accounts via per-event map`
3. `feat(coa): require per-item account overrides in inventory postings`
4. `test(coa): cover per-event account overrides`

### `fine-desktop`

1. `chore(coa): revise plan to v2 — per-event account map`
2. `feat(coa): add per-event account editor to inventory items`
3. `feat(coa): show linked accounts in inventory list + detail`
4. `fix(coa): add tree search filter to chart of accounts page`
