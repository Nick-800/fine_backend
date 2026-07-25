# Unified Entity System Design Specification

**Date**: 2026-07-25  
**Status**: Approved / Design Complete  
**Scope**: Support for internal/external entities (Employees, B2B Clients, Third-Party Employers) decoupled from system User accounts.

---

## 1. Overview & Architectural Goals

The Unified Entity System establishes a core entity management layer for the ERP platform. It enables individual workers, B2B corporate clients, suppliers, and third-party employer agencies to exist as first-class domain entities without requiring system `User` login accounts.

### Key Objectives
* **User Decoupling**: Enable business operations (HR payroll, attendance, sales orders, accounts receivable/payable) to function without requiring system login credentials for every person or company.
* **Multi-Role Flexibility**: Allow a single real-world entity (e.g. *Tripoli Contracting Co.*) to act simultaneously as a B2B Client and a Subcontracting Employer without duplicating core contact or tax details.
* **On-Demand Access Provisioning**: Allow optional attachment of a system `User` account when an entity requires software access (e.g. employee self-service or client portal).
* **Audit & Historical Integrity**: Ensure deleting or deactivating a `User` account never breaks historical transaction records, attendance logs, sales invoices, or GL subledger entries.

---

## 2. Database Schema Design

All tables use UUID primary keys (`HasUuids`), optimistic locking (`record_version`), audit tracking (`Auditable`), and soft deletes consistent with codebase patterns.

### 2.1 `entities` Table
Master record for individuals and organizations.

```sql
CREATE TABLE entities (
    id UUID PRIMARY KEY,
    name VARCHAR(255) NOT NULL,                      -- Legal or display name
    entity_type VARCHAR(50) NOT NULL,               -- 'individual' | 'organization'
    tax_number VARCHAR(100) NULL,                   -- Tax / Registration ID (indexed)
    user_id UUID NULL UNIQUE,                       -- Optional foreign key -> users(id) ON DELETE SET NULL
    is_active BOOLEAN DEFAULT TRUE,
    record_version INT DEFAULT 1,                   -- Optimistic locking
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    deleted_at TIMESTAMP NULL
);

CREATE INDEX idx_entities_type ON entities(entity_type);
CREATE INDEX idx_entities_tax_number ON entities(tax_number);
CREATE INDEX idx_entities_user_id ON entities(user_id);
```

### 2.2 `entity_roles` Table
Tracks all operational capacities assigned to an entity.

```sql
CREATE TABLE entity_roles (
    id UUID PRIMARY KEY,
    entity_id UUID NOT NULL REFERENCES entities(id) ON DELETE CASCADE,
    role_type VARCHAR(50) NOT NULL,                -- 'employee' | 'client' | 'vendor' | 'external_employer'
    operating_unit_id UUID NULL REFERENCES operating_units(id) ON DELETE CASCADE,
    created_at TIMESTAMP NOT NULL,
    
    CONSTRAINT uq_entity_role_unit UNIQUE (entity_id, role_type, operating_unit_id)
);

CREATE INDEX idx_entity_roles_lookup ON entity_roles(entity_id, role_type);
```

### 2.3 `entity_contacts` Table
Contact detail records linked to entities.

```sql
CREATE TABLE entity_contacts (
    id UUID PRIMARY KEY,
    entity_id UUID NOT NULL REFERENCES entities(id) ON DELETE CASCADE,
    contact_name VARCHAR(255) NULL,                 -- Primary contact person name
    email VARCHAR(255) NULL,                        -- Contact email
    phone VARCHAR(50) NULL,                         -- Contact phone
    address TEXT NULL,                              -- Physical address
    city VARCHAR(100) NULL,
    country VARCHAR(100) DEFAULT 'LY',
    is_primary BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

CREATE INDEX idx_entity_contacts_entity ON entity_contacts(entity_id);
```

### 2.4 Domain Extension Tables

#### `employees` Table
```sql
CREATE TABLE employees (
    id UUID PRIMARY KEY,
    entity_id UUID NOT NULL UNIQUE REFERENCES entities(id) ON DELETE CASCADE,
    operating_unit_id UUID NOT NULL REFERENCES operating_units(id) ON DELETE RESTRICT,
    employer_entity_id UUID NULL REFERENCES entities(id) ON DELETE RESTRICT, -- Nullable: NULL = Direct internal hire; Set = Subcontracted via Third-Party Employer
    job_title VARCHAR(150) NOT NULL,
    pay_type VARCHAR(50) NOT NULL,                         -- 'hourly' | 'monthly' | 'piece_rate'
    hire_date DATE NOT NULL,
    status VARCHAR(50) DEFAULT 'active',                   -- 'active' | 'terminated' | 'on_leave'
    record_version INT DEFAULT 1,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    deleted_at TIMESTAMP NULL,

    CONSTRAINT chk_employee_not_self_employer CHECK (entity_id != employer_entity_id)
);

CREATE INDEX idx_employees_unit ON employees(operating_unit_id);
CREATE INDEX idx_employees_employer ON employees(employer_entity_id);
```

#### `clients` Table
```sql
CREATE TABLE clients (
    id UUID PRIMARY KEY,
    entity_id UUID NOT NULL UNIQUE REFERENCES entities(id) ON DELETE CASCADE,
    operating_unit_id UUID NOT NULL REFERENCES operating_units(id) ON DELETE RESTRICT,
    credit_limit DECIMAL(15, 4) DEFAULT 0.0000,
    payment_terms_days INT DEFAULT 30,
    account_id UUID NULL REFERENCES accounts(id) ON DELETE RESTRICT,
    status VARCHAR(50) DEFAULT 'active',
    record_version INT DEFAULT 1,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

CREATE INDEX idx_clients_unit ON clients(operating_unit_id);
```

#### `external_employers` Table
```sql
CREATE TABLE external_employers (
    id UUID PRIMARY KEY,
    entity_id UUID NOT NULL UNIQUE REFERENCES entities(id) ON DELETE CASCADE,
    contract_reference VARCHAR(100) NULL,
    billing_rate_multiplier DECIMAL(5, 2) DEFAULT 1.00,
    account_id UUID NULL REFERENCES accounts(id) ON DELETE RESTRICT,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
```

---

## 3. Backed Enums & Domain Types

PHP 8.4 Enums will enforce type safety across controllers, service layers, and models.

```php
namespace App\Enums;

enum EntityType: string
{
    case Individual = 'individual';
    case Organization = 'organization';
}

enum EntityRoleType: string
{
    case Employee = 'employee';
    case Client = 'client';
    case Vendor = 'vendor';
    case ExternalEmployer = 'external_employer';
}

enum PayType: string
{
    case Hourly = 'hourly';
    case Monthly = 'monthly';
    case PieceRate = 'piece_rate';
}

enum EmployeeStatus: string
{
    case Active = 'active';
    case Terminated = 'terminated';
    case OnLeave = 'on_leave';
}

enum ClientStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Blacklisted = 'blacklisted';
}
```

---

## 4. User Provisioning & Decoupling Workflow

### 4.1 Non-User Default Mode
1. Entity, Employee, Client, and External Employer records are created via REST APIs by authorized administrative users.
2. `entities.user_id` remains `NULL`.
3. All domain operations (attendance logging, sales ordering, payroll calculation, invoice generation) consume `entity_id`, `employee_id`, or `client_id`.

### 4.2 Account Provisioning (`POST /api/v1/entities/{id}/provision-user`)
1. **Authorization Check**: Requiring `users.create` permission.
2. **Validation**: Ensures entity does not already have an active `user_id` and has a primary contact email.
3. **Execution**:
   - Creates `User` record with `name` and `email` from primary contact, random temporary password, `must_change_password = true`, `is_active = true`.
   - Updates `entities.user_id = user.id`.
   - Assigns corresponding system roles via `user_roles` based on active `entity_roles` (e.g. `employee-self-service` role).
4. **Response**: Returns `UserResource` with 201 Created.

### 4.3 Resilience & Deprovisioning
- If a `User` account is deleted or deactivated (`is_active = false`), `entities.user_id` is set to `NULL` via FK cascade rule or explicit model event.
- No payroll, sales, or financial transaction histories are affected because foreign keys reference `entities.id`, `employees.id`, or `clients.id`.

---

## 5. API Endpoints & Routing

Registered under `/api/v1` protected by `auth:sanctum`, `ensure.password.updated`, and `scope.unit` middleware:

```php
// Unified Entities
Route::apiResource('entities', EntityController::class);
Route::post('/entities/{id}/provision-user', [EntityController::class, 'provisionUser']);

// Domain Modules
Route::apiResource('employees', EmployeeController::class);
Route::apiResource('clients', ClientController::class);
Route::apiResource('external-employers', ExternalEmployerController::class);
```

---

## 6. Verification & Quality Plan

### 6.1 Pest PHP Feature Tests
* `tests/Feature/EntityManagementTest.php`:
  * Entity creation without user account (`user_id` is null).
  * Assigning multiple roles to a single entity.
  * Validation checks: prevent self-employer assignment (`chk_employee_not_self_employer`).
  * Provisioning a `User` account sets `user_id` and assigns default roles.
  * Soft delete entity does not cascade-delete financial transactions.
* `tests/Feature/EmployeeManagementTest.php`:
  * CRUD for internal employees vs third-party subcontracted employees.
* `tests/Feature/ClientManagementTest.php`:
  * Credit limit and payment terms configuration.

### 6.2 Code Style & Linting
- Format dirty PHP files with `vendor/bin/pint --dirty --format agent`.
