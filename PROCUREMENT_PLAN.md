# fix/procurement-inventory — Plan

Branch: `fix/procurement-inventory` (off `COA`)
Repos: `fine_backend` + `fine-desktop` (paired)

## Goal

When a purchase order (both `kind=foreign` and `kind=local`) finishes — goods
received AND paid — the purchased items must end up as `StockLot` rows in the
operator-chosen warehouse. Today the accounting posts (DR inventory, CR
supplier advance / payment source) but no StockLot is ever created, so the
destination warehouse stays empty even though the books say the goods arrived.

## Where the warehouse comes from

- `destination_warehouse_id` — **new** column on `purchase_orders`. Set on
  create (optional). Editable while the order is in Draft. Frozen once the
  order moves past Draft.
- `arrived_warehouse_id` — existing column, already editable on the foreign
  flow's `arriveAtWarehouse` transition. Acts as an override.

**Resolution at the final accounting step** (foreign `completeOrder`, local
`payLocal`):

```
warehouse = arrived_warehouse_id ?? destination_warehouse_id
```

If both are `null`, the system refuses with `422 MISSING_DESTINATION_WAREHOUSE`.

**Operating-unit consistency check** — the resolved warehouse must belong to
`order.operating_unit_id`; otherwise `422 CROSS_UNIT_WAREHOUSE`.

## When StockLots are created

At the **final accounting step**, in the same transaction as the journal:

- **Foreign flow** — `PurchaseOrderStateService::completeOrder()`: after
  `postCompletionJournal()` posts the inventory journal, iterate
  `order->items()->with('inventoryItem')->get()` and call
  `StockLotService::intake([...])` per line item with
  `source='import_receipt'` (no-journal path; the journal was already posted).
  `unit_cost` is the per-unit landed value already computed in
  `postCompletionJournal()`'s `perUnit`.

- **Local flow** — `PurchaseOrderStateService::payLocal()`: after the
  supplier-advance / payment-source journal posts, iterate
  `order->items` with `received_quantity > 0` and call
  `StockLotService::intake([...])` with `source='purchase_credit'`.
  `unit_cost = line.unit_price`, `quantity = line.received_quantity`
  (partial receipts only stock what was received).

**Reuse `StockLotService::intake()`** — this keeps journal posting, INV-06
movement recording, and the new `INVENTORY_ACCOUNT_NOT_LINKED` guard
(from `feature/coa-inventory-items`) in one place.

## Cost derivation

- **Foreign:** per-unit landed value = `(bookedCost + landedCostTotal) / receivedQty`.
  Already computed as `$perUnit` in `postCompletionJournal()`.
- **Local:** `purchase_order_items.unit_price` directly.

## Lot number

`"PO-{$order->id_first_8}-{$item->id_first_8}"` — UUIDs truncated, collision-free
with the `stock_lots.lot_number` unique constraint.

## Files

### `fine_backend`

- **NEW** `database/migrations/2026_09_29_xxxxxx_add_destination_warehouse_id_to_purchase_orders_table.php`
- **EDIT** `app/Models/PurchaseOrder.php` — fillable + relation
- **EDIT** `app/Http/Requests/v1/StorePurchaseOrderRequest.php` — `destination_warehouse_id` (sometimes/nullable, OU check)
- **EDIT** `app/Http/Requests/v1/UpdatePurchaseOrderRequest.php` — same
- **EDIT** `app/Http/Controllers/Api/v1/PurchaseOrderController.php` — persist, eager-load
- **EDIT** `app/Services/PurchaseOrderStateService.php` — inject `StockLotService`, route lots at `completeOrder` / `payLocal`
- **EDIT** `app/Http/Resources/v1/PurchaseOrderResource.php` — include `destination_warehouse`
- **NEW** `tests/Feature/PurchaseOrderStockLotTest.php` — happy paths + missing warehouse + cross-unit + partial receipt + COA guard propagation
- **NEW** `PROCUREMENT_PLAN.md` (root)

### `fine-desktop`

- **EDIT** `src/api/endpoints/procurement.ts` — add `destination_warehouse_id` to `CreatePurchaseOrderPayload`
- **EDIT** `src/pages/procurement/PurchaseOrdersPage.tsx` — destination picker on create modal; chip on list/detail; hint on foreign `arriveAtWarehouse`
- **NEW** `PROCUREMENT_PLAN.md` (root)

## Endpoints / payloads

### `POST /api/v1/purchase-orders` (new field)

```json
{
  "operating_unit_id": "...",
  "supplier_id": "...",
  "kind": "local|foreign",
  "currency": "USD|LYD",
  "items": [...],
  "destination_warehouse_id": "uuid (optional)"
}
```

Validation: when supplied, the warehouse's `operating_unit_id` must equal
`data.operating_unit_id`.

### `PUT /api/v1/purchase-orders/{id}` (new field, Draft only)

Same shape. Same OU check.

### Response includes

```json
{
  "...": "...",
  "destination_warehouse": { "id": "...", "code": "...", "name": "...", "operating_unit_id": "..." }
}
```

## Verification

- `vendor/bin/pint --dirty --format agent`
- `php artisan test --compact --filter=PurchaseOrder`
- `npx tsc --noEmit`
- `npm run lint` (0 errors)

## Commits

### `fine_backend`

1. `chore(procurement): scaffold fix/procurement-inventory planning doc`
2. `feat(procurement): route purchase orders into the chosen warehouse at completion`
3. `test(procurement): cover purchase-order → stock-lot routing`

### `fine-desktop`

1. `chore(procurement): scaffold fix/procurement-inventory planning doc`
2. `feat(procurement): pick destination warehouse on create + surface it on order detail`

## Known risk (deploy ordering)

This change hard-fails `completeOrder` / `payLocal` unless every line item
has the `purchases` per-event COA override set. That override table is being
introduced on `feature/coa-inventory-items` (not yet merged into `COA`).
After merging both branches into `COA`, operators must map every active
inventory item's `purchases` event before any purchase can complete. This
must be called out in the PR description and HANDOFF.
