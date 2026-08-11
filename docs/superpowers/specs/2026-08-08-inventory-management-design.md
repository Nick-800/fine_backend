# Inventory Management System Design Specification

**Date**: 2026-08-08  
**Status**: Approved  
**Scope**: Define the backend Eloquent data model, weighted-average costing, serialized foam block lots, stock movement ledger, approval workflows, and frontend Electron/React UI screens for Phase 03 / Phase F06.

---

## 1. System Overview & Core Principles

The Inventory Management System provides the canonical stock ledger across all 5 operating units (Procurement, Foam Manufactory, Cutter Manufactory, Furniture Manufactory, Store/Showroom). 

### Key Design Principles:
1. **Operating Unit Isolation**: Each operating unit manages its own scoped warehouses and stock ledgers via `operating_unit_id` and `X-Operating-Unit-ID` headers.
2. **Serialized Foam Block Lots (`StockLot`)**: Foam blocks are individually serialized with unique lot numbers, dimensions ($L \times W \times H$), volume ($m^3$), grade, and status. Upon consumption by the cutter, the operator is prompted during work order completion to manually choose whether to restock the remnant as a new block (filling in $L \times W \times H$ dimensions) or convert it to Byproduct Fill ($kg$).
3. **Atomic Weighted-Average Tank Costing (`TankStock`)**: Chemical bulk tanks recalculate unit cost on every refill using:
   $$\text{New WAC} = \frac{(\text{Old Qty} \times \text{Old WAC}) + (\text{Refill Qty} \times \text{Refill Cost})}{\text{Old Qty} + \text{Refill Qty}}$$
4. **Approval-Gated Manual Adjustments (`StockAdjustmentRequest`)**: Manual stock count adjustments, damage, or spill losses require pre-defined reason codes (`spill_loss`, `damage`, `audit_reconciliation`, `expired`) and **Unit Manager approval** before triggering a `StockMovement`.
5. **Polymorphic Immutable Ledger (`StockMovement`)**: Stock quantities change ONLY via immutable movement records referencing source document types (`ImportOrder`, `StockAdjustmentRequest`, `ProductionBatch`, `CutterWorkOrder`, `SalesOrder`).

---

## 2. Data Model & Database Schema

### 2.1 `inventory_items`
Master catalog of sellable, consumable, and raw materials.
- `id` (UUID, Primary)
- `name` (string)
- `sku` (string, unique)
- `item_type` (enum: `raw_material`, `foam_block`, `cut_template_piece`, `slice`, `byproduct_fill`, `furniture_finished_good`, `packaging`, `barrel`, `pallet`)
- `unit_of_measure` (enum: `each`, `m3`, `kg`, `meter`, `liter`)
- `timestamps`, `soft_deletes`

### 2.2 `stock_lots`
Serialized foam blocks and discrete lot batches.
- `id` (UUID, Primary)
- `inventory_item_id` (FK -> `inventory_items`)
- `warehouse_id` (FK -> `warehouses`)
- `lot_number` (string, unique)
- `quantity` (decimal 15,4 - enforced 1.0000 for `foam_block`)
- `length_m`, `width_m`, `height_m` (decimal 8,3, nullable)
- `volume_m3` (decimal 10,4, auto-calculated)
- `weight_kg` (decimal 10,4, for byproduct fill)
- `unit_cost` (decimal 15,4)
- `grade` (enum: `standard`, `acceptable_variant`, `defective_usable`, `reject`)
- `status` (enum: `available`, `reserved`, `consumed`, `quarantined`)
- `production_batch_id` (nullable FK -> `production_batches`)
- `record_version` (unsigned integer, optimistic locking)
- `timestamps`, `soft_deletes`

### 2.3 `tank_stocks`
Chemical tanks for raw material mixing.
- `id` (UUID, Primary)
- `chemical_inventory_item_id` (FK -> `inventory_items`)
- `operating_unit_id` (FK -> `operating_units`)
- `quantity_on_hand` (decimal 15,4)
- `weighted_avg_unit_cost` (decimal 15,4)
- `record_version` (unsigned integer)
- `timestamps`

### 2.4 `stock_adjustment_requests`
Manual stock adjustment workflow table.
- `id` (UUID, Primary)
- `operating_unit_id` (FK -> `operating_units`)
- `stock_lot_id` (FK -> `stock_lots`)
- `reason_code` (enum: `audit_reconciliation`, `spill_loss`, `damage`, `expired`)
- `quantity_delta` (decimal 15,4)
- `notes` (text, nullable)
- `status` (enum: `pending`, `approved`, `rejected`)
- `requested_by_user_id` (FK -> `users`)
- `approved_by_user_id` (nullable FK -> `users`)
- `approved_at` (timestamp, nullable)
- `record_version` (unsigned integer)
- `timestamps`

### 2.5 `stock_movements`
Immutable inventory movement audit ledger.
- `id` (UUID, Primary)
- `operating_unit_id` (FK -> `operating_units`)
- `stock_lot_id` (FK -> `stock_lots`)
- `from_warehouse_id` (nullable FK -> `warehouses`)
- `to_warehouse_id` (nullable FK -> `warehouses`)
- `movement_type` (enum: `receipt`, `issue`, `transfer`, `adjustment`, `consumption`, `production_output`, `byproduct_yield`, `sale`)
- `quantity` (decimal 15,4)
- `unit_cost` (decimal 15,4)
- `reference_document_type` (string)
- `reference_document_id` (UUID)
- `record_version` (unsigned integer)
- `timestamps`

---

## 3. API Endpoints & Contracts

### Inventory Items
- `GET /api/v1/inventory-items`
- `POST /api/v1/inventory-items`
- `GET /api/v1/inventory-items/{id}`
- `PUT /api/v1/inventory-items/{id}`

### Stock Lots & Cutter Selection
- `GET /api/v1/stock-lots`
- `GET /api/v1/stock-lots/available-for-cutting?min_volume_m3={val}&grade={grade}`
- `GET /api/v1/stock-lots/{id}`

### Tank Stocks
- `GET /api/v1/tank-stocks`
- `POST /api/v1/tank-stocks/refill` (Body: `{ chemical_inventory_item_id, refill_quantity, refill_unit_cost }`)

### Stock Adjustments & Approvals
- `GET /api/v1/stock-adjustment-requests`
- `POST /api/v1/stock-adjustment-requests` (Body: `{ stock_lot_id, reason_code, quantity_delta, notes }`)
- `POST /api/v1/stock-adjustment-requests/{id}/approve`
- `POST /api/v1/stock-adjustment-requests/{id}/reject`

### Inventory Valuation
- `GET /api/v1/inventory/valuation`
- `GET /api/v1/inventory/valuation/rollup`

---

## 4. Frontend Architecture & UI Components (`fine-desktop`)

### API Layer & Custom Hooks
- `src/api/endpoints/inventory.ts`: Typed API endpoints for items, stock lots, tank stocks, adjustments, and valuation.
- `src/hooks/useInventory.ts`: TanStack Query hooks (`useInventoryItems`, `useStockLots`, `useAvailableForCutting`, `useTankStocks`, `useRefillTank`, `useStockAdjustments`, `useApproveAdjustment`).

### UI Pages
1. `InventoryItemsPage.tsx`: Item master table, SKU search, UOM tags, category filter, item creation modal.
2. `StockLedgerPage.tsx`: Serialized block stock lot table showing lot number, dimensions ($L \times W \times H$), volume ($m^3$), grade badge, status, and movement history modal.
3. `TankStockPage.tsx`: Tank level gauges, chemical inventory list, weighted-average unit cost indicator, refill modal.
4. `StockAdjustmentsPage.tsx`: Pending adjustment requests table for Unit Manager approval, reason code badges, approve/reject buttons.
