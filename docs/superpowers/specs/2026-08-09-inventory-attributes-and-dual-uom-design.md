# Dynamic Item Attributes & Dual Units of Measure (UOM) Design Specification

**Date**: 2026-08-09  
**Status**: Approved  
**Scope**: Dynamic Category Attribute Templates, Dual UOM Tracking (Container vs. Measure), Stock Lot Attribute Valuation & Filtering, Backend Laravel Services, and Frontend Electron/React Management UI.

---

## 1. System Overview & Objectives

The Inventory Management System requires multi-attribute and dual-unit tracking for raw materials, foam blocks, chemical containers, and finished goods across all operating units:
1. **Container vs. Measure Dual UOM**:
   - Items can be measured simultaneously by **Container Count** (e.g. `1 Barrel`, `1 Block`, `1 Pallet`, `1 Box`) and **Measure Quantity** (e.g. `160 Liters`, `4.0 m³`, `120 kg`).
   - Partial consumption reduces the measure quantity on that specific container/lot while preserving container metadata.
2. **Category-Driven Dynamic Attribute Templates**:
   - Reusable attribute templates defined per `ItemCategory` (e.g., Foam Block attributes: $L \times W \times H$, weight, pressure/hardness, density; Chemical Liquid attributes: purity %, viscosity, density).
   - Attribute definitions specify field name, slug, data type (`number`, `text`, `select`, `boolean`), unit of measure, and validation rules.
3. **Lot-Level Attribute Tracking & Advanced Filtering**:
   - `StockLot` records actual measured attributes for individual lots.
   - Operators can filter stock lots using dynamic attribute queries (e.g., `pressure_kpa >= 35`, `density_kg_m3 = 30`, `volume_m3 >= 2.0`).

---

## 2. Database Schema & Data Models (`fine_backend`)

### 2.1 `item_categories`
Stores product categories across operating units.
- `id` (UUID, Primary Key)
- `operating_unit_id` (nullable FK -> `operating_units`)
- `name` (string)
- `code` (string, unique)
- `description` (text, nullable)
- `created_at`, `updated_at`, `deleted_at`

### 2.2 `inventory_attribute_definitions`
Defines dynamic attribute fields per item category.
- `id` (UUID, Primary Key)
- `category_id` (FK -> `item_categories`, cascade delete)
- `name` (string, e.g. "Pressure Rating")
- `slug` (string, e.g. `pressure_kpa`)
- `data_type` (enum: `number`, `text`, `select`, `boolean`)
- `unit_of_measure` (string, nullable, e.g. `kPa`, `kg/m³`, `L`, `%`)
- `options` (JSON array, nullable for `select` type options)
- `is_required_on_lot` (boolean, default `false`)
- `is_filterable` (boolean, default `true`)
- `sort_order` (integer, default `0`)
- `created_at`, `updated_at`

### 2.3 `inventory_items` Extensions
- `category_id` (nullable FK -> `item_categories`)
- `primary_uom` (string, container UOM e.g. `barrel`, `block`, `pallet`, `box`, `each`)
- `secondary_uom` (string, measure UOM e.g. `liter`, `m3`, `kg`, `meter`)
- `default_attributes` (JSON map of default item specs)

### 2.4 `stock_lots` Extensions
- `container_quantity` (decimal 15,4, default `1.0000`)
- `quantity` (decimal 15,4, secondary measure qty)
- `attribute_values` (JSON map of actual measured lot values, e.g. `{"length_m": 2.0, "width_m": 2.0, "height_m": 1.0, "weight_kg": 120, "pressure_kpa": 35}`)

---

## 3. Backend Services & Controllers (`fine_backend`)

### 3.1 `CategoryController` & `InventoryAttributeController`
- `GET /api/v1/item-categories` & `POST /api/v1/item-categories`
- `GET /api/v1/item-categories/{id}/attribute-definitions`
- `POST /api/v1/item-categories/{id}/attribute-definitions`
- `PUT /api/v1/attribute-definitions/{id}`
- `DELETE /api/v1/attribute-definitions/{id}`

### 3.2 `StockLotService` Extended Filtering
- Advanced attribute JSON filtering on `/api/v1/stock-lots`:
  Support query format: `?category_id={uuid}&attrs[pressure_kpa][gte]=35&attrs[density_kg_m3]=30`
- Attribute validation: Ensures required lot attributes declared by the category template are present upon `StockLot` creation.

---

## 4. Frontend Desktop UI (`fine-desktop`)

### 4.1 Category & Attribute Template Manager
- [`src/pages/inventory/CategoryAttributeManagerPage.tsx`](file:///c:/Users/Nick/Documents/Projects/Fine/Project/fine-desktop/src/pages/inventory/CategoryAttributeManagerPage.tsx):
  - View & create categories.
  - Interactive attribute definition builder (field name, slug, type, UOM, required flag, select dropdown options).

### 4.2 Item Master Extensions
- [`src/pages/inventory/InventoryItemsPage.tsx`](file:///c:/Users/Nick/Documents/Projects/Fine/Project/fine-desktop/src/pages/inventory/InventoryItemsPage.tsx):
  - Category selector with dynamic default attribute fields rendered based on selected category template.
  - Dual UOM selection (Container UOM & Measure UOM).

### 4.3 Serialized Stock Ledger & Cutter Extensions
- [`src/pages/inventory/StockLedgerPage.tsx`](file:///c:/Users/Nick/Documents/Projects/Fine/Project/fine-desktop/src/pages/inventory/StockLedgerPage.tsx):
  - Dynamic Attribute Filter bar (filter by category, pressure rating, density, dimensions).
  - Stock lot cards displaying attribute badges and Dual UOM quantities (`1 Barrel (160 L)` or `1 Block (4.0 m³)`).
