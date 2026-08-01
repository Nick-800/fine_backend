# Phase 04: Foam Manufacturing

> **Duration Estimate:** 4–5 weeks  
> **Team Size:** 2 backend engineers, 1 frontend engineer  
> **Dependencies:** Phase 01 (Foundation), Phase 03 (Inventory)

---

## 4.1 Objective

Build the foam batch production module: from batch creation and machine configuration, through chemical consumption tracking with weighted-average tank costing, to block slicing, curing, individual grading, and entry into Foam unit inventory as serialized StockLots. Every batch automatically posts its material cost to the accounting ledger upon closure.

### Sync Tier Classification
* **Tier 1 (Full Offline-First Read & Write):** Batch creation, chemical consumption entry, block output logging, and block grading. Saved locally to SQLite outbox and pushed to API on connection.

---

## 4.2 Deliverables

| # | Deliverable | Acceptance Criteria |
|---|-------------|---------------------|
| 1 | Production Batch lifecycle | Full state machine (Planned → Configured → Running → Consumed → Curing → ReadyForGrading → Graded → Closed) |
| 2 | Machine configuration | Formula params: length, width (max 2.4m hard cap), height, pressure, color, chemical formula |
| 3 | Consumption Report | Auto-created on batch run completion; per-chemical quantities from machine report |
| 4 | Tank stock integration | Consumption draws from TankStock at current weighted-avg cost; blocks batch if insufficient |
| 5 | Block creation & grading | ~138 blocks per batch typical; each graded Standard / Acceptable-variant / Defective-usable / Reject |
| 6 | Batch cost apportionment | Material cost split per block by volume ratio: `block_cost = batch_material_cost × (block.volume / batch.total_volume)` |
| 7 | Serialized inventory entry | Each graded block becomes a `StockLot` with unique lot number, dimensions, grade, cost |
| 8 | Auto journal posting | On Close: WIP → Finished Goods journal entry posted to Accounting |
| 9 | Unit client: Batch management | Batch creation, status tracking, grading UI (grid of ~138 blocks) |
| 10 | Unit client: Machine operator view | Simple screen for operator to start run, enter consumption, mark complete |

---

## 4.3 Database Migrations (This Phase)

- `production_batches`
- `consumption_reports`
- `consumption_lines`
- `foam_blocks`

*(Note: `tank_stocks` created in Phase 03; `stock_lots` created in Phase 03)*

---

## 4.4 State Machine: Production Batch

```
Planned ──[Owner/Foam Manager creates batch]──► Configured

Configured ──[Operator sets formula on machine]──► Running
  • Width capped at 2.4m (hard validation)
  • Chemical formula selected

Running ──[Machine completes run]──► Consumed
  • System creates ConsumptionReport
  • Decrements tank levels
  • Snapshots unit_cost_at_consumption per chemical

Consumed ──[Slicer cuts continuous block into individual blocks]──► Curing
  • Creates draft FoamBlock rows (ungraded, unmeasured)

Curing ──[Timer expires / staff marks ready]──► ReadyForGrading

ReadyForGrading ──[Each block inspected, measured, graded]──► Graded
  • Foam Manager sets grade + dimensions + m³ per block
  • Volume calculated: L × W × H

Graded ──[Blocks entered into inventory]──► Closed
  • Each FoamBlock becomes a StockLot
  • Batch material cost apportioned by volume share
  • Posts WIP → Finished Goods journal entry
```

---

## 4.5 Cost Apportionment Logic

### Step 1: Batch Material Cost
```
batch_material_cost = Σ (consumption_line.quantity × consumption_line.unit_cost_at_consumption)
```

### Step 2: Total Output Volume
```
batch_total_volume_m3 = Σ (foam_block.volume_m3) for all blocks in batch
```

### Step 3: Per-Block Cost
```
block_unit_cost = batch_material_cost × (block.volume_m3 / batch_total_volume_m3)
```

### Step 4: Fully-Loaded Cost (if overhead absorption enabled)
```
block_fully_loaded_cost = block_unit_cost + labor_cost_share + overhead_cost_share
// Labor and overhead shares require Phase 06/08/09 data
// For Phase 04, material cost only; labor/overhead added later via adjustment journal
```

---

## 4.6 Journal Entry on Batch Close

```
DR  Finished Goods Inventory — Foam Blocks (at material cost)
CR  Work In Process — Foam Production

// If labor logs exist (Phase 09):
DR  Finished Goods Inventory — Foam Blocks
CR  Wages Payable / Labor Cost Clearing
```

---

## 4.7 API Endpoints (This Phase)

### Production Batches
```
GET    /api/v1/production-batches
POST   /api/v1/production-batches
GET    /api/v1/production-batches/{id}
PUT    /api/v1/production-batches/{id}
POST   /api/v1/production-batches/{id}/transition
POST   /api/v1/production-batches/{id}/configure          → Configured state
POST   /api/v1/production-batches/{id}/start-run          → Running state
POST   /api/v1/production-batches/{id}/complete-run       → Consumed state
POST   /api/v1/production-batches/{id}/mark-cured         → ReadyForGrading state
POST   /api/v1/production-batches/{id}/submit-grading     → Graded state
POST   /api/v1/production-batches/{id}/close              → Closed state
```

### Consumption Reports
```
GET    /api/v1/production-batches/{id}/consumption-report
POST   /api/v1/production-batches/{id}/consumption-report
GET    /api/v1/consumption-reports/{id}
```

### Foam Blocks (Grading)
```
GET    /api/v1/production-batches/{id}/foam-blocks
POST   /api/v1/production-batches/{id}/foam-blocks/bulk   ← bulk grading submission
PUT    /api/v1/foam-blocks/{id}
```

---

## 4.8 Key Business Rules

| Rule ID | Description | Enforcement |
|---------|-------------|-------------|
| FOAM-01 | Batch width cannot exceed 2.4m | Validation on `formula_params.width` |
| FOAM-02 | Machine cannot start if tank levels insufficient for formula | Pre-flight check: `Σ required_chemicals <= tank_stock.quantity_on_hand` |
| FOAM-03 | ConsumptionReport created automatically on run completion | Observer on status change Running → Consumed |
| FOAM-04 | Unit cost snapshot taken at consumption time | `unit_cost_at_consumption = tank_stock.weighted_avg_unit_cost` at moment of transition |
| FOAM-05 | All blocks must be graded before batch can close | Guard on Graded → Closed: `count(ungraded_blocks) == 0` |
| FOAM-06 | Block volume calculated from dimensions: L × W × H | Auto-calculation in model setter or service |
| FOAM-07 | Batch material cost apportioned strictly by volume share | Service method `FoamCostingService::apportionCost()` |
| FOAM-08 | Each block becomes an individually serialized StockLot on Close | Transaction: create StockLot per block, link via `stock_lot_id` |
| FOAM-09 | Grade affects sale price, not unit cost (default for v1) | Pricing rules in Sales module; cost stays uniform per batch |
| FOAM-10 | Tank levels decremented on Consumed transition | StockMovement records created: `issue` type from tank warehouse |

---

## 4.9 Grading UI Specification

The grading screen must handle ~138 blocks efficiently:

- **Grid view:** Table with columns: Block #, Length, Width, Height, Volume (auto), Grade (dropdown), Status
- **Bulk actions:** Select multiple rows → set grade in bulk
- **Auto-calculate:** Volume updates live as dimensions change
- **Progress bar:** "X of 138 blocks graded"
- **Validation:** All blocks must have grade + dimensions before Close is enabled
- **Performance:** Paginated or virtualized if batch size grows; bulk API submission (not 138 individual requests)

---

## 4.10 Testing Strategy

- **Unit tests:** Cost apportionment formula, volume calculation, width validation, tank sufficiency check
- **Feature tests:** Full batch lifecycle from Planned → Closed; grading submission; StockLot creation
- **Integration tests:** Batch close triggers correct journal entry; tank levels decrement correctly

---

## 4.11 Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Grading 138 blocks is slow and error-prone | High | Bulk grading API; grid with keyboard navigation; barcode scanner support for block IDs (future enhancement) |
| Machine integration not available (no API) | Medium | Manual entry screen for consumption report; leave webhook endpoint for future machine integration |
| Batch cost calculation incorrect | High | Unit tests for apportionment; reconciliation report per batch |
| Foam block dimensions vary from spec | Low | Actual measured dimensions are what go into inventory; formula params are target, not binding |

---

## 4.12 Open Questions to Resolve

1. **Grade-based cost discounting:** Does grade apply a cost discount factor, or only sale price? (SRS 19.1) — *Default: sale price only for v1.*
2. **Machine integration:** Is there a machine API for automatic consumption reporting, or is it manual entry? — *Default: manual entry with prepared structure for future API integration.*

---

## 4.13 Phase Exit Criteria

- [ ] Production batch can be created and moved through full lifecycle to Closed
- [ ] Consumption report captures per-chemical quantities with unit cost snapshots
- [ ] Tank stock levels decrement correctly on batch run completion
- [ ] All blocks graded with dimensions and volume; batch close blocked until complete
- [ ] Each block becomes a serialized StockLot with correct apportioned cost
- [ ] Journal entry auto-posts on batch close (WIP → Finished Goods)
- [ ] Grading UI handles bulk entry efficiently
- [ ] Width > 2.4m rejected with clear error
