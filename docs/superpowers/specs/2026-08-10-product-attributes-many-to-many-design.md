# Product Attributes Many-to-Many System Design Specification

**Date**: 2026-08-10  
**Status**: Approved — with one exception, see note below  

> **Amendment (2026-08-10):** this document uses **pressure** as its running example (`pressure_kpa`). For **foam blocks** that is no longer correct. Pressure is measured per block, embedded in the block's identifier, and drives cutter-selection queries, so it is a real indexed column on `stock_lots` — not a JSON attribute. See `2026-08-10-foam-block-identity-and-batches-design.md` §4.
>
> The many-to-many mechanism described here is unchanged and remains correct. Read `pressure_kpa` below as a generic stand-in for any descriptive attribute (viscosity, purity, colour) that no identifier or indexed query depends on.

**Scope**: Refactor Inventory Attributes from category-only binding to a direct **Many-to-Many Relationship** between Products (`inventory_items`) and Attribute Definitions (`inventory_attribute_definitions`) via a pivot table (`inventory_item_attribute_definitions`), JSON lot-level storage (`stock_lots`), Laravel backend APIs, and Electron React UI (`fine-desktop`).

---

## 1. System Architecture

```
+------------------------------------+          +--------------------------------------------+
|          inventory_items           |          |      inventory_attribute_definitions       |
+------------------------------------+          +--------------------------------------------+
| id (UUID)                          |          | id (UUID)                                  |
| name, sku                          |          | name (e.g. "Pressure Rating")              |
| item_type, unit_of_measure         |          | slug (e.g. "pressure_kpa")                 |
| primary_uom, secondary_uom         |          | data_type (number, text, select, boolean)  |
+------------------------------------+          | unit_of_measure (e.g. "kPa", "kg/m³")      |
                 |                              +--------------------------------------------+
                 |                                                     |
                 +-----------------------+-----------------------------+
                                         |
                                         v
                 +-----------------------------------------------------+
                 |        inventory_item_attribute_definitions         |
                 |                  (Pivot Table)                      |
                 +-----------------------------------------------------+
                 | inventory_item_id (FK)                              |
                 | attribute_definition_id (FK)                        |
                 +-----------------------------------------------------+
                                         |
                                         v
                 +-----------------------------------------------------+
                 |                     stock_lots                      |
                 +-----------------------------------------------------+
                 | id (UUID), lot_number, inventory_item_id (FK)       |
                 | container_quantity, quantity                        |
                 | attribute_values (JSON: {"pressure_kpa": 35})       |
                 +-----------------------------------------------------+
```

---

## 2. Database Schema & Models (`fine_backend`)

### 2.1 `inventory_attribute_definitions`
Master attribute library across the application.
- `id` (UUID, Primary Key)
- `category_id` (nullable FK -> `item_categories`)
- `name` (string, e.g. "Pressure Rating")
- `slug` (string, unique)
- `data_type` (enum: `number`, `text`, `select`, `boolean`)
- `unit_of_measure` (string, nullable, e.g. `kPa`, `kg/m³`, `L`, `%`)
- `options` (JSON array, nullable for `select` type)
- `is_filterable` (boolean, default `true`)
- `sort_order` (integer, default `0`)
- `created_at`, `updated_at`

### 2.2 `inventory_item_attribute_definitions` (Pivot Table)
Many-to-Many link table declaring which attributes apply to a product.
- `inventory_item_id` (FK -> `inventory_items`, cascade delete)
- `attribute_definition_id` (FK -> `inventory_attribute_definitions`, cascade delete)
- Primary Key: `(inventory_item_id, attribute_definition_id)`

### 2.3 `inventory_items` Extensions
- Relationship `attributeDefinitions()`: `belongsToMany(InventoryAttributeDefinition::class, 'inventory_item_attribute_definitions', 'inventory_item_id', 'attribute_definition_id')`

### 2.4 `stock_lots`
- Stores actual lot values in `attribute_values` JSON column.

---

## 3. Backend API Services & Controllers (`fine_backend`)

### 3.1 `InventoryAttributeController`
- `GET /api/v1/attribute-definitions` — List global master attributes library.
- `POST /api/v1/attribute-definitions` — Create new master attribute.
- `PUT /api/v1/attribute-definitions/{id}` — Update attribute.
- `DELETE /api/v1/attribute-definitions/{id}` — Delete attribute.

### 3.2 `InventoryItemController` Updates
- `GET /api/v1/inventory-items` & `POST /api/v1/inventory-items` & `PUT /api/v1/inventory-items/{id}`:
  Accepts `attribute_definition_ids` array to sync Many-to-Many pivot records via `$item->attributeDefinitions()->sync($attributeIds)`.
  Returns `attributeDefinitions` in item payload.

---

## 4. Frontend Desktop UI (`fine-desktop`)

### 4.1 Master Attributes Library Page
- [`src/pages/inventory/AttributeLibraryPage.tsx`](file:///c:/Users/Nick/Documents/Projects/Fine/Project/fine-desktop/src/pages/inventory/AttributeLibraryPage.tsx):
  - View and create global master attribute definitions (Pressure Rating, Density, Dimensions, Viscosity, Purity).

### 4.2 Inventory Item Master Page Updates
- [`src/pages/inventory/InventoryItemsPage.tsx`](file:///c:/Users/Nick/Documents/Projects/Fine/Project/fine-desktop/src/pages/inventory/InventoryItemsPage.tsx):
  - Multi-select attribute assignment checkboxes when creating/editing an inventory item.
  - Display linked attributes tags under each item card/row.

### 4.3 Serialized Stock Ledger Page Updates
- [`src/pages/inventory/StockLedgerPage.tsx`](file:///c:/Users/Nick/Documents/Projects/Fine/Project/fine-desktop/src/pages/inventory/StockLedgerPage.tsx):
  - Dynamic lot entry modal inspecting the item's linked attributes and prompting for lot values.
