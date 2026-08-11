# Foam Block Identity & Production Batches Design Specification

**Date**: 2026-08-10
**Status**: Implemented 2026-08-10 — 18 feature tests in `tests/Feature/FoamBlockIdentityTest.php`. Remaining §9 items affect form defaults and field sizing only.
**Scope**: Introduce the `production_batches` table, resolve the dangling `stock_lots.production_batch_id` reference, define serialized foam-block identity (`{sequence}-{pressure}-{operation}`), store measured pressure per lot, and specify collision-safe sequence generation for bulk block registration.

**Source document**: `تقرير انتاج` (production report), the paper sheet the foam floor fills per run. Field mapping in §6.

---

## 1. Problem Statement

Foam blocks are serialized — every block is individually labeled and carries its own code of the form `3-35-191` (3rd block of the run, pressure 35, operation 191). Nothing in the current schema can produce that code:

1. **`production_batches` does not exist.** `stock_lots.production_batch_id` is a bare `uuid` column with **no foreign key and no table behind it** (`2026_08_08_123500_create_stock_lots_table.php:30`). `StockLotController` validates it as `['nullable', 'uuid']` with no `exists:` rule, so any arbitrary UUID can be written today. The table is designed in `docs/erd.md` §6 and scheduled for Phase 04, which has not started.
2. **Two of the three code components live on the batch** — operation number and pressure. Until batches are real, the code cannot be generated.
3. **No sequence tracking.** Nothing records a block's ordinal position within its run.
4. **Existing lot-number generation is unsafe.** `StockLotService::processCutRemnant()` builds remnant numbers as `$parentLot->lot_number.'-R'.time()` — second-resolution, so two remnants created in the same second collide against `lot_number`'s global unique index.

---

## 2. Identity Model

### 2.1 The code is a label, not an identity

Three distinct things, deliberately separated:

| Concern | Column | Rule |
|---|---|---|
| Identity | `stock_lots.id` (UUID) | What every FK points at. Immutable. |
| Human label | `stock_lots.lot_number` | `003-35-191`. Printed on the physical block. Globally unique. |
| Queryable data | component columns | Batch FK, sequence, pressure — stored separately |

**Every component of the code is also stored as a real column or relation.** The string is generated once at creation for humans and is never parsed to recover data.

This matters concretely:

- Querying "all pressure-35 blocks" via `LIKE '%-35-%'` would also match operation 35 and sequence 35. Component columns make the query exact and indexable.
- If a value is corrected after labels are printed, a parsed-identity system forces the ID to change — invalidating labels already stuck on blocks sitting in the yard. With the ID as an inert label, the correction lands in the batch record and the printed code stays valid as a historical reference.

### 2.2 Format

```
   003   -   35   -   191
    │         │         │
    │         │         └── operation number  (production_batches.operation_number)
    │         └──────────── pressure, measured (stock_lots.pressure)
    └────────────────────── sequence in batch  (stock_lots.sequence_in_batch)
```

Sequence is zero-padded to 3 digits so lexical sort matches numeric sort in exports and file listings. Operation number is a plain integer with no prefix (confirmed against the production report: `رقم التشغيلة / 191`), so hyphens stay unambiguous.

### 2.3 A foam block is a stock lot — nothing more

`docs/erd.md` §6 proposes a separate `foam_blocks` table carrying `length_m`, `width_m`, `height_m`, `volume_m3`, and `grade`, linked to `stock_lots` by `stock_lot_id`. **Every one of those columns already exists on `stock_lots`.**

This spec **drops `foam_blocks`**. A foam block is a `stock_lot` with `item_type = 'foam_block'`, `quantity = 1`, and a non-null `production_batch_id`. Keeping both tables would mean two rows per block held in sync forever with no capability gained.

---

## 3. `production_batches` Table

```
id                     uuid           PK
operating_unit_id      uuid           FK -> operating_units   cascadeOnDelete
requested_by_client_id uuid           FK -> clients  nullable  (null = stock run)
operation_number       unsignedBigInt UNIQUE
bun_width_m            decimal(8,3)
formula_params         json           nullable
status                 string(50)     default 'planned'
material_cost          decimal(15,4)  default 0
scrap_volume_m3        decimal(10,4)  default 0
next_sequence          unsignedInteger default 1
record_version         unsignedInteger default 1
timestamps
softDeletes

unique  (operation_number)
index   (operating_unit_id, status)
```

Notes:

- **`operation_number` is entered manually by the operator** — it is *not* system-assigned. It is incremental by convention, globally unique, never scoped per unit, never reset, never reused. The database enforces uniqueness; everything else is validation (§3.1).
  - Uniqueness checks must count **soft-deleted batches** too. A soft-deleted batch may have labeled blocks in the yard, so its number is still in physical circulation. Laravel's `unique:` rule and the database index both include soft-deleted rows by default — do not add a `whereNull('deleted_at')` exclusion.
- **Pressure is deliberately absent here** — it is a measured per-block value and lives on `stock_lots` (§4).
- **`bun_width_m`** is a real column because it is constant for the whole run — it is a machine setting (`العرض` reads `2.4` on every row of the observed sheet), not per-block data. Entered once, applied to every block.
- **`scrap_volume_m3`** captures non-saleable output so material yield reconciles (§5.5).
- **`formula_params`** holds the remaining run parameters recorded on the production report: density band (`الكثافة`, e.g. `"12-14"` — a band, not a scalar, so it stays JSON), cure time in minutes (`الزمن`), conveyor speed (`سرعة السير`), and chemical formula reference.
- **The report number (`تقرير انتاج رقم`) is deliberately not stored.** It is a separate paper-side counter with no operational meaning here.

### 3.1 Operator entry: validation and mutability

Because a human types the operation number and it ends up printed on physical blocks, a typo is the most likely failure mode in the whole module. Transposing `191` into `119` produces a number that is plausible, unused, and silently accepted.

**Validation on entry — two tiers:**

| Tier | Rule | Behaviour |
|---|---|---|
| Hard | number already used | Reject. Error names the conflicting batch and its date, e.g. *"Operation 191 already belongs to a batch created 2025-10-19."* |
| Soft | number ≠ previous highest + 1 | Warn and require explicit confirmation: *"Last operation was 190. You entered 199 — confirm?"* |

The soft tier is what catches transpositions. It must **warn, not block** — legitimate gaps happen, and a hard rule would train operators to work around the system. The entry form should also display the last-used number as a hint, which is the cheapest error prevention available.

**Mutability window:**

`operation_number` is freely editable while the batch has **no registered blocks**. Once the first block is registered, the number is **immutable** — physical labels now exist referencing it, and changing the record would put the label and the database in disagreement.

### 3.2 Operating unit is taken from context only

`operating_unit_id` is **not accepted in the request body**. It is read solely from `CurrentUnitContext`, which `ScopeOperatingUnit` has already validated against the caller's roles. Accepting it as input would let a unit-scoped user create a batch inside a unit they cannot otherwise reach, since the body value would win over the validated header.

Company-wide roles (Owner) carry no unit of their own and the middleware lets them through with no context set. They must name a unit via `X-Operating-Unit-ID`; without it the request is rejected with **422 `OPERATING_UNIT_REQUIRED`**. Falling through would write `null` into a `NOT NULL` column and surface as a 500 the operator cannot act on.

### 3.3 Status enum

Per `docs/erd.md` and `DatabaseSeeder`: `planned`, `configured`, `running`, `consumed`, `curing`, `ready_for_grading`, `graded`, `closed`. Backed by a `ProductionBatchStatus` enum class, following the `ImportOrderStatus` precedent.

**No failure state is included.** Faulted runs are discarded on paper before they reach the system (§10), so the system never sees an aborted batch.

---

## 4. Pressure Is Per-Lot and Measured

Pressure is **measured from the cured foam and entered manually** — it is an observation, not a run setting. It therefore lives on the lot:

```
stock_lots.pressure   unsignedInteger  nullable
```

Nullable because non-foam lots have no pressure.

### 4.1 Why per-lot rather than per-batch

An earlier draft put pressure on `production_batches`, reasoning that one pour is one formula. That was wrong, and the storage decision is asymmetric:

| Reality | Stored per-lot | Stored per-batch |
|---|---|---|
| One reading per run | works — form copies the value across the group | works |
| Readings vary per block | works | **cannot be represented** — data loss, needs migration |

Per-lot is correct under both readings; per-batch is correct under only one. Since the value is *measured*, variation is the default expectation, and the cost of per-lot storage when a single reading covers a run is one repeated integer per row.

This also means **pressure is not indexed on the batch** — cutter selection filters on `stock_lots.pressure` directly, alongside the existing `volume_m3` and `grade` filters in `StockLotService::getAvailableForCutting()`.

### 4.2 Relationship to the attribute system

`2026-08-10-product-attributes-many-to-many-design.md` treats pressure as a per-lot descriptive attribute in `attribute_values` JSON. That instinct was right about the *grain* but wrong about the *storage*: because pressure is embedded in the block code and drives cutter selection queries, it is promoted to a real indexed column rather than a JSON key.

Pressure is therefore **not** registered as an `inventory_attribute_definition` for foam blocks. The JSON attribute system remains correct for genuinely free-form descriptive values (colour, finish notes) that no query or identifier depends on.

### 4.3 Registration happens after grading

The block code contains a measured value, so **a block has no code until it has been measured.**

**Confirmed floor sequence:** pour → cure → grade → enter. Blocks are keyed into the system **once, after grading**, from the completed paper report — dimensions and pressure entered together in a single pass, with `lot_number` generated at creation.

Two things follow, and both simplify the build:

- **`lot_number` is `NOT NULL` and assigned at insert.** There is no provisional-identity state, no second-pass back-fill, and no window where a lot exists without a code.
- **Blocks are untracked while curing**, by design. The system's record of a run begins at grading. Anything needing visibility into uncured stock is out of scope for this spec.

---

## 5. Sequence Generation

### 5.1 Bulk entry, individual records

The production report records blocks as **dimension groups with counts** — one row reading `2.4 × 2 × 0.8, count 25`. But every block is individually labeled, so the sheet's grouping is a reporting convenience, not the grain of the data.

The entry form therefore mirrors the sheet (dimension group + count) and the system **expands each group into N individual lots**, each receiving its own sequence and its own printable code. A run of 36 blocks across 9 dimension groups produces 36 stock lots numbered `001`–`036`.

### 5.2 Algorithm

Registering a group of `N` blocks runs inside one `DB::transaction`:

1. `SELECT ... FOR UPDATE` the batch row (`lockForUpdate()`).
2. Read `next_sequence`, reserve the block `[next_sequence, next_sequence + N - 1]`.
3. Set `next_sequence += N` and persist.
4. For each block, compose `lot_number` as `sprintf('%03d-%d-%d', $sequence, $block->pressure, $batch->operation_number)`.
5. Insert all `N` stock lots.

The whole group reserves its range in a single locked pass — not one lock per block — so a 25-block row is one round trip, and a concurrent registration on the same batch cannot interleave into the reserved range.

A counter column is used rather than `MAX(sequence_in_batch) + 1` so the write does not depend on scanning sibling rows, and so sequence numbers are never reused after a block is deleted.

### 5.5 Scrap rows consume no sequence

`فاصل` (separator) and `بداية` (start piece) are scrap. They are **not** serialized: no sequence number, no block code, no stock lot, and they must not advance `next_sequence` — otherwise the printed codes would skip numbers with nothing to account for the gap.

Their volume is still recorded, on `production_batches.scrap_volume_m3`. This is not optional bookkeeping: on the observed sheet the two scrap rows account for `4.8 + 2.4 = 7.2 m³` of the `135.657 m³` printed total. Dropping them would leave recorded output at `128.457 m³` against unchanged chemical consumption, silently corrupting every yield and material-cost-per-m³ figure derived from the run.

So the entry form accepts scrap rows as dimension groups like any other, but routes their volume to the batch total instead of creating inventory.

### 5.3 Constraints as backstop

```
stock_lots: unique (production_batch_id, sequence_in_batch)
stock_lots: lot_number already unique (existing)
```

The application must never rely on the lock alone. Both constraints exist so a concurrency bug surfaces as an insert failure rather than two blocks silently sharing a code.

**SQLite note:** `lockForUpdate()` is a no-op on SQLite (dev), which serializes writes at the database level anyway. The unique constraints are what actually guarantee correctness in both environments, and tests must assert them directly.

### 5.4 New column

```
stock_lots.sequence_in_batch   unsignedInteger  nullable
stock_lots.pressure            unsignedInteger  nullable
```

Both nullable because non-foam lots (chemicals, furniture, packaging) have neither a batch sequence nor a pressure.

`production_batch_id` also gains the foreign key it never had, and `StockLotController` gains the matching `exists:production_batches,id` validation rule.

---

## 6. Source Document Mapping

Field-by-field mapping from the paper `تقرير انتاج` to schema:

### 6.1 Header

| Sheet field | Maps to |
|---|---|
| `رقم التشغيلة` (operation no.) | `production_batches.operation_number` |
| `تقرير انتاج رقم` (report no.) | **not stored** — paper-side counter, no operational meaning |
| `التاريخ` (date) | `production_batches.created_at` |
| `الكثافة` (density band, e.g. `12-14`) | `formula_params.density_band` — a band, stays JSON |
| `الزمن` (time, minutes) | `formula_params.cure_time_minutes` |
| `سرعة السير` (conveyor speed) | `formula_params.conveyor_speed` |

### 6.2 Output table (`المنتج`)

| Sheet column | Maps to |
|---|---|
| `الصنف` — `بلوكة` | serialized block → one stock lot per unit (§5.1) |
| `الصنف` — `فاصل` / `بداية` | scrap → volume only, no lot, no sequence (§5.5) |
| `اللون` (color) | per-lot, `stock_lots.attribute_values.color` |
| `العرض / م` (width) | `production_batches.bun_width_m` — **constant per run** (machine setting), entered once, copied to each lot's `width_m` |
| `الطول / م` (length) | `stock_lots.length_m` |
| `الارتفاع / م` (height) | `stock_lots.height_m` |
| `الحجم / م3` (volume) | `stock_lots.volume_m3` — **computed**, never entered |
| `العدد` (count) | expansion factor (§5.1) — not stored; becomes N lots |
| `متر مكعب` (group total m³) | derived at report time — not stored |
| `ملاحظات` (notes) | per-lot note / grade |
| *(not on the sheet)* | `stock_lots.pressure` — measured at grading, entered alongside the sheet data (§4.3) |

Arithmetic verified against a real sheet: `2.4 × 2 × 0.8 = 3.84`, `× 25 = 96`, and the nine group totals sum to the printed `135.657 m³`. The volume column is a pure product of the three dimensions and must be computed, never accepted as input.

### 6.3 Consumption table (`المواد المستهلكة`)

Belongs to Phase 04 consumption reporting (§10), but the sheet confirms the shape:

- Materials carry supplier/product codes (`A33`, `T-9`, `JC-7858`, `sabec`) that map to `inventory_items.sku`.
- Consumption is by **weight in kg**, matching the tank model.
- **Zero-consumption rows are valid data, not missing data** — colors and carbonate routinely read `0`. The formula lists optional inputs; a `0` line must be storable and distinguishable from an unrecorded line.
- **Paper is consumed by metre with a kg-per-metre factor** (`0.25 كجم للمتر`, `0.27 كجم للمتر`). This is the same dual-UOM conversion as `container_capacity` in `2026-08-10-barrel-tank-consumption-cascade-design.md` — a second instance, confirming the conversion factor should be a general product-level concept rather than a barrel special case.

---

## 7. Remnant Numbering

`processCutRemnant()` currently generates `{parent}-R{unix_timestamp}`, which collides for two remnants in the same second and yields codes like `003-35-191-R1754831234`.

Replacement: remnants take a per-parent revision counter — `003-35-191-R1`, `-R2` — assigned under the same locked-transaction pattern as §5, backed by a unique constraint. A `remnant_of_lot_id` self-FK on `stock_lots` records the parentage as data rather than leaving it encoded in the string.

---

## 8. Tests

New `tests/Feature/FoamBlockIdentityTest.php`:

- block code renders as `003-35-191` with correct zero-padding
- a 25-count group expands to 25 lots with consecutive sequences
- multiple groups in one batch continue the sequence rather than restarting
- a scrap group creates no stock lot and does not advance `next_sequence`
- a scrap group's volume lands on `production_batches.scrap_volume_m3`
- block volumes plus scrap volume reconcile to the run's printed total
- `volume_m3` is computed from dimensions and ignores any client-supplied value
- sequences are independent across concurrent batches
- concurrent registration in one batch never yields duplicate `sequence_in_batch` (assert the unique constraint fires)
- `lot_number` collision is rejected at the database level
- deleting a block does not cause its sequence to be reused
- `production_batch_id` now rejects a non-existent UUID (`exists:` rule added)
- a duplicate `operation_number` is rejected with an error naming the conflicting batch
- a duplicate against a **soft-deleted** batch is also rejected
- a non-sequential `operation_number` is accepted but flagged for confirmation, not blocked
- `operation_number` is editable while the batch has no blocks, and rejected once one is registered
- two blocks in one batch can carry different measured pressures, and each code reflects its own
- a block registered without a pressure is rejected — no code can be composed without it
- pressure is written to the `stock_lots.pressure` column, not to `attribute_values`
- cutter selection filters on `stock_lots.pressure` together with `volume_m3` and `grade`
- remnant numbering yields `-R1`, `-R2` without collision in the same second

---

## 9. Open Decisions

*No blocking decisions remain. The items below affect form defaults or field sizing only, and none require a migration to resolve later.*

1. **Is one pressure reading taken per run, or per block?** The schema handles both (§4.1). Decides only whether the entry form offers "apply to all blocks in this group" as the default or prompts per block.
2. **Pressure precision** — spec assumes an integer. If half-values occur, either the column becomes decimal and the code renders `35_5`, or the code uses a rounded display value while the column stays exact.
3. **Zero-padding width** — spec uses 3 digits (max 999 blocks per run). The observed run produced 36. Confirm no run exceeds 999.
4. **Is scrap ever shredded into `byproduct_fill`?** Treated as pure waste here. But `byproduct_fill` already exists in the `item_type` enum, `processCutRemnant()` already produces it from cutter offcuts, and the company manufactures pillows — so foam scrap becoming fill stock is plausible. If it happens, §5.5 changes from "record volume" to "record volume **and** credit fill inventory". Not blocking; the volume is captured either way.

**Resolved:** blocks are entered after grading, so `lot_number` is never provisional (§4.3); pressure is measured, manually entered, and stored per-lot (§4); report number is not stored (§3); `فاصل`/`بداية` are scrap, not SKUs (§5.5); operation number is operator-entered, numeric, never reused (§3.1); faulted runs never reach the system (§10).

---

## 10. Out of Scope

- **Faulted runs.** Defective pours are discarded on paper before data entry, so the system never records them. No abort status, no void path, no renumbering. *(If defective blocks ever do get entered as saleable seconds, the existing `grade` enum — `acceptable_variant`, `defective_usable`, `reject` — already covers it with no schema change.)*
- **The full Phase 04 pipeline** — consumption reports, consumption lines, grading workflow, curing state transitions. This spec covers only batch identity and block numbering, the prerequisites for anything else in that phase.
- **Cutter work orders consuming blocks** (Phase 05).

---

## 11. Frontend (`fine-desktop`)

Replaces the `Manufacturing` placeholder route, which was previously a `PlaceholderPage` stub.

| File | Role |
|---|---|
| `src/api/endpoints/production.ts` | Types + `productionApi`; `isNonSequentialError` / `apiErrorPayload` guards |
| `src/hooks/useProduction.ts` | TanStack Query hooks for batches, blocks, registration |
| `src/hooks/useWarehouses.ts` | Warehouse list for the block-registration selector |
| `src/pages/manufacturing/ProductionBatchesPage.tsx` | Batch list + create modal |
| `src/pages/manufacturing/BatchBlocksPage.tsx` | Report-shaped block registration + registered-block list |
| `src/routes/ManufacturingRoutes.tsx` | `/manufacturing/batches`, `/manufacturing/batches/:batchId` |

### 11.1 The registration form mirrors the paper report

Rows are entered as the sheet records them — `length × height`, a count, and a pressure — not one row per block. Bun width is **not** an input; it is displayed from the batch and multiplied in, because it is a machine setting. Volume is computed live per row and per run (`width × length × height × count`), with a footer total, so the operator can reconcile against the printed `اجمالي المنتج` figure before submitting.

Each row carries a `kind` of `block` or `scrap`. Scrap rows disable the pressure, grade, colour and cost inputs, and their volume is shown separately in the footer — reinforcing at the point of entry that scrap is measured but not serialized.

### 11.2 The non-sequential warning contract

This is the one place the UI must honour a specific API contract or the feature becomes unusable.

`POST /production-batches` returns **422** with `code: NON_SEQUENTIAL_OPERATION_NUMBER` plus `expected_operation_number` and `entered_operation_number` whenever the entered number is not `previous + 1`. This is a **warning, not a rejection**.

`ProductionBatchesPage` therefore:

1. Detects that specific code via `isNonSequentialError` before any generic error handling.
2. Renders an inline amber panel naming both numbers — *"Last operation was 190, so 191 was expected — you entered 199."*
3. Offers a **"Use 199 anyway"** button that resubmits with `confirm_non_sequential: true`.

Rendering this 422 as a generic validation error would wall operators off from every legitimate gap. The create modal also pre-fills the expected number and shows it as a hint, so the warning path is rare by design.

### 11.3 Supporting endpoints added for the UI

Two small backend additions were required and are covered by tests:

- **`GET /warehouses`** (`WarehouseController@index`) — none existed; block registration needs a `warehouse_id`. Scoped automatically by the `BelongsToOperatingUnit` global scope.
- **`production_batch_id` filter** on `GET /stock-lots` — needed to list a batch's registered blocks.
