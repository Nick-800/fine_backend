# Phase 01: Foundation, Auth & Dynamic Unit Provisioning

> **Duration Estimate:** 4–5 weeks  
> **Team Size:** 2 backend engineers, 1 frontend engineer  
> **Dependencies:** None (this is the foundational phase)

---

## 1.1 Objective

Establish the technical backbone of the ERP: Laravel API scaffolding, PostgreSQL schema for core entities, authentication/authorization system with layered RBAC, dynamic operating unit provisioning, and the core infrastructure patterns (optimistic locking, soft deletes, audit logging, polymorphic references) that every subsequent module will build upon.

---

## 1.2 Deliverables

| # | Deliverable | Acceptance Criteria |
|---|-------------|---------------------|
| 1 | Laravel API skeleton with Docker Compose (App + PostgreSQL + Redis) | `php artisan serve` boots; PHPUnit passes; PSR-12 compliance enforced via CI |
| 2 | PostgreSQL migration set for core tables | All tables in Sections 1–2 of `erd.md` migrated; seeders for demo company + 5 initial units |
| 3 | Authentication system | JWT or Sanctum token auth; login/logout/refresh; password hashing (bcrypt) |
| 4 | Layered RBAC | Roles, Permissions, UserRoles with unit scoping; permission middleware on all routes |
| 5 | Optimistic locking trait | Reusable `HasOptimisticLocking` trait on all editable models; version mismatch returns 409 Conflict |
| 6 | Soft delete + audit logging | `SoftDeletes` on all financially relevant tables; `AuditLog` model auto-populated via observers |
| 7 | Unit provisioning API | Endpoint to create new `OperatingUnit` from `UnitBlueprint`; auto-creates warehouse, scoped role, inventory config |
| 8 | Base unit web client scaffold | One React/Vue SPA scaffold that can be deployed per unit; reads unit config from API to show correct nav/workflow |
| 9 | Next.js Owner Dashboard scaffold | Next.js app with auth integration; role-gated (Owner-only); empty shell ready for KPI widgets |

---

## 1.3 Database Migrations (This Phase)

### 1.3.1 Core Foundation
- `companies`
- `operating_units`
- `unit_blueprints`
- `warehouses`

### 1.3.2 Auth & Audit
- `users`
- `roles`
- `user_roles`
- `permissions`
- `role_permissions`
- `audit_logs`

### 1.3.3 Unified Entity System
- `entities`
- `entity_roles`
- `entity_contacts`
- `employees`
- `clients`
- `external_employers`

### 1.3.4 Inventory Master Data (Headers Only)
- `inventory_items`
- `chart_of_accounts` (header)
- `accounts` (minimal seed for later expansion)

---

## 1.4 API Endpoints (This Phase)

### Auth
```
POST   /api/v1/auth/login
POST   /api/v1/auth/logout
POST   /api/v1/auth/refresh
GET    /api/v1/auth/me
```

### Central API & Operating Unit Scoping
All requests require Sanctum Bearer token and `X-Operating-Unit-ID` HTTP header for tenant isolation. Context middleware (`ScopeOperatingUnit`) automatically scopes database queries per operating unit.

### Users & Roles
```
GET    /api/v1/users
POST   /api/v1/users
GET    /api/v1/users/{id}
PUT    /api/v1/users/{id}
DELETE /api/v1/users/{id}
GET    /api/v1/users/{id}/roles
POST   /api/v1/users/{id}/roles/assign
DELETE /api/v1/users/{id}/roles/{roleId}

GET    /api/v1/roles
POST   /api/v1/roles
GET    /api/v1/roles/{id}/permissions
PUT    /api/v1/roles/{id}/permissions
```

### Unified Entity System
```
GET    /api/v1/entities
POST   /api/v1/entities
POST   /api/v1/entities/{id}/provision-user
GET    /api/v1/employees
GET    /api/v1/clients
GET    /api/v1/external-employers
```

### Operating Units
```
GET    /api/v1/operating-units
POST   /api/v1/operating-units              ← triggers provisioning
GET    /api/v1/operating-units/{id}
PUT    /api/v1/operating-units/{id}
DELETE /api/v1/operating-units/{id}
GET    /api/v1/unit-blueprints
POST   /api/v1/unit-blueprints
```

### Audit
```
GET    /api/v1/audit-logs
GET    /api/v1/audit-logs/{table}/{recordId}
```

---

## 1.5 Key Business Rules

| Rule ID | Description | Enforcement |
|---------|-------------|-------------|
| AUTH-01 | Email must be unique across the company | DB unique constraint + validation |
| AUTH-02 | A user may hold multiple roles simultaneously | `user_roles` many-to-many with unit scope |
| AUTH-03 | Every API request must carry a valid token and pass permission check | Middleware chain: `auth:sanctum` → `check.permission` |
| AUTH-04 | Unit managers can only see/edit records within their own unit | Query scope `->where('operating_unit_id', auth()->user()->unit_id)` applied via global scope or policy |
| AUTH-05 | Optimistic locking: PUT/PATCH must include `record_version`; mismatch returns 409 | `HasOptimisticLocking` trait on all models |
| AUTH-06 | No hard deletes on any table with financial or inventory impact | `SoftDeletes` trait; DELETE routes call `->delete()` not `->forceDelete()` |
| AUTH-07 | Unit provisioning auto-creates: warehouse, scoped manager role, default inventory config, workflow state machine config | Transactional creation in `OperatingUnitService::provision()` |

---

## 1.6 Technical Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Auth package | Laravel Sanctum | Simpler than Passport for SPA-first architecture; token-based fits browser clients |
| Optimistic locking | Integer `record_version` column | Lightweight; no need for timestamp-based concurrency in this architecture |
| Audit logging | Eloquent Observer pattern | Non-intrusive; auto-fires on create/update/delete without touching business logic |
| Unit scoping | Global query scope on models | Automatic; developers don't need to remember to add `->where('unit_id')` every time |
| Soft deletes | `deleted_at` timestamp | Enables recovery; audit log captures who deleted and when |
| Blueprint provisioning | JSON config columns | Flexible enough to store workflow sets and role templates without schema changes per new unit type |

---

## 1.7 Testing Strategy

- **Unit tests:** Optimistic locking trait, permission middleware, provisioning service
- **Feature tests:** Full auth flow (login → access protected route → logout), role CRUD, unit provisioning end-to-end
- **Policy tests:** User A (Foam Manager) cannot access Cutter records; Owner can access everything

---

## 1.8 Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| RBAC too complex for v1 | High | Start with 5 base roles; per-user overrides can be deferred to Phase 2 if needed |
| Unit provisioning JSON schema becomes brittle | Medium | Version the blueprint JSON schema; validate against JSON Schema on save |
| Soft deletes bloat queries | Low | Add composite indexes on `deleted_at`; use `->withoutGlobalScope()` only in admin reports |

---

## 1.9 Open Questions to Resolve

1. **Dashboard access:** Should General Manager / Accounting Manager also get Next.js dashboard access? (SRS Section 4.1, 18) — *Default: Owner-only until decided.*
2. **Cross-unit visibility:** Should unit managers have read-only visibility into other units' inventory? (SRS Section 16) — *Default: strictly siloed per unit until decided.*
3. **Self-service unit provisioning scope:** Is v1 limited to cloning existing blueprints, or does it include novel workflow creation? (SRS 19.5/19.8) — *Default: blueprint cloning only.*

---

## 1.10 Phase Exit Criteria

- [ ] All endpoints return correct HTTP status codes and JSON shapes
- [ ] Authentication blocks unauthenticated requests on 100% of protected routes
- [ ] Authorization test matrix passes (at least 20 permission scenarios)
- [ ] Unit provisioning creates a fully functional new unit in one API call
- [ ] Audit log captures every create/update/delete with before/after values
- [ ] Frontend scaffolds (unit client + dashboard) successfully authenticate against API
- [ ] CI/CD pipeline runs lint, test, and migration check on every commit
