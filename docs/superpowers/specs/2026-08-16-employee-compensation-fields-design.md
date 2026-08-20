# Employee Compensation and Labor Role Specification

- **Date:** 2026-08-16
- **Status:** Approved
- **Scope:** Backend `EmployeeController::store`, Frontend `entities.ts` types, and `EmployeesPage.tsx` table & modal UI.

---

## 1. Overview & Business Rationale

In Phase 09 (HR & Payroll) and Phase 06 (Furniture Manufacturing):
- Production labor logging (`ProductionOrderService::logLabor`) snapshots hourly labor rates for direct workers using `LaborRoleRate::rateFor($employee->labor_role, $work_date)`, falling back to the employee's `hourly_rate` or BOM rate.
- Payroll calculation (`PayrollService::calculate`) calculates base pay using `employee->monthly_salary` for monthly salaried employees, and computes hourly earnings using `employee->labor_role` rate lookup or `employee->hourly_rate`.

Previously, while the backend database migration and `Employee` model contained `labor_role`, `monthly_salary`, and `hourly_rate`, the `EmployeeController::store()` method did not pass these parameters into `Employee::create()`, and the frontend `EmployeesPage.tsx` modal form and table omitted these fields.

---

## 2. Backend Specifications (`fine_backend`)

### 2.1 Controller Implementation (`App\Http\Controllers\Api\v1\EmployeeController`)
- In `store()` method, include `'labor_role'`, `'monthly_salary'`, and `'hourly_rate'` in the `Employee::create()` payload:
  ```php
  $employee = Employee::create([
      'entity_id' => $entityId,
      'operating_unit_id' => $request->operating_unit_id,
      'employer_entity_id' => $request->employer_entity_id,
      'job_title' => $request->job_title,
      'labor_role' => $request->labor_role,
      'pay_type' => $request->pay_type,
      'monthly_salary' => $request->monthly_salary,
      'hourly_rate' => $request->hourly_rate,
      'hire_date' => $request->hire_date,
      'status' => $request->input('status', 'active'),
  ]);
  ```

### 2.2 Automated Testing (`tests/Feature/EntityManagementTest.php`)
- Add Pest test verifying that `POST /api/v1/employees` saves `labor_role`, `monthly_salary`, and `hourly_rate`, and that `EmployeeResource` serializes them accurately.

---

## 3. Frontend Desktop Specifications (`fine-desktop`)

### 3.1 Type Definitions (`src/types/entities.ts`)
- Update `Employee` and `CreateEmployeePayload` interfaces:
  ```typescript
  export interface Employee {
    id: string;
    entity_id: string;
    operating_unit_id: string;
    employer_entity_id?: string | null;
    job_title: string;
    labor_role?: string | null;
    pay_type: PayType;
    monthly_salary?: string | number | null;
    hourly_rate?: string | number | null;
    hire_date: string;
    status: EmployeeStatus;
    record_version?: number;
    entity?: Entity;
    employer_entity?: Entity | null;
    created_at?: string;
    updated_at?: string;
  }

  export interface CreateEmployeePayload {
    entity_id?: string;
    name?: string;
    entity_type?: EntityType;
    tax_number?: string;
    operating_unit_id: string;
    employer_entity_id?: string | null;
    job_title: string;
    labor_role?: string | null;
    pay_type: PayType;
    monthly_salary?: number | null;
    hourly_rate?: number | null;
    hire_date: string;
    status?: EmployeeStatus;
  }
  ```

### 3.2 UI Modal & Table Presentation (`src/pages/employees/EmployeesPage.tsx`)
- **Modal Add Form**:
  - Add **الدور المهني / الحرفي (Labor Role)** input (e.g. `tailor`, `carpenter`, `upholsterer`, `operator`, `assembler`).
  - Conditional wage inputs based on selected `pay_type`:
    - When `pay_type === 'monthly'`: show **الراتب الشهري الأساسي (LYD)**.
    - When `pay_type === 'hourly'`: show **الأجر بالساعة (LYD)** with helper description.
    - When `pay_type === 'piece_rate'`: optional rate/note.
- **Table Columns**:
  - Show job title with labor role badge if present (`emp.labor_role`).
  - Show compensation details under pay system (e.g. `1,800 د.ل / شهرياً`, `15 د.ل / ساعة`).
  - Refactor raw Tailwind styling classes to semantic design tokens (`bg-app-status-*`, `text-app-status-*`).

---

## 4. Verification Plan

1. **Backend Tests:** Run `php artisan test --compact --filter=EntityManagementTest`.
2. **Pint Code Formatter:** Run `vendor/bin/pint --format agent`.
3. **Frontend Typecheck:** Run `npx tsc --noEmit` in `fine-desktop`.
