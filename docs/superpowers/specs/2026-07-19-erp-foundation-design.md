# Phase 01: Foundation, Auth & Dynamic Unit Provisioning — Design Specification

> **Date:** 2026-07-19  
> **Status:** Draft  
> **Topic:** ERP Core Architecture, Authentication, Scoped RBAC, Optimistic Locking, Audit Trails, and Operating Unit Provisioning.

---

## 1. Objectives & Scope

This specification defines the foundation layer of the ERP backend. It establishes the database conventions, authentication, unit-based multi-tenancy (via scoping headers), audit logging, and dynamic unit provisioning mechanisms.

---

## 2. Database Schema (aligned with `erd.md`)

All primary keys use UUIDs (`uuid_generate_v4()`). Timestamps (`created_at`, `updated_at`) are present on all tables.

### 2.1 Core Foundation
*   **`companies`**
    *   `id`: UUID (Primary Key)
    *   `name`: VARCHAR(255)
    *   `default_currency`: VARCHAR(3) (e.g. 'USD', 'LYD')
    *   `overhead_absorption_enabled`: BOOLEAN (default `false`)
    *   `transfer_pricing_mode`: VARCHAR(50) (e.g. 'at_cost')
    *   `timezone`: VARCHAR(100) (default 'Africa/Tripoli')
*   **`unit_blueprints`**
    *   `id`: UUID (Primary Key)
    *   `name`: VARCHAR(255)
    *   `workflow_set`: JSONB (defines default workflows, states, gates)
    *   `default_role_template`: JSONB (roles and permission slugs to auto-create)
    *   `default_inventory_config`: JSONB (warehouse rules and settings)
*   **`operating_units`**
    *   `id`: UUID (Primary Key)
    *   `company_id`: UUID (Foreign Key -> `companies.id`)
    *   `blueprint_id`: UUID (Foreign Key -> `unit_blueprints.id`)
    *   `name`: VARCHAR(255)
    *   `unit_type`: VARCHAR(50) (Enum: 'manufactory', 'store', 'office')
    *   `currency`: VARCHAR(3)
    *   `status`: VARCHAR(50) (Enum: 'provisioning', 'active', 'inactive')
*   **`warehouses`**
    *   `id`: UUID (Primary Key)
    *   `operating_unit_id`: UUID (Foreign Key -> `operating_units.id`)
    *   `name`: VARCHAR(255)
    *   `is_internal_unit`: BOOLEAN (default `false`)

### 2.2 Authentication & RBAC
*   **`users`**
    *   `id`: UUID (Primary Key)
    *   `name`: VARCHAR(255)
    *   `email`: VARCHAR(255) (Unique Index)
    *   `password`: VARCHAR(255) (Bcrypt hash)
    *   `is_active`: BOOLEAN (default `true`)
    *   `record_version`: INTEGER (default `1`)
    *   `deleted_at`: TIMESTAMP (Soft deletes)
*   **`roles`**
    *   `id`: UUID (Primary Key)
    *   `name`: VARCHAR(255) (Unique Index)
    *   `slug`: VARCHAR(255) (Unique Index, e.g. 'foam-manager')
    *   `description`: TEXT
*   **`user_roles`**
    *   `id`: UUID (Primary Key)
    *   `user_id`: UUID (Foreign Key -> `users.id`)
    *   `role_id`: UUID (Foreign Key -> `roles.id`)
    *   `operating_unit_id`: UUID (Foreign Key -> `operating_units.id`, Nullable. Null represents company-wide/Owner access)
*   **`permissions`**
    *   `id`: UUID (Primary Key)
    *   `name`: VARCHAR(255) (Unique Index)
    *   `slug`: VARCHAR(255) (Unique Index, e.g. 'view-inventory')
    *   `module`: VARCHAR(50) (e.g. 'procurement', 'inventory')
    *   `action`: VARCHAR(50) (Enum: 'view', 'create', 'edit', 'approve', 'delete')
*   **`role_permissions`**
    *   `id`: UUID (Primary Key)
    *   `role_id`: UUID (Foreign Key -> `roles.id`)
    *   `permission_id`: UUID (Foreign Key -> `permissions.id`)

### 2.3 Audit Logs
*   **`audit_logs`**
    *   `id`: UUID (Primary Key)
    *   `user_id`: UUID (Foreign Key -> `users.id`, Nullable for system actions)
    *   `operating_unit_id`: UUID (Foreign Key -> `operating_units.id`, Nullable)
    *   `table_name`: VARCHAR(255)
    *   `record_id`: UUID
    *   `action`: VARCHAR(50) (Enum: 'create', 'update', 'delete')
    *   `old_values`: JSONB (Nullable)
    *   `new_values`: JSONB (Nullable)
    *   `ip_address`: VARCHAR(45) (Nullable)

### 2.4 Minimal Seed Headers (Inventory & Ledger)
*   **`inventory_items`**
    *   `id`: UUID (Primary Key)
    *   `name`: VARCHAR(255)
    *   `sku`: VARCHAR(100) (Unique Index)
    *   `item_type`: VARCHAR(50) (Enum: 'raw_material', 'foam_block', 'cut_template_piece', 'slice', 'byproduct_fill', 'furniture_finished_good', 'packaging', 'barrel', 'pallet')
    *   `unit_of_measure`: VARCHAR(50) (Enum: 'each', 'm3', 'kg', 'meter', 'liter')
*   **`chart_of_accounts`**
    *   `id`: UUID (Primary Key)
    *   `company_id`: UUID (Foreign Key -> `companies.id`)
    *   `name`: VARCHAR(255)
*   **`accounts`**
    *   `id`: UUID (Primary Key)
    *   `chart_of_accounts_id`: UUID (Foreign Key -> `chart_of_accounts.id`)
    *   `account_code`: VARCHAR(50) (Unique Index)
    *   `name`: VARCHAR(255)
    *   `type`: VARCHAR(50) (Enum: 'asset', 'liability', 'equity', 'revenue', 'expense')
    *   `currency`: VARCHAR(3)
    *   `parent_account_id`: UUID (Foreign Key -> `accounts.id`, Nullable)

---

## 3. Architecture & Code Design

### 3.1 Unit Scoping Context Middleware
Every protected request reads the operating unit ID from the `X-Operating-Unit-ID` header.
*   **Context Class (`App\Support\CurrentUnitContext`):**
    *   A singleton storing the active `OperatingUnit` model for the duration of the request.
*   **Middleware (`App\Http\Middleware\ScopeOperatingUnit`):**
    *   Verifies that `X-Operating-Unit-ID` is present (returns `400 Bad Request` if missing on unit-scoped routes).
    *   Checks if the authenticated user has a matching `user_roles` record targeting that specific unit.
        *   If the user has a company-wide role (`operating_unit_id` is null), access is granted automatically.
    *   If no matching role exists, returns `403 Forbidden`.
    *   Stores the operating unit inside `CurrentUnitContext`.

### 3.2 Automated Scoping
*   **Trait (`App\Models\Traits\BelongsToOperatingUnit`):**
    *   Registers a global query scope `OperatingUnitScope`.
    *   Filters all select queries automatically: `where('operating_unit_id', app(CurrentUnitContext::class)->id())`.
    *   Listens to the `creating` event on the model to automatically populate the `operating_unit_id` with the active context value.

### 3.3 Concurrency Control (Optimistic Locking)
*   **Trait (`App\Models\Traits\HasOptimisticLocking`):**
    *   Defines a custom Eloquent event listener on `saving`.
    *   When an update is occurring, it checks if the model has a `record_version` attribute.
    *   Performs a safe database update where version matches the loaded version.
    *   If the database version has changed (affected rows = 0), it aborts the save and throws an `App\Exceptions\OptimisticLockConflictException`.
    *   The `OptimisticLockConflictException` is mapped in `bootstrap/app.php` to return an HTTP `409 Conflict` response with error details.

### 3.4 Audit Trail
*   **Observer (`App\Observers\AuditObserver`):**
    *   Observed models use an `Auditable` interface/trait.
    *   **On Create:** Records all fields into `new_values`.
    *   **On Update:** Identifies changes using `$model->getDirty()` vs `$model->getOriginal()`. Only changed values are written to `old_values` and `new_values`.
    *   **On Delete:** Records the final state into `old_values`.
    *   Automatically reads `auth()->id()`, the current IP address, and `app(CurrentUnitContext::class)->id()` if available.

### 3.5 Dynamic Operating Unit Provisioning
*   **Service (`App\Services\OperatingUnitService`):**
    *   `provision(UnitBlueprint $blueprint, string $name): OperatingUnit`
    *   Execution is wrapped entirely in a database transaction (`DB::transaction`).
    *   Creates the `OperatingUnit` record with `status => 'provisioning'`.
    *   Creates a corresponding `Warehouse` based on `blueprint->default_inventory_config`.
    *   Creates scoped roles (e.g., manager, worker) according to `blueprint->default_role_template` and maps default permissions.
    *   Updates status to `active`.

---

## 4. API Endpoints

### 4.1 Authentication
```
POST   /api/v1/auth/login     -> Authenticates credentials, returns Sanctum token
POST   /api/v1/auth/logout    -> Revokes current token
GET    /api/v1/auth/me        -> Returns user profile and active scopes
```

### 4.2 Operating Units & Blueprints
```
GET    /api/v1/operating-units
POST   /api/v1/operating-units              <- Triggers provisioning service
GET    /api/v1/operating-units/{id}
PUT    /api/v1/operating-units/{id}         <- Scoped, requires record_version
DELETE /api/v1/operating-units/{id}         <- Soft deletes unit
GET    /api/v1/unit-blueprints
POST   /api/v1/unit-blueprints
```

### 4.3 Users & Roles CRUD
```
GET    /api/v1/users
POST   /api/v1/users
GET    /api/v1/users/{id}
PUT    /api/v1/users/{id}                  <- Scoped, requires record_version
DELETE /api/v1/users/{id}                  <- Soft deletes user
GET    /api/v1/users/{id}/roles
POST   /api/v1/users/{id}/roles/assign     <- Assigns role with unit scoping
DELETE /api/v1/users/{id}/roles/{roleId}   <- Unassigns role
```

### 4.4 Audit Logs
```
GET    /api/v1/audit-logs
GET    /api/v1/audit-logs/{tableName}/{recordId}
```

---

## 5. Verification Plan

### 5.1 Automated Tests (Pest)
We will create integration and unit tests under the following namespaces:

1.  **`Tests\Feature\AuthTest`:**
    *   Tests credential exchange, token generation, and secure logout.
2.  **`Tests\Feature\OperatingUnitScopingTest`:**
    *   Tests that routes carrying `X-Operating-Unit-ID` correctly authorize or block users.
    *   Tests that query scoping filters results based on the header unit.
3.  **`Tests\Feature\OptimisticLockingTest`:**
    *   Tests that concurrent modifications on `User` or `OperatingUnit` throw a conflict exception and return a `409` HTTP response.
4.  **`Tests\Feature\AuditLogObserverTest`:**
    *   Tests that write operations on audited models automatically populate `audit_logs` with before-and-after attributes.
5.  **`Tests\Feature\UnitProvisioningTest`:**
    *   Tests that calling the provisioning endpoint creates all scoped child entities transactionally and cleanly.

### 5.2 Manual Verification
*   Execute Pest test suite: `php artisan test --compact`
*   Run Laravel Pint style checks: `vendor/bin/pint --dirty`
