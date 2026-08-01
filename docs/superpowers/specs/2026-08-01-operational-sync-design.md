# Operational Sync (Work Orders & Inventory Movements) Design Specification

**Date**: 2026-08-01  
**Status**: Pending Review  
**Scope**: Support backend synchronization of local work orders and inventory movements pushed/pulled by Electron desktop clients.

---

## 1. Overview & Goal

The Electron frontend (`fine-desktop`) on the `origin/SQlite` branch implements local-first offline capabilities for **Work Orders** and **Inventory Movements**. 
To enable synchronization, the Laravel backend (`fine_backend`) must:
1. Provide database tables for `work_orders` and `inventory_movements`.
2. Add Eloquent models matching these tables, including multi-tenant scoping (`operating_unit_id`).
3. Update `SyncService` to process delta pushes and pulls for these models, handling column case conversion (camelCase client keys <-> snake_case DB columns) and tenant filtering.

---

## 2. Proposed Database Schema

Both tables will be scoped by `operating_unit_id` to ensure strict multi-tenant isolation.

### 2.1 `work_orders` Table
* `id` (UUID, Primary Key)
* `operating_unit_id` (UUID, Foreign Key referencing `operating_units.id`)
* `product_sku` (VARCHAR, Not Null)
* `quantity` (DECIMAL, Not Null)
* `status` (VARCHAR, Not Null, e.g. `'open'`, `'completed'`)
* `record_version` (INTEGER, Default `1`)
* `created_at` (TIMESTAMP)
* `updated_at` (TIMESTAMP)

### 2.2 `inventory_movements` Table
* `id` (UUID, Primary Key)
* `operating_unit_id` (UUID, Foreign Key referencing `operating_units.id`)
* `sku` (VARCHAR, Not Null)
* `quantity_delta` (DECIMAL, Not Null)
* `reason` (VARCHAR, Not Null)
* `reference_id` (UUID, Nullable, links to work order ID)
* `record_version` (INTEGER, Default `1`)
* `created_at` (TIMESTAMP)
* `updated_at` (TIMESTAMP)

---

## 3. Implementation Plan

### 3.1 Backend Models
* **`App\Models\WorkOrder`**:
  * Fields: `id`, `operating_unit_id`, `product_sku`, `quantity`, `status`, `record_version`.
  * Relationship: `belongsTo(OperatingUnit::class)`.
  * Uses: `HasUuids` trait.
* **`App\Models\InventoryMovement`**:
  * Fields: `id`, `operating_unit_id`, `sku`, `quantity_delta`, `reason`, `reference_id`, `record_version`.
  * Relationship: `belongsTo(OperatingUnit::class)`.
  * Uses: `HasUuids` trait.

### 3.2 Sync Service Integration (`App\Services\SyncService`)
* Register `'work_orders' => WorkOrder::class` and `'inventory_movements' => InventoryMovement::class` in the `$modelMap`.
* **Push translation**:
  - In `processPush`, when handling incoming operations for `work_orders` or `inventory_movements`, convert camelCase fields from the payload to snake_case (`productSku` -> `product_sku`, `quantityDelta` -> `quantity_delta`, `referenceId` -> `reference_id`).
  - Attach `operating_unit_id = $unit->id`.
* **Pull translation**:
  - In `processPull`, filter database models by `operating_unit_id`.
  - Convert output records' snake_case columns back to camelCase for the frontend client JSON (e.g. `product_sku` -> `productSku`).

### 3.3 Frontend Integration
* Update `fine-desktop/src/sync/syncService.ts` to request endpoints on the API versioned route: `${API_ORIGIN}/api/v1/sync/push` and `${API_ORIGIN}/api/v1/sync/pull`.

---

## 4. Verification Plan

### 4.1 Automated Tests
* Create unit/feature tests for pushing/pulling work orders and inventory movements via `tests/Feature/SyncEngineTest.php`.
* Run backend verification:
  ```bash
  php artisan test --compact
  ```

### 4.2 Manual Verification
* Run the Electron app local build, trigger a manual sync, and ensure that local database mutations on work orders and inventory movements sync successfully without error, populating the backend database.
