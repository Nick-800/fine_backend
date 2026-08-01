# Foam-to-Furniture Manufacturing ERP — Implementation Master Plan

> **Document:** Implementation Master Plan  
> **Based on:** ERP_SRS_Foam_Furniture.docx  
> **Stack:** Laravel API + PostgreSQL (VPS) | Browser-based Unit Clients | Next.js Owner Dashboard  
> **Version:** 1.0 — Draft for Development Team Review

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Architecture Summary](#2-architecture-summary)
3. [Phase Roadmap](#3-phase-roadmap)
4. [Cross-Phase Dependencies](#4-cross-phase-dependencies)
5. [Team Structure & Responsibilities](#5-team-structure--responsibilities)
6. [Tech Stack Details](#6-tech-stack-details)
7. [Data Model Overview](#7-data-model-overview)
8. [Critical Open Questions](#8-critical-open-questions)
9. [Risk Register](#9-risk-register)
10. [Success Criteria](#10-success-criteria)

---

## 1. Project Overview

This plan implements a comprehensive ERP system for a foam-to-furniture manufacturing company operating five distinct units:

| # | Unit | Role |
|---|------|------|
| 1 | Procurement/Import & Treasury | Foreign supplier orders, FX payments, landed cost |
| 2 | Foam Manufactory | Batch production, chemical consumption, block grading |
| 3 | Cutter Manufactory | Work orders, template-based cutting, byproduct generation |
| 4 | Furniture Manufactory | BOM-driven assembly, labor tracking, custom orders |
| 5 | Store/Showroom | POS retail, internal restock requests |

All units roll up to a central, full double-entry accounting ledger. The system supports multi-currency (USD/LYD), landed-cost inventory valuation, serialized batch tracking, and internal unit-to-unit trading.

---

## 2. Architecture Summary

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              CLIENT LAYER                                    │
├─────────────────────────┬─────────────────────────┬─────────────────────────┤
│  Unit Web Clients       │  Next.js Owner Dashboard│  Mobile (future)        │
│  (Browser, per unit)    │  (Browser, Owner only)  │                         │
│  Arabic / RTL           │  Arabic / RTL           │                         │
└──────────┬──────────────┴──────────┬──────────────┴──────────┬──────────────┘
           │                         │                         │
           └─────────────────────────┼─────────────────────────┘
                                     │
                              HTTPS / JSON
                                     │
┌────────────────────────────────────┴───────────────────────────────────────┐
│                           API LAYER (Laravel PHP)                          │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐            │
│  │ Auth & RBAC     │  │ Business Logic  │  │ Auto Accounting │            │
│  │ Middleware      │  │ Services        │  │ Observers       │            │
│  └─────────────────┘  └─────────────────┘  └─────────────────┘            │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐            │
│  │ State Machines  │  │ Journal Engine  │  │ Report Services │            │
│  │ (Enums/Guards)  │  │ (Double-Entry)  │  │ (Aggregations)  │            │
│  └─────────────────┘  └─────────────────┘  └─────────────────┘            │
└────────────────────────────────────┬───────────────────────────────────────┘
                                     │
                              PostgreSQL + Redis
                                     │
┌────────────────────────────────────┴───────────────────────────────────────┐
│                         INFRASTRUCTURE (VPS)                               │
│  PostgreSQL 15+ │ Redis (Cache/Queue) │ Nginx │ Supervisor │ Backups       │
└────────────────────────────────────────────────────────────────────────────┘
```

### Key Architectural Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Simple Offline Sync | Pure Local-First SQLite | UI only talks to local SQLite via Repositories. Background sync engine pushes Outbox actions to server and pulls updates. |
| Single canonical DB | PostgreSQL (Server Source of Truth) | Server is canonical; client pulls using a single `sync_version` BIGINT to prevent clock drift issues. |
| Conflict Resolution | Last-Write-Wins & Additive Deltas | Most records use simple last-write-wins. Inventory and monetary balances sync `quantity_delta` (additive) to eliminate complex conflict merging entirely. |
| Soft deletes | `deleted_at` timestamp | Recovery possible; audit trail preserved; synced soft-deletes propagated via pull diffs. |
| Auto-accounting | Event observers post journals | No manual bookkeeping. When the server processes a synced operational action, background observers generate the strict financial ledgers online. |
| Polymorphic references | `source_document_type` + `id` | Links journal entries & stock movements back to originating events. |

---

## 3. Phase Roadmap

| Phase | Module | Duration | Team | Prerequisites |
|-------|--------|----------|------|---------------|
| [01](phase-01-foundation.md) | Foundation, Auth & Unit Provisioning | 4–5 wks | 2 BE, 1 FE | None |
| [02](phase-02-procurement-treasury.md) | Procurement, Import & Treasury | 4–5 wks | 2 BE, 1 FE | Phase 01 |
| [03](phase-03-inventory.md) | Inventory Management | 3–4 wks | 2 BE, 1 FE | Phase 01 |
| [04](phase-04-foam-manufacturing.md) | Foam Manufacturing | 4–5 wks | 2 BE, 1 FE | Phase 01, 03 |
| [05](phase-05-cutter-manufacturing.md) | Cutter Manufacturing | 4–5 wks | 2 BE, 1 FE | Phase 01, 03, 04 |
| [06](phase-06-furniture-manufacturing.md) | Furniture Manufacturing | 4–5 wks | 2 BE, 1 FE | Phase 01, 03, 05 |
| [07](phase-07-sales-pos.md) | Sales, POS & Credit | 4–5 wks | 2 BE, 1 FE | Phase 01, 03, 04–06 |
| [08](phase-08-accounting.md) | Full Accounting, Overhead & Fixed Assets | 5–6 wks | 2 BE, 1 FE | Phase 01–07 |
| [09](phase-09-hr-payroll.md) | HR & Payroll | 3–4 wks | 1–2 BE, 1 FE | Phase 01 |
| [10](phase-10-dashboard-deployment.md) | Owner Dashboard & Deployment | 3–4 wks | 1 BE, 2 FE | Phase 01–09 |

**Total Estimated Duration:** 38–48 weeks (9–12 months)
**Recommended Approach:** Phases 01–03 can start in parallel after Phase 01 foundation is solid. Phases 04–07 run sequentially. Phase 08 (Accounting) runs parallel with late manufacturing phases. Phase 09 can run parallel with Phase 06–07.

### Optimized Timeline (Parallel Tracks)

```
Month  1    2    3    4    5    6    7    8    9    10   11   12
Track A: [==== Phase 01: Foundation ====]
Track B:      [==== Phase 02: Procurement ====]
Track C:      [==== Phase 03: Inventory ====]
Track D:           [==== Phase 04: Foam ====]
Track E:                [==== Phase 05: Cutter ====]
Track F:                     [==== Phase 06: Furniture ====]
Track G:                          [==== Phase 07: Sales/POS ====]
Track H:                               [======== Phase 08: Accounting ========]
Track I:      [======== Phase 09: HR (parallel) ========]
Track J:                                    [==== Phase 10: Dashboard ====]
                                              [==== Deploy ====]
```

---

## 4. Cross-Phase Dependencies

```
Phase 01 (Foundation)
  ├─► Phase 02 (Procurement)
  ├─► Phase 03 (Inventory)
  │   ├─► Phase 04 (Foam)
  │   │   └─► Phase 05 (Cutter)
  │   │       └─► Phase 06 (Furniture)
  │   │           └─► Phase 07 (Sales/POS)
  │   └─► Phase 07 (Sales/POS) [indirect]
  ├─► Phase 08 (Accounting) [depends on ALL operational modules]
  └─► Phase 09 (HR)
      └─► Phase 06 (Furniture) [labor logging]
      └─► Phase 08 (Accounting) [payroll journals]

Phase 10 (Dashboard) depends on ALL previous phases.
```

### Critical Path

```
Phase 01 → Phase 03 → Phase 04 → Phase 05 → Phase 06 → Phase 07 → Phase 08 → Phase 10
```

**Float:** Phase 02, Phase 09 have some scheduling flexibility but should not be delayed beyond Month 8.

---

## 5. Team Structure & Responsibilities

### Recommended Team

| Role | Count | Responsibilities |
|------|-------|-----------------|
| Tech Lead / Architect | 1 | Overall architecture, code review, technical decisions |
| Senior Backend (Laravel) | 2 | API development, business logic, database design |
| Backend (Laravel) | 2 | Feature implementation, testing |
| Senior Frontend (Next.js/React) | 1 | Dashboard, component library |
| Frontend (React/Vue) | 2 | Unit client screens, POS |
| DevOps / Infrastructure | 1 | VPS setup, CI/CD, monitoring, backups |
| QA Engineer | 1 | Test planning, automation, UAT |
| Product Owner (Client-side) | 1 | Requirements clarification, acceptance, UAT |

### Phase Assignment

| Phase | Primary Team |
|-------|-------------|
| 01 | Tech Lead + 2 Backend + 1 Frontend |
| 02 | 2 Backend + 1 Frontend |
| 03 | 2 Backend + 1 Frontend |
| 04 | 2 Backend + 1 Frontend |
| 05 | 2 Backend + 1 Frontend |
| 06 | 2 Backend + 1 Frontend |
| 07 | 2 Backend + 1 Frontend |
| 08 | 2 Backend + 1 Frontend + Tech Lead |
| 09 | 1–2 Backend + 1 Frontend |
| 10 | 1 Backend + 2 Frontend + DevOps |

---

## 6. Tech Stack Details

### Backend

| Component | Technology | Version |
|-----------|-----------|---------|
| Framework | Laravel | 10.x / 11.x |
| Language | PHP | 8.2+ |
| ORM | Eloquent | bundled |
| Auth | Laravel Sanctum | bundled |
| Queues | Laravel Queues + Redis | bundled |
| Scheduler | Laravel Scheduler + Cron | bundled |
| API Format | JSON:API or REST | custom |
| Validation | Laravel Form Request | bundled |
| Testing | PHPUnit + Pest | bundled |

### Database

| Component | Technology | Notes |
|-----------|-----------|-------|
| Primary DB | PostgreSQL | 15+ |
| Cache | Redis | sessions, queues, cache |
| Migrations | Laravel Migrations | version controlled |
| Seeds | Laravel Seeders | demo data |

### Frontend

| Component | Technology | Notes |
|-----------|-----------|-------|
| Unit Clients | React 18+ or Vue 3 | SPA, RTL, Arabic |
| Dashboard | Next.js 14+ | App Router, SSR/CSR hybrid |
| Styling | Tailwind CSS | RTL support via rtlcss |
| State Management | React Query / SWR | server state |
| Charts | Recharts or Chart.js | KPI widgets |
| HTTP Client | Axios | with interceptors |

### Infrastructure

| Component | Technology |
|-----------|-----------|
| Server OS | Ubuntu 22.04 LTS |
| Web Server | Nginx |
| Process Manager | Supervisor |
| SSL | Let's Encrypt |
| Backups | pg_dump + S3-compatible storage |
| Monitoring | Uptime monitoring + Sentry |
| CI/CD | GitHub Actions / GitLab CI |

---

## 7. Data Model Overview

See [erd.md](erd.md) for the complete Entity Relationship Diagram with 60+ entities.

### Entity Count by Module

| Module | Entities | Key Complexity |
|--------|----------|----------------|
| Foundation | 10 | RBAC with unit scoping, optimistic locking |
| Procurement | 6 | State machine with 9 states |
| Treasury | 3 | FX rate snapshots, bank hold tracking |
| Inventory | 5 | Serialized + weighted-avg costing |
| Foam Mfg | 4 | Batch-to-block serialization (~138 units) |
| Cutter Mfg | 5 | Template vs. requested shape, byproduct yield |
| Furniture Mfg | 6 | BOM versioning, stock reservation |
| Sales/POS | 7 | Credit limit, unified order model |
| Accounting | 10 | Auto-posting, subledgers, depreciation |
| HR | 6 | Payroll state machine, rate versioning |
| **Total** | **~60** | |

---

## 8. Critical Open Questions

These questions from the SRS must be resolved before or during implementation. They are flagged in each phase document.

| # | Question | Affected Phases | Recommended Default |
|---|----------|-----------------|---------------------|
| 1 | Dashboard access: Owner-only or include GM/Accounting Manager? | 01, 10 | Owner-only for v1 |
| 2 | Cross-unit visibility for managers: siloed or read-only? | 01, 03 | Siloed for v1 |
| 3 | POS model: reuse SalesOrder or separate? | 07 | Reuse SalesOrder with channel=pos |
| 4 | Internal transfer pricing: at-cost or transfer price? | 07, 08 | At-cost for v1 |
| 5 | Landed cost: block Complete until confirmed, or estimate + true-up? | 02 | Block until confirmed |
| 6 | Multi-currency: USD⇄LYD only or generic matrix? | 02 | USD⇄LYD for v1 |
| 7 | HR scope: full payroll or labor logs only? | 09 | Full payroll |
| 8 | Grade-based cost discounting: cost or price only? | 03, 04 | Price only for v1 |
| 9 | Full-absorption costing: absorb overhead or period expense? | 08 | Period expense for v1 |
| 10 | Clean remainder restocking: byproduct or new StockLot? | 03, 05 | Byproduct for v1 |
| 11 | Self-service unit provisioning: clone blueprint only? | 01 | Clone existing blueprint only |
| 12 | Libyan statutory payroll: need local legal input | 09 | Build extensible schema; add deductions later |

---

## 9. Risk Register

| # | Risk | Probability | Impact | Mitigation | Owner |
|---|------|------------|--------|------------|-------|
| 1 | Scope creep from open questions | High | High | Document defaults; require sign-off for changes | Tech Lead |
| 2 | Offline connectivity stops unit work | High | High | Recommend 4G/5G backup routers per unit; clear error UI | Product Owner |
| 3 | Serialized inventory performance at scale | Medium | Medium | Pagination, indexing, potential partitioning | Backend Lead |
| 4 | Accounting journal balance errors | Medium | Critical | Extensive unit tests; reconciliation reports; manual review dashboard | Tech Lead |
| 5 | RTL/Arabic UI issues | Medium | Medium | Dedicated QA with Arabic content; browser testing | Frontend Lead |
| 6 | Bank hold tracking without bank API | Medium | Medium | Manual status updates; clear UI for Treasury | Product Owner |
| 7 | Payroll statutory requirements unknown | Medium | High | Extensible deduction schema; deferred to post-v1 | Tech Lead |
| 8 | Database failure without backup | Low | Critical | Daily automated backups; monthly restore tests; off-site storage | DevOps |
| 9 | Team turnover during long project | Medium | High | Code documentation; consistent patterns; bus factor awareness | Tech Lead |
| 10 | Machine integration (foam) unavailable | Medium | Medium | Manual consumption entry; webhook-ready endpoint | Backend Lead |

---

## 10. Success Criteria

The project is considered successfully implemented when:

### Functional
- [ ] All 10 phases complete with passing tests
- [ ] Full import-to-sale material flow works end-to-end
- [ ] Accounting ledger balances on every trial balance
- [ ] Credit limits enforced on external sales
- [ ] Internal transfers skip credit checks
- [ ] POS checkout completes in under 2 seconds
- [ ] Payroll runs produce correct payslips and journal entries

### Non-Functional
- [ ] All unit clients require live API connection (no offline mode)
- [ ] Optimistic locking prevents silent overwrites
- [ ] Audit log captures every financial event
- [ ] Arabic/RTL UI renders correctly on all screens
- [ ] Dashboard loads KPIs in under 3 seconds
- [ ] Database backups verified monthly
- [ ] Security audit checklist complete

### Business
- [ ] Owner can view company-wide financials in dashboard
- [ ] Each unit manager can operate their unit independently
- [ ] New operating unit can be provisioned without code changes
- [ ] All users authenticated and authorized correctly
- [ ] Training completed for all unit staff

---

## Appendix A: Document Index

| Document | Purpose |
|----------|---------|
| [erd.md](erd.md) | Complete Entity Relationship Diagram with 60+ entities |
| [phase-01-foundation.md](phase-01-foundation.md) | Auth, RBAC, unit provisioning, core infrastructure |
| [phase-02-procurement-treasury.md](phase-02-procurement-treasury.md) | Import orders, landed cost, FX, payments |
| [phase-03-inventory.md](phase-03-inventory.md) | Serialized inventory, weighted-average costing, stock movements |
| [phase-04-foam-manufacturing.md](phase-04-foam-manufacturing.md) | Batch production, consumption, grading |
| [phase-05-cutter-manufacturing.md](phase-05-cutter-manufacturing.md) | Work orders, templates, byproduct |
| [phase-06-furniture-manufacturing.md](phase-06-furniture-manufacturing.md) | BOMs, production orders, labor |
| [phase-07-sales-pos.md](phase-07-sales-pos.md) | Sales, credit, POS, internal transfers |
| [phase-08-accounting.md](phase-08-accounting.md) | Double-entry ledger, overhead, depreciation |
| [phase-09-hr-payroll.md](phase-09-hr-payroll.md) | Employees, attendance, labor, payroll |
| [phase-10-dashboard-deployment.md](phase-10-dashboard-deployment.md) | Owner dashboard, reporting, deployment |

---

*End of Implementation Master Plan*
