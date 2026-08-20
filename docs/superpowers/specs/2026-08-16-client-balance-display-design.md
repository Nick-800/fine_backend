# Client Balance and Credit Headroom Display Specification

- **Date:** 2026-08-16
- **Status:** Approved
- **Scope:** Backend `ClientResource` & `Client` model casts, Frontend `entities.ts` types, and `ClientsPage.tsx` table UI.

---

## 1. Overview & Business Rationale

External sales orders in FINE ERP are credit-gated (`SalesOrderService::submit`). When external sales orders are fulfilled, `$client->current_balance` increases by the order total amount; when customer payments are recorded, `$client->current_balance` decreases accordingly.

Previously, the `ClientResource` API omitted the `current_balance` attribute from its JSON serialization, preventing the frontend `ClientsPage` from displaying a customer's current outstanding balance or remaining credit headroom.

---

## 2. Backend Specifications (`fine_backend`)

### 2.1 Model Casts (`App\Models\Client`)
- Add `'current_balance' => 'decimal:4'` to `Client::casts()`.

### 2.2 API Resource (`App\Http\Resources\v1\ClientResource`)
- Add `'current_balance' => (float) $this->current_balance` (or numeric equivalent) to `ClientResource::toArray()`.

### 2.3 Automated Testing
- Update or add a Pest feature test verifying that `GET /api/v1/clients` and `GET /api/v1/clients/{id}` return `current_balance` accurately formatted as a numeric float.

---

## 3. Frontend Desktop Specifications (`fine-desktop`)

### 3.1 Type Definitions (`src/types/entities.ts`)
- Update `Client` interface:
  ```typescript
  export interface Client {
    id: string;
    entity_id: string;
    operating_unit_id: string;
    credit_limit: string | number;
    current_balance?: string | number;
    payment_terms_days: number;
    account_id?: string | null;
    status: ClientStatus;
    record_version?: number;
    entity?: Entity;
    created_at?: string;
    updated_at?: string;
  }
  ```

### 3.2 UI Table Presentation (`src/pages/clients/ClientsPage.tsx`)
- Table Columns:
  1. **اسم العميل / الكيان** (Client Name & Entity)
  2. **الحد الائتماني (LYD)** (Credit Limit)
  3. **الرصيد المستحق (LYD)** (Current Balance) — styled with semantic status indicators if balance $> 0$.
  4. **المتبقي من الائتمان (LYD)** (Available Credit Headroom = `credit_limit - current_balance`) — displays available buffer or an over-limit badge if `headroom < 0`.
  5. **فترة السداد الآجل** (Payment Terms Days)
  6. **الحالة** (Status) — active, suspended, blacklisted with semantic badges (`bg-app-status-positive/10`, `text-app-status-positive`, etc.).
  7. **إجراءات** (Actions) — Split entity button.
- Refactor any raw hardcoded Tailwind colors (e.g. `bg-emerald-100`, `bg-red-50`) to semantic design system tokens (`bg-app-status-*`, `text-app-status-*`).

---

## 4. Verification Plan

1. **Backend Tests:** Run `php artisan test --compact --filter=ClientTest` (or equivalent feature tests) to verify all tests pass.
2. **Style Formatting:** Run `vendor/bin/pint --format agent` to verify PHP formatting.
3. **Frontend Typecheck:** Run `npx tsc --noEmit` in `fine-desktop` to verify clean TypeScript compilation.
