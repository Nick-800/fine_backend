# Phase 06: Furniture Manufacturing

> **Duration Estimate:** 4–5 weeks  
> **Team Size:** 2 backend engineers, 1 frontend engineer  
> **Dependencies:** Phase 01 (Foundation), Phase 03 (Inventory), Phase 05 (Cutter Manufacturing)

---

## 6.1 Objective

Build the furniture production module: product catalog with BOM management, BOM-driven production orders, component stock reservation, labor time logging, custom order BOM adaptation, and finished goods entry into Furniture inventory. This module bridges Cutter output (foam pieces, slices) with finished furniture assembly.

### Architecture & API Scope
All BOM lookups, production assembly orders, component stock reservations, and labor time logging execute live via direct central REST API endpoints against the central server database.

---

## 6.2 Deliverables

| # | Deliverable | Acceptance Criteria |
|---|-------------|---------------------|
| 1 | Product catalog | CRUD for furniture products (sofa, mattress, etc.) with base BOM linkage |
| 2 | BOM management | Versioned BOMs with component lines (materials + slices) and labor requirements |
| 3 | BOM component lines | Links to InventoryItem (foam pieces, slices, fabric, wood, springs, etc.) |
| 4 | Labor requirements | Per-BOM role definitions (tailor, carpenter, etc.) with estimated hours |
| 5 | Production order lifecycle | Requested → BOMConfirmed → InProduction → QualityCheck → ReadyForCollection → Completed |
| 6 | Custom order adaptation | Clone closest BOM, adapt components, recalculate price |
| 7 | Stock reservation | BOMConfirmed transition reserves component stock from Furniture inventory |
| 8 | Labor logging | Workshop staff log hours per LaborRequirement; Labor Manager reviews |
| 9 | Finished goods entry | QualityCheck → ReadyForCollection increments Furniture inventory |
| 10 | Unit client: BOM editor | Visual BOM builder with component list and labor roles |
| 11 | Unit client: Production order management | Order creation, BOM adaptation, labor logging, QC |

---

## 6.3 Database Migrations (This Phase)

- `products`
- `boms`
- `bom_component_lines`
- `labor_requirements`
- `production_orders`
- `labor_logs`

---

## 6.4 State Machine: Production Order

```
Requested ──[Client order or stock order]──► BOMConfirmed
  • For custom orders: closest BOM cloned & adapted
  • Price recalculated from adapted BOM cost

BOMConfirmed ──[Furniture Manager confirms availability]──► InProduction
  • Reserves BOMComponentLine stock from Furniture inventory
  • StockLot status → reserved

InProduction ──[Assembly work performed]──► QualityCheck
  • Consumes reserved components (StockMovement: reservation → issue)
  • Logs labor time per LaborRequirement

QualityCheck ──[Furniture Manager verifies]──► ReadyForCollection
  • Increments Furniture inventory with finished good
  • Creates new StockLot for finished product

ReadyForCollection ──[Client collects or internal transfer]──► Completed
  • Decrements Furniture inventory
  • Creates Sale/Invoice or internal transfer
```

---

## 6.5 BOM Structure

```
Product: "3-Seat Sofa - Standard"
  BOM v1.0 (active)
    ├─ Component Lines:
    │   ├─ Foam base piece (cut template) × 1
    │   ├─ Foam back piece (cut template) × 3
    │   ├─ Slice (~2cm) × 2 (adds height)
    │   ├─ Fabric cover (meter) × 5
    │   ├─ Wood frame (each) × 1
    │   └─ Springs (each) × 4
    │
    └─ Labor Requirements:
        ├─ Carpenter: 4 hours
        ├─ Tailor: 3 hours
        └─ Upholsterer: 2 hours
```

### Custom Order Adaptation

```
1. Sales selects closest existing Product/BOM
2. System clones BOM → adapted_BOM
3. User modifies component lines:
   - Change quantities
   - Add/remove components
   - Change slice thickness
4. System recalculates:
   - material_cost = Σ (component.qty × component.unit_cost)
   - labor_cost = Σ (labor.estimated_hours × current LaborRoleRate)
   - total_cost = material_cost + labor_cost
   - sale_price = total_cost × markup_factor (configurable)
```

---

## 6.6 Stock Reservation Logic

On `BOMConfirmed → InProduction`:

```
For each BOMComponentLine:
  1. Find available StockLots of required InventoryItem
  2. Reserve sufficient quantity:
     - For serialized items (foam pieces): reserve specific StockLots
     - For bulk items (fabric): reserve quantity from available pool
  3. Create reservation StockMovement records
  4. Set reserved StockLot status → "reserved"
```

On `InProduction → QualityCheck`:
```
For each reserved component:
  1. Convert reservation to consumption
  2. StockMovement: reservation → issue
  3. StockLot status → "consumed" or quantity decremented
```

---

## 6.7 Labor Logging

```
LaborLog {
  production_order_id
  employee_id
  role (tailor | carpenter | operator | assembler | upholsterer | other)
  hours_logged
  hourly_rate_at_log  ← snapshot of LaborRoleRate at time of logging
}
```

**Labor cost formula:**
```
production_order.labor_cost = Σ (labor_log.hours_logged × labor_log.hourly_rate_at_log)
```

---

## 6.8 Journal Entries

### On BOMConfirmed → InProduction (Reservation)
```
No journal entry for reservation alone (just inventory status change)
```

### On InProduction → QualityCheck (Consumption)
```
DR  Work In Process — Furniture
CR  Raw Materials Inventory (foam pieces, fabric, wood, etc.)
```

### On QualityCheck → ReadyForCollection (Finished Goods)
```
DR  Finished Goods Inventory — Furniture
CR  Work In Process — Furniture
CR  Wages Payable / Labor Cost Clearing  (if labor cost known)
```

---

## 6.9 API Endpoints (This Phase)

### Products
```
GET    /api/v1/products
POST   /api/v1/products
GET    /api/v1/products/{id}
PUT    /api/v1/products/{id}
DELETE /api/v1/products/{id}
GET    /api/v1/products/{id}/boms
```

### BOMs
```
GET    /api/v1/boms
POST   /api/v1/boms
GET    /api/v1/boms/{id}
PUT    /api/v1/boms/{id}
POST   /api/v1/boms/{id}/clone              ← for custom order adaptation
GET    /api/v1/boms/{id}/component-lines
POST   /api/v1/boms/{id}/component-lines
GET    /api/v1/boms/{id}/labor-requirements
POST   /api/v1/boms/{id}/labor-requirements
```

### Production Orders
```
GET    /api/v1/production-orders
POST   /api/v1/production-orders
GET    /api/v1/production-orders/{id}
PUT    /api/v1/production-orders/{id}
POST   /api/v1/production-orders/{id}/transition
POST   /api/v1/production-orders/{id}/confirm-bom        → BOMConfirmed
POST   /api/v1/production-orders/{id}/start-production   → InProduction
POST   /api/v1/production-orders/{id}/submit-qc          → QualityCheck
POST   /api/v1/production-orders/{id}/ready-for-collection → ReadyForCollection
POST   /api/v1/production-orders/{id}/complete           → Completed
```

### Labor Logs
```
GET    /api/v1/production-orders/{id}/labor-logs
POST   /api/v1/production-orders/{id}/labor-logs
PUT    /api/v1/labor-logs/{id}
```

---

## 6.10 Key Business Rules

| Rule ID | Description | Enforcement |
|---------|-------------|-------------|
| FUR-01 | Every product must have at least one active BOM | Validation on product creation; deactivate product if last BOM deactivated |
| FUR-02 | BOM component lines can reference any InventoryItem including slices | Foreign key to `inventory_items`; no type restriction |
| FUR-03 | Custom orders clone the closest BOM and allow adaptation | `BOMService::cloneAndAdapt()` method |
| FUR-04 | Stock reservation happens on BOMConfirmed → InProduction | Service method `ProductionOrderService::reserveComponents()` |
| FUR-05 | Reserved stock cannot be used by other orders | `stock_lots.status = "reserved"` with query scope |
| FUR-06 | Labor logs capture hourly_rate_at_log as snapshot | Set from current `LaborRoleRate` at time of log creation |
| FUR-07 | Production order cannot proceed to QC if components not fully consumed | Guard checks all reservations are converted |
| FUR-08 | Finished good StockLot created on QC approval | `InventoryService::createFinishedGoodStockLot()` |
| FUR-09 | Only Furniture Manager can approve BOM and QC | Policy checks |
| FUR-10 | Workshop staff can log labor but cannot approve transitions | Role-based middleware |

---

## 6.11 Unit Client Screens

1. **Product Catalog:** Grid of products with active BOM indicator
2. **BOM Editor:** 
   - Component section: add/remove items, set quantities, see cost rollup
   - Labor section: add roles, estimated hours
   - Version history: clone, activate, deactivate
3. **Production Order List:** Pipeline by status; filter by client/stock
4. **Order Creation:** Select product → auto-load BOM → adapt if custom → price preview
5. **Labor Logging:** Simple screen for workshop staff: select order → select role → enter hours
6. **QC Screen:** Furniture Manager views finished product, photos (optional), approves/rejects

---

## 6.12 Testing Strategy

- **Unit tests:** BOM cost calculation, labor cost rollup, reservation logic
- **Feature tests:** Full production order lifecycle; custom BOM adaptation; stock reservation and consumption
- **Integration tests:** Production order completion triggers correct journal entries; labor logs feed into costing

---

## 6.13 Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| BOM versions become confusing | Medium | Only one BOM active per product at a time; clear version naming; clone trail |
| Custom order pricing incorrect | High | Price preview before confirmation; cost breakdown visible to Furniture Manager |
| Stock reservation deadlocks | Low | Reservation timeout (auto-release if order cancelled); explicit release on cancellation |
| Labor logging forgotten | Medium | Daily reminder/notification to Labor Manager; batch log entry allowed |

---

## 6.14 Phase Exit Criteria

- [ ] Product catalog with BOM management functional
- [ ] Production order lifecycle complete from Requested → Completed
- [ ] BOM component stock reservation and consumption work correctly
- [ ] Custom order BOM cloning and adaptation functional
- [ ] Labor logging captures hours and rates correctly
- [ ] Finished goods enter Furniture inventory on QC approval
- [ ] Journal entries auto-post at correct transitions
- [ ] Unit client screens functional in Arabic/RTL
