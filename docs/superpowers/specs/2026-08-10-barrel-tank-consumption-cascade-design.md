# Barrel → Tank Consumption Cascade Design Specification

**Date**: 2026-08-10
**Status**: Draft — pending decisions in §8
**Scope**: Introduce container capacity on products, a partial-consumption operation on stock lots, automatic empty-container recovery, and rework `TankStockService::refill()` from a one-sided entry into a balanced two-leg transfer sourced from real stock.

---

## 1. Problem Statement

Three defects in the current inventory model, all visible in the chemical-barrel flow:

1. **Tank refill creates stock from nothing.** `TankStockService::refill()` accepts an operator-typed `refillQty` and `refillUnitCost`, credits the tank, and writes a single `receipt` movement. No stock lot is ever decremented. Pouring a 40 L barrel into a tank leaves the barrel still showing 40 L.
2. **No container capacity exists.** `inventory_items.primary_uom` / `secondary_uom` are free-text strings (`barrel`, `liter`). There is nowhere to record that a barrel of Chemical A holds 40 L while Chemical B's holds 20 L, so no container arithmetic is possible.
3. **No partial-consumption operation.** `StockLotService` only offers `processCutRemnant()`, which marks an entire lot `consumed`. Drawing 15 L from a 40 L barrel — leaving the barrel physically present but partly empty — cannot be expressed.

A fourth, related gap: `barrel` and `pallet` are already valid `item_type` values, so empty containers are modelled as inventory items, but nothing ever produces them when a container drains.

---

## 2. Core Model Decisions

### 2.1 `quantity` and `container_quantity` are both stored, never derived

A lot carries two independent measures:

- `quantity` — measure UOM on hand (e.g. `185.0000` L)
- `container_quantity` — physical container count (e.g. `5` barrels)

These **drift apart on partial consumption** and that drift is correct. Drawing 15 L from a lot of 5 full 40 L barrels yields 185 L across *still 5* physical barrels — one of them open with 25 L in it. Deriving `container_quantity` as `quantity / capacity` would report `4.625` barrels, which is meaningless: the open barrel still occupies floor space and still has to be counted.

The automated cascade **updates** `container_quantity` using `ceil(quantity / capacity)` as its best estimate, but the column remains authoritative and manually correctable. Physical counts, damaged containers, and partial supplier deliveries can all override it, and those corrections must stick.

`ceil(quantity / capacity)` is therefore used as a **reconciliation check**, not a source of truth:

| Stored | Expected `ceil(q/c)` | Verdict |
|---|---|---|
| 5 barrels / 185 L | 5 | consistent |
| 8 barrels / 185 L | 5 | flag — miscount or partially-filled containers |

### 2.2 Structural quantity vs. descriptive attributes

Container capacity, dimensions, and volume drive inventory and costing math. They are **not** user-defined attributes and must not be expressed through `inventory_attribute_definitions`. Descriptive attributes (pressure, density, viscosity, purity) describe *which variant* a lot is and remain in `stock_lots.attribute_values`.

This spec touches only the structural side. The broader "tracking mode / measurement profile" question (see §8.4) is deliberately left open — nothing here presumes its outcome, because `container_capacity` is nullable and simply inert for items that do not use containers.

---

## 3. Schema Changes

### 3.1 `inventory_items` — new columns

```
container_capacity        decimal(15,4)  nullable
empty_container_item_id   uuid           nullable  FK -> inventory_items.id  nullOnDelete
```

- `container_capacity` — how much `secondary_uom` one `primary_uom` holds. `40.0000` for a 40 L barrel. `null` means the item has no container semantics; all container math is skipped for it.
- `empty_container_item_id` — the inventory item representing this product's *empty* container (an item of type `barrel` or `pallet`). `null` means empties are not recovered for this product.

### 3.2 `stock_lots` — no change

`quantity` and `container_quantity` already exist (added `2026_08_09_140003`). Nothing further is required.

---

## 4. Consumption Cascade

### 4.1 Algorithm

```
capacity        = item.container_capacity
containersBefore = lot.container_quantity
quantityAfter   = lot.quantity - drawQty

containersAfter = capacity > 0
                ? ceil(quantityAfter / capacity)
                : containersBefore

emptied         = containersBefore - containersAfter
```

Then, inside a single `DB::transaction`:

1. Update the lot: `quantity = quantityAfter`, `container_quantity = containersAfter`, bump `record_version` (optimistic lock).
2. If `quantityAfter == 0`, set `status = 'consumed'`.
3. If `emptied > 0` and `empty_container_item_id` is set, credit `emptied` units to the empty-container item.
4. Write the movements in §4.3.

### 4.2 Worked examples

Lot: 5 barrels, 200 L, capacity 40 L.

| Draw | quantity after | containers after | emptied | Note |
|---|---|---|---|---|
| 15 L | 185 | `ceil(185/40)` = 5 | 0 | barrel opened, still present |
| 25 L more | 160 | `ceil(160/40)` = 4 | 1 | open barrel drained → +1 empty |
| 80 L (2 whole) | 80 | 2 | 2 | +2 empties |
| 200 L (all) | 0 | 0 | 5 | lot consumed, +5 empties |

### 4.3 Movements written

A refill is a **transfer**, so both legs are recorded — this is what makes the ledger balance:

| Leg | Type | Item | Delta |
|---|---|---|---|
| 1 | `issue` | chemical (from lot) | `-drawQty` |
| 2 | `receipt` | chemical (into tank) | `+drawQty` |
| 3 | `receipt` | empty container item | `+emptied` (omitted when `emptied == 0`) |

Each carries `reference_document_type` and `reference_id` linking back to the source lot.

### 4.4 Costing

`unit_cost` is read from the **source lot**, never from operator input. That cost feeds the existing weighted-average calculation in `TankStockService`, which is otherwise unchanged. This removes a whole class of costing error: the operator can no longer type a number that disagrees with what was actually paid.

---

## 5. Service Layer

### 5.1 `StockLotService::drawFromLot()`

New method implementing §4.1. Generic — usable for any partial consumption, not only tank refills.

```php
public function drawFromLot(
    StockLot $lot,
    float $drawQuantity,
    string $reason,
    ?string $referenceId = null,
): array // ['lot' => StockLot, 'emptied' => int, 'movements' => array]
```

Guards (all throw before any write):

- `drawQuantity > 0`
- `drawQuantity <= lot.quantity`
- `lot.status === 'available'`
- lot's warehouse belongs to the current operating unit

### 5.2 `TankStockService::refillFromLot()`

Replaces the manual path as the primary entry point.

```php
public function refillFromLot(
    StockLot $sourceLot,
    float $drawQuantity,
    ?string $referenceId = null,
): TankStock
```

- Delegates the decrement to `drawFromLot()`
- Derives `chemical_inventory_item_id` and `operating_unit_id` from the lot and its warehouse
- Derives unit cost from `sourceLot->unit_cost`
- Retains the existing WAC recomputation and row-level `lockForUpdate()`

The existing `refill()` is **kept but demoted** to an explicit adjustment path for opening balances and corrections, gated behind a required `reason` and a distinct movement reason code (`tank_adjustment`, not `tank_refill`) so unsourced credits are auditable rather than indistinguishable from real receipts.

---

## 6. API Changes

### 6.1 `POST /api/v1/tank-stocks/refill` — reworked payload

```jsonc
{
  "source_stock_lot_id": "uuid",   // required — replaces chemical_inventory_item_id
  "draw_quantity":       25.5,     // measure UOM; XOR with draw_containers
  "draw_containers":     2,        // whole containers; expands to n * container_capacity
  "reference_id":        "uuid"    // optional
}
```

- `refill_unit_cost` is **removed** — derived from the lot.
- `chemical_inventory_item_id` and `operating_unit_id` are **removed** — derived from the lot.
- Exactly one of `draw_quantity` / `draw_containers` is required. `draw_containers` is the common case ("I poured 2 barrels in") and requires `container_capacity` to be set.

### 6.2 `POST /api/v1/stock-lots/{id}/draw` — new

Generic partial consumption for cases not destined for a tank. Same guards, same cascade, caller-supplied `reason`.

### 6.3 Lot selection

The client sends an explicit `source_stock_lot_id`. The UI pre-selects the FIFO candidate (oldest available lot of that chemical in the unit) but the operator can override — they physically walk up to a specific barrel, and the record should match the barrel they actually grabbed. See §8.1.

---

## 7. Tests

To be added to `tests/Feature/InventoryManagementTest.php` or a new `TankRefillCascadeTest`:

- partial draw leaves `container_quantity` unchanged, no empty credited
- draw that crosses a container boundary credits exactly one empty
- draw of N whole containers credits N empties
- full draw zeroes the lot, sets `status = 'consumed'`, credits all empties
- draw exceeding `lot.quantity` is rejected, no partial writes persist
- draw against a non-`available` lot is rejected
- tank WAC uses the source lot's `unit_cost`, not any client-supplied value
- item with `container_capacity = null` skips container math and credits no empties
- item with `empty_container_item_id = null` credits no empties but still decrements correctly
- concurrent draws on one lot — second fails on `record_version` rather than overwriting
- all three movement legs are written with correct signs and reference back to the lot

---

## 8. Open Decisions

1. **Lot picking** — spec assumes *operator picks, FIFO pre-selected*. Alternative is silent FIFO auto-pick (fewer clicks, but the system guesses which physical barrel was used and costing follows the guess). **Recommended: operator picks.**
2. **Empty container condition** — are recovered empties always `grade = 'standard'`, or does the operator flag damaged ones on return? Spec currently assumes standard.
3. **Empty container value** — do empties carry a cost/deposit value, or enter at zero cost? Spec currently assumes zero cost. This matters if barrels are returned to suppliers for credit.
4. **Tracking mode** *(separate, larger question)* — whether products should carry an explicit measurement profile (`simple` / `container` / `dimensional`) governing which structural fields apply. Nothing in this spec depends on the answer, but it would formalise when `container_capacity` is required rather than optional.

---

## 9. Out of Scope

- **Recipe/BOM-driven consumption** — a foam work order auto-issuing chemicals from the tank per formula. This is the natural next level of automation and `WorkOrder::complete` already establishes the atomic-movement pattern, but it belongs to the Foam Manufacturing phase.
- **Sensor / foam machine integration** — tank level telemetry. On the risk register, but too many unknowns to shape schema around now.
