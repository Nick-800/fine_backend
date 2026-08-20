# POS Receipt Printing & Daily Register Closing Specification

- **Date:** 2026-08-16
- **Status:** Approved
- **Scope:** `fine-desktop/src/pages/sales/PosPage.tsx`, `fine-desktop/src/components/pos/PosReceiptModal.tsx`, `fine-desktop/src/components/pos/PosDailyCloseModal.tsx`.

---

## 1. Overview & Business Rationale

Phase 07 (Sales & POS) and Phase F08 (Furniture & Sales UI) require counter sales at point-of-sale terminals to:
1. Emit immediate printed receipts for retail customers with line item details, payment breakdown, tendered cash, and change due.
2. Provide counter staff and cashiers with an end-of-day register closing procedure to reconcile physical cash in the drawer against system sales recorded across the shift.

---

## 2. Technical Specifications

### 2.1 Printable Receipt Modal (`PosReceiptModal.tsx`)
- **Structure**:
  - Modal container displaying a formatted 80mm thermal receipt slip preview.
  - Receipt Header: Company Name, Operating Unit / Store, Date & Time, Cashier / User, Order Reference Number (`POS-xxxxxxxx`).
  - Line Items: Item name, quantity, unit price (LYD), line total.
  - Payment Summary:
    - Total Gross Amount (LYD)
    - Payment Method (نقد / بطاقة)
    - Cash Tendered (المبلغ المستلم) & Change Returned (المتبقي للعميل)
  - Footer: "شكراً لتعاملكم معنا - فاين للصناعات والإسفنج"
- **Print Execution**:
  - Direct print trigger via `window.print()` with `@media print` CSS isolating only the receipt card and stripping out navigation / backdrop.

### 2.2 End-of-Day Daily Register Close Modal (`PosDailyCloseModal.tsx`)
- **Data Source**: `GET /api/v1/pos/daily-report` (via `usePosDailyReport()` hook).
- **Structure**:
  - Shift / Date header.
  - Overall Summary: Total transactions count, Total Sales Volume (LYD), Estimated Cost of Goods Sold.
  - Breakdown by Payment Method:
    - النقد (Cash): Transaction count and expected cash total.
    - البطاقات المصرفية (Card): Transaction count and electronic settlements total.
  - Cash Drawer Reconciliation:
    - User input: **النقد الفعلي المعدود في الصندوق (Counted Cash in Drawer)**.
    - Real-time difference indicator:
      - إذا كان الفارق 0: متطابق تماماً (`text-app-status-positive`).
      - إذا كان هناك نقص: عجز في الصندوق (`text-app-status-danger`).
      - إذا كان هناك زيادة: زيادة في الصندوق (`text-app-status-warning`).
  - Print / Export Summary button to generate a printed closing Z-Report for accounting.

### 2.3 POS Main Screen Polish (`PosPage.tsx`)
- Full RTL and Arabic localization.
- Fast-cart interactions: search filter, add-to-cart, quantity and price inline editing, clear cart.
- Cash tendering quick calculations.
- Integrated trigger for `PosReceiptModal` on checkout success.
- Header action button to open `PosDailyCloseModal`.

---

## 3. Verification Plan

1. **Frontend Typecheck:** Run `npx tsc --noEmit` in `fine-desktop`.
2. **Backend Tests:** Run `php artisan test --compact --filter=SalesAndPosTest` to verify POS checkout and daily report APIs.
