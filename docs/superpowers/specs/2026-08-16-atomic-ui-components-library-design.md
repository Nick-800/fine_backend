# Centralized Atomic UI Component Library Specification

- **Date:** 2026-08-16
- **Status:** Approved
- **Scope:** `fine-desktop/src/components/ui/` (`Modal.tsx`, `ConfirmDialog.tsx`, `StatusBadge.tsx`, `DataTable.tsx`, `Toast.tsx`, `index.ts`), `fine-desktop/src/stores/toastStore.ts`, `fine-desktop/src/components/layout/AppShell.tsx`.

---

## 1. Overview & Business Rationale

To maintain consistency, accessibility, and high visual excellence across all FINE ERP frontend pages, this specification defines a centralized Atomic UI Component Library matching the Kimi Design System (`phase-f02-design-system.md`).

---

## 2. Technical Specifications

### 2.1 Modal Component (`Modal.tsx`)
- **Props**:
  - `isOpen: boolean`
  - `onClose: () => void`
  - `title?: React.ReactNode`
  - `description?: React.ReactNode`
  - `size?: 'sm' | 'md' | 'lg' | 'xl' | '2xl' | 'full'`
  - `children: React.ReactNode`
  - `footer?: React.ReactNode`
  - `showCloseButton?: boolean`
  - `className?: string`
- **Behavior**:
  - Backdrop blur overlay (`bg-black/60 backdrop-blur-sm`).
  - Keydown event listener for `Escape` key.
  - Smooth scale and opacity enter animations.

### 2.2 ConfirmDialog Component (`ConfirmDialog.tsx`)
- **Props**:
  - `isOpen: boolean`
  - `onClose: () => void`
  - `onConfirm: () => void | Promise<void>`
  - `title: string`
  - `message: string`
  - `confirmText?: string`
  - `cancelText?: string`
  - `variant?: 'danger' | 'warning' | 'primary'`
  - `isLoading?: boolean`
- **Design**:
  - Warning/Danger alert badge header with matching action button.

### 2.3 StatusBadge Component (`StatusBadge.tsx`)
- **Props**:
  - `status: string`
  - `label?: string`
  - `variant?: 'positive' | 'warning' | 'danger' | 'info' | 'neutral' | 'accent' | 'purple'`
  - `size?: 'sm' | 'md'`
  - `className?: string`
- **Mapping**:
  - `draft`, `inactive` $\rightarrow$ neutral
  - `pending`, `pending_approval`, `curing`, `running` $\rightarrow$ warning / yellow / orange
  - `confirmed`, `in_progress`, `active` $\rightarrow$ info / blue
  - `fulfilled`, `paid`, `completed`, `approved`, `closed`, `graded` $\rightarrow$ positive / green
  - `rejected`, `cancelled`, `error`, `danger` $\rightarrow$ danger / red

### 2.4 DataTable Component (`DataTable.tsx`)
- **Props**:
  - `columns: Column<T>[]` (header, key, render, className, align)
  - `data: T[]`
  - `isLoading?: boolean`
  - `emptyMessage?: string`
  - `emptyIcon?: React.ComponentType<{ className?: string }>`
  - `onRowClick?: (item: T) => void`
  - `keyExtractor?: (item: T, index: number) => string | number`
- **Features**:
  - Skeleton loading rows.
  - Hover states and empty feedback.

### 2.5 Toast System (`toastStore.ts` & `Toast.tsx`)
- **Store**: `useToastStore` tracking active toasts with unique ID, message, type, and timeout ID.
- **Convenience API**: `toast.success()`, `toast.error()`, `toast.warning()`, `toast.info()`.
- **UI**: `<ToastContainer />` rendering fixed toast stack at top-start of the viewport (RTL top-right) with entrance and dismissal animations.

### 2.6 Layout Integration (`AppShell.tsx`)
- Mount `<ToastContainer />` at the root layout alongside `<ConflictModal />`.

---

## 3. Verification Plan

1. **Frontend Typecheck:** Run `npx tsc --noEmit` in `fine-desktop`.
2. **Backend Pint & Tests:** Run `php artisan test --compact`.
