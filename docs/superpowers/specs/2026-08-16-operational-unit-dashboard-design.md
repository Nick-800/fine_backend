# Operational Unit Dashboard Specification

- **Date:** 2026-08-16
- **Status:** Approved
- **Scope:** `fine-desktop/src/pages/Dashboard.tsx`.

---

## 1. Overview & Business Rationale

While the Owner Dashboard (`OwnerDashboardPage.tsx`) provides high-level executive and company-wide financial rollups, operational staff and unit supervisors who land on the root route `/` require an **Operational Unit Dashboard** (`Dashboard.tsx`).

This dashboard must present:
1. Active operating unit context (name, code, server connection health).
2. Live operational KPI cards for the unit (daily POS sales, active manufacturing batches, stock lots available in warehouse, and pending restock requests).
3. Quick action launchers for high-frequency unit workflows (POS, Foam Batches, Cutter Orders, Stock Movements, Attendance).
4. Recent operational activity feed.

---

## 2. Technical Specifications

### 2.1 Layout & Components (`fine-desktop/src/pages/Dashboard.tsx`)
- **Header**:
  - Operating Unit title & code with unit status.
  - Live server connection status indicator.
  - Quick refresh action.
- **Real-Time KPI Cards Grid (4 Cards)**:
  - **مبيعات اليوم (POS Today)**: `report.sales_count` transactions, `report.total` LYD volume.
  - **تشغيلات الإنتاج (Production Batches)**: Count of active/graded batches.
  - **أرصدة المخزون (Stock Lots)**: Count of available inventory lots in unit warehouses.
  - **طلبات التموين المعلقة (Pending Restock)**: Count of internal unit transfer requests awaiting approval.
- **Quick Action Hub**:
  - POS Counter Sale (`/sales/pos`)
  - Foam Production (`/manufacturing/foam/batches`)
  - Cutter Work Orders (`/cutter/work-orders`)
  - Inventory Stock (`/inventory/lots`)
  - Daily Attendance Sheet (`/hr/attendance`)
- **Recent Activity Feed**:
  - Real-time list of recent production batches and stock movements with status pills and timestamps.

### 2.2 Design System Tokens
- Semantic tokens: `bg-app-bg-primary`, `bg-app-bg-secondary`, `border-app-separator`, `text-app-label-primary`, `text-app-label-secondary`, `bg-app-accent`.
- Logical RTL properties: `ps-*`, `pe-*`, `ms-*`, `me-*`.

---

## 3. Verification Plan

1. **Frontend Typecheck:** Run `npx tsc --noEmit` in `fine-desktop`.
2. **Backend Tests:** Run full test suite `php artisan test --compact`.
