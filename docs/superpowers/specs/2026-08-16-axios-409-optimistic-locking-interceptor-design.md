# Axios 409 Conflict Interceptor & Optimistic Locking Dialog Specification

- **Date:** 2026-08-16
- **Status:** Approved
- **Scope:** Backend `bootstrap/app.php`, Frontend `conflictStore.ts`, `client.ts`, `ConflictModal.tsx`, `AppShell.tsx`.

---

## 1. Overview & Business Rationale

To maintain strict data integrity under concurrent multi-user operations, FINE ERP uses optimistic locking via the `record_version` column. When two users simultaneously edit the same entity (e.g. Sales Order, Client, Production Order, Fixed Asset), the second write is rejected by the database with an `OptimisticLockConflictException` (HTTP `409 Conflict`).

Without a centralized interceptor in the frontend desktop client, users encountering a 409 conflict could be left on a stuck form with stale state.

This specification introduces:
1. Standardized backend 409 error code `'OPTIMISTIC_LOCK_CONFLICT'`.
2. A global Axios response interceptor in `fine-desktop` that captures HTTP 409 errors.
3. A global Zustand store `useConflictStore` and a modal dialog `ConflictModal` mounted in `AppShell` that alerts the user and provides a one-click reload mechanism to refresh data.

---

## 2. Technical Specifications

### 2.1 Backend Standardization (`fine_backend/bootstrap/app.php`)
- Standardize the `OptimisticLockConflictException` JSON response format:
  ```php
  $exceptions->render(function (OptimisticLockConflictException $e, Request $request) {
      return response()->json([
          'message' => $e->getMessage(),
          'code' => 'OPTIMISTIC_LOCK_CONFLICT',
      ], 409);
  });
  ```

### 2.2 Frontend Conflict Store (`fine-desktop/src/stores/conflictStore.ts`)
- Zustand store tracking modal state:
  ```typescript
  interface ConflictState {
    isOpen: boolean;
    message: string | null;
    endpoint: string | null;
    triggerConflict: (data: { message?: string; endpoint?: string }) => void;
    dismissConflict: () => void;
  }
  ```

### 2.3 Axios Response Interceptor (`fine-desktop/src/api/client.ts`)
- In `apiClient.interceptors.response`, intercept `error.response?.status === 409`:
  ```typescript
  if (error.response?.status === 409) {
    const message =
      error.response?.data?.message ||
      "تم تعديل هذا السجل بواسطة مستخدم آخر بالتزامن.";
    useConflictStore.getState().triggerConflict({
      message,
      endpoint: error.config?.url,
    });
  }
  ```

### 2.4 Conflict Modal Component (`fine-desktop/src/components/ui/ConflictModal.tsx`)
- Render an accessible modal window with:
  - Title: **تعارض في تحديث البيانات (409 Conflict)**
  - Explanation: Describes that another user has saved updates to this record since the page was loaded.
  - Action buttons:
    - **تحديث الصفحة واسترجاع أحدث البيانات (Reload)**: Calls `window.location.reload()`.
    - **إغلاق (Dismiss)**: Closes the modal.
- Styled using semantic CSS tokens (`bg-app-bg-primary`, `border-app-separator`, `bg-app-status-warning/15 text-app-status-warning`).

### 2.5 AppShell Integration (`fine-desktop/src/components/layout/AppShell.tsx`)
- Mount `<ConflictModal />` inside `AppShell` layout.

---

## 3. Verification Plan

1. **Frontend Typecheck:** Run `npx tsc --noEmit` in `fine-desktop`.
2. **Backend Pint & Tests:** Run `vendor/bin/pint --format agent` and `php artisan test --compact`.
