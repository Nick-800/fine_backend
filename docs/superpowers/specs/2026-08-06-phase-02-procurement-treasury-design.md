# Phase 02: Procurement, Import & Treasury Design Specification

**Date**: 2026-08-06  
**Status**: Approved  
**Scope**: Deliver backend architecture, database schema, state machine service, API resources, policies, and Pest tests for Phase 02 Procurement, Import Pipeline, Landed Cost Allocation, and Treasury Module.

---

## 1. Architectural Overview & Objectives

Phase 02 builds the foreign procurement pipeline and multi-currency treasury engine for the ERP. All operations communicate directly with the central server over REST API endpoints in real-time database transactions (`DB::transaction()`).

### Core Capabilities:
1. **Supplier Management**: Supplier master data with default currency and order history.
2. **Import Order State Machine (`ImportOrderStateService`)**: Explicit 10-stage lifecycle (`draft` → `pending_payment` → `awaiting_bank_approval`/`awaiting_transfer` → `paid` → `in_transit` → `at_port` → `awaiting_receipt` → `received` → `complete`).
3. **Payment Routes & Bank Holds**: Dual payment routes (Bank vs Market exchange), buffer reservation in LYD, and unused buffer release.
4. **Landed Cost Capitalization**: Itemized cost tracking (`supplier_price`, `fx_spread`, `customs`, `freight`, `local_transport`, `other`) allocated per unit into raw-material inventory valuation.
5. **Goods Receipt**: Physical check-in at main warehouse.
6. **Treasury & FX Engine**: FX rate capture (USD⇄LYD), cash account balance tracking, and automated FX gain/loss journal posting when realized exchange rate differs from booked estimate.

---

## 2. Database Schema (8 Migrations)

### 2.1 `suppliers`
- `id` (UUID, Primary Key)
- `operating_unit_id` (UUID, Foreign Key)
- `name` (VARCHAR)
- `contact` (VARCHAR, Nullable)
- `default_currency` (VARCHAR, Default `'USD'`)
- `address` (TEXT, Nullable)
- `created_at`, `updated_at`, `deleted_at`

### 2.2 `import_orders`
- `id` (UUID, Primary Key)
- `operating_unit_id` (UUID, Foreign Key)
- `supplier_id` (UUID, Foreign Key referencing `suppliers.id`)
- `currency` (VARCHAR)
- `negotiated_price` (DECIMAL, 15, 4)
- `quantity` (DECIMAL, 15, 4)
- `status` (VARCHAR, Default `'draft'`)
- `record_version` (INTEGER, Default `1`)
- `created_at`, `updated_at`, `deleted_at`

### 2.3 `payment_requests`
- `id` (UUID, Primary Key)
- `operating_unit_id` (UUID, Foreign Key)
- `import_order_id` (UUID, Foreign Key referencing `import_orders.id`)
- `route` (VARCHAR, Enum `'bank'|'market'`)
- `invoice_ref` (VARCHAR, Nullable)
- `amount_requested` (DECIMAL, 15, 4)
- `status` (VARCHAR, Enum `'pending'|'paid'|'rejected'`, Default `'pending'`)
- `fx_rate_used` (DECIMAL, 15, 6, Nullable)
- `created_at`, `updated_at`

### 2.4 `bank_holds`
- `id` (UUID, Primary Key)
- `payment_request_id` (UUID, Foreign Key referencing `payment_requests.id`)
- `held_amount_lyd` (DECIMAL, 15, 4)
- `exact_amount_used` (DECIMAL, 15, 4, Default `0`)
- `released_amount` (DECIMAL, 15, 4, Default `0`)
- `bank_reference` (VARCHAR, Nullable)
- `created_at`, `updated_at`

### 2.5 `landed_cost_lines`
- `id` (UUID, Primary Key)
- `import_order_id` (UUID, Foreign Key referencing `import_orders.id`)
- `type` (VARCHAR, Enum `'supplier_price'|'fx_spread'|'customs'|'freight'|'local_transport'|'other'`)
- `amount` (DECIMAL, 15, 4)
- `currency` (VARCHAR)
- `is_confirmed` (BOOLEAN, Default `false`)
- `created_at`, `updated_at`

### 2.6 `goods_receipts`
- `id` (UUID, Primary Key)
- `import_order_id` (UUID, Foreign Key referencing `import_orders.id`)
- `warehouse_id` (UUID, Foreign Key referencing `warehouses.id`)
- `received_qty` (DECIMAL, 15, 4)
- `condition_notes` (TEXT, Nullable)
- `created_at`, `updated_at`

### 2.7 `fx_rates`
- `id` (UUID, Primary Key)
- `from_currency` (VARCHAR)
- `to_currency` (VARCHAR)
- `rate` (DECIMAL, 15, 6)
- `captured_at` (TIMESTAMP)
- `created_at`, `updated_at`

### 2.8 `cash_accounts`
- `id` (UUID, Primary Key)
- `operating_unit_id` (UUID, Foreign Key)
- `name` (VARCHAR)
- `currency` (VARCHAR)
- `balance` (DECIMAL, 15, 4, Default `0`)
- `created_at`, `updated_at`

---

## 3. State Machine & Domain Services

### `ImportOrderStateService`
Controls all state transitions inside `DB::transaction()` blocks:

1. **`transitionToPendingPayment(ImportOrder $order)`**:
   - Validates status is `draft`.
   - Transitions status to `pending_payment`.
   - Creates a pending `PaymentRequest`.

2. **`selectPaymentRoute(ImportOrder $order, string $route, float $amountRequested, ?float $heldAmountLyd)`**:
   - `route = 'bank'`: Transitions to `awaiting_bank_approval`, creates `BankHold` with `held_amount_lyd` buffer.
   - `route = 'market'`: Transitions to `awaiting_transfer`.

3. **`executePayment(PaymentRequest $paymentRequest, float $fxRateUsed, ?float $exactAmountUsedLyd)`**:
   - Sets `fx_rate_used`.
   - If bank route: calculates `released_amount = held_amount_lyd - exact_amount_used` and releases buffer.
   - Posts FX gain/loss calculation variance if realized rate differs from estimate.
   - Transitions `ImportOrder` status to `paid`.

4. **`confirmShipment(ImportOrder $order)`**: Transitions `paid` → `in_transit`.
5. **`arriveAtPort(ImportOrder $order)`**: Transitions `in_transit` → `at_port`.
6. **`transportToWarehouse(ImportOrder $order)`**: Transitions `at_port` → `awaiting_receipt`.
7. **`receiveGoods(ImportOrder $order, string $warehouseId, float $receivedQty, ?string $notes)`**:
   - Validates `receivedQty <= order->quantity` (`PROC-05`).
   - Creates `GoodsReceipt`.
   - Transitions status to `received`.

8. **`completeOrder(ImportOrder $order)`**:
   - Validates `GoodsReceipt` exists (`PROC-08`).
   - Validates all `LandedCostLine` entries are `is_confirmed = true` (`PROC-04`).
   - Calculates `landed_cost_per_unit = sum(landed_cost_lines) / received_quantity`.
   - Capitalizes landed cost into inventory valuation (`PROC-09`).
   - Transitions status to `complete`.

---

## 4. API Controllers & Endpoints

- **`SupplierController`**: CRUD (`/api/v1/suppliers`, `/api/v1/suppliers/{id}/import-orders`).
- **`ImportOrderController`**: CRUD & transition actions (`/api/v1/import-orders`, `/api/v1/import-orders/{id}/transition`).
- **`LandedCostLineController`**: List & store (`/api/v1/import-orders/{id}/landed-cost-lines`).
- **`GoodsReceiptController`**: List & store (`/api/v1/import-orders/{id}/goods-receipts`).
- **`PaymentRequestController`**: List & execute (`/api/v1/payment-requests`, `/api/v1/payment-requests/{id}/execute`).
- **`BankHoldController`**: List (`/api/v1/bank-holds`).
- **`FxRateController`**: List & capture (`/api/v1/fx-rates`).
- **`CashAccountController`**: List & ledger view (`/api/v1/cash-accounts`, `/api/v1/cash-accounts/{id}/ledger`).

---

## 5. Verification & Testing

- Pest Feature Tests (`tests/Feature/ImportOrderLifecycleTest.php`, `tests/Feature/TreasuryPaymentTest.php`, `tests/Feature/LandedCostAllocationTest.php`).
- Form validation tests (`PROC-01` through `PROC-10`).
- Code style verification via Laravel Pint (`vendor/bin/pint --format agent`).
