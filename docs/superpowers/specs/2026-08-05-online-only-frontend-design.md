# Online-Only Frontend Architecture Design Specification

**Date**: 2026-08-05  
**Status**: Approved  
**Scope**: Define the frontend client architecture for Electron desktop clients (`fine-desktop`) and web dashboards under the 100% Direct Online-Only Local Server Model.

---

## 1. Overview & Core Architectural Principles

The ERP frontend application (`fine-desktop` & web clients) communicates directly with the central Laravel local server/VPS API over HTTP/HTTPS REST endpoints.

### Key Principles:
1. **Always-Online Direct REST API**: No local SQLite databases, IndexedDB outboxes, or client-side sync engines.
2. **Sanctum Auth & Multi-Tenancy**: Authorization via Sanctum Bearer tokens (`Authorization: Bearer <token>`) and operating unit isolation header (`X-Operating-Unit-ID`).
3. **Server State Management**: `@tanstack/react-query` (React Query) manages query caching, stale-while-revalidate data fetching, and optimistic UI updates upon successful REST mutations.
4. **Optimistic Locking Concurrency**: Form mutations pass `record_version` column to central API; `409 Conflict` HTTP errors trigger an inline refresh prompt displaying server-authoritative data.
5. **Real-time Server Health & Disconnect UX**: Live network overlay ("Connecting to Central Local Server...") blocks form submissions when LAN/VPS connectivity drops, preventing unverified local states.

---

## 2. API Communication & Interceptor Stack

The frontend repository pattern (`fine-desktop/src/api/client.ts`) uses Axios configured with:

```typescript
// Centralized Axios Configuration
import axios from 'axios';

export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || 'http://192.168.1.100:8000/api/v1',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  timeout: 15000,
});

// Interceptor: Inject Sanctum Bearer Token & Operating Unit Scoping Header
apiClient.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  const operatingUnitId = localStorage.getItem('operating_unit_id');

  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  if (operatingUnitId) {
    config.headers['X-Operating-Unit-ID'] = operatingUnitId;
  }
  return config;
});
```

---

## 3. Phase-by-Phase Frontend Alignment

| Phase | Module | Frontend Online Architecture |
|-------|--------|------------------------------|
| **F01** | Electron Shell & Auth | Sanctum token login, password change modal enforcement, unit switcher header injecting `X-Operating-Unit-ID`. |
| **F02** | Design System & RTL | Kimi design tokens, Tailwind v4 RTL layout flipper (`dir="rtl"`), accessible form components. |
| **F03** | API & State | TanStack Query provider, global error boundary for 401/403/409 HTTP status codes. |
| **F04** | App Shell | Role-gated navigation bar according to user operating unit assignment. |
| **F05** | Procurement & Treasury | Direct REST API purchase orders, supplier management, landed cost calculator, FX payment requests. |
| **F06** | Inventory Management | Real-time inventory item lookup, warehouse movement posting, serial number scan validation against central DB. |
| **F07** | Foam & Cutter Mfg | Live chemical batch logging, block grading form, cutting work order execution against central API. |
| **F08** | Furniture & POS | Direct online POS checkout with live credit check validation against central client ledger; BOM assembly orders. |
| **F09** | Accounting & HR | Real-time double-entry trial balance query, attendance scan logging, payroll state machine approval UI. |
| **F10** | Owner Dashboard | Next.js / Electron high-level KPI dashboard polling central backend analytics services. |

---

## 4. Verification & Testing Standards

- **Unit Tests**: Form validation schemas (Zod) and UI components (Vitest + React Testing Library).
- **Integration Tests**: React Query API hooks mocked via MSW (Mock Service Worker).
- **End-to-End Tests**: Playwright / Electron testing for login, navigation, and live API form submissions.
