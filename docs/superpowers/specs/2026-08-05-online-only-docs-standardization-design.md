# Online-Only Documentation Standardization Design Specification

**Date**: 2026-08-05  
**Status**: Approved  
**Scope**: Standardize all master documentation, phase roadmaps, and architecture guides in `docs/` to 100% Direct Online-Only Local Server Model, superseding legacy hybrid offline-sync specifications.

---

## 1. Overview & Architectural Alignment

The ERP system has transitioned from a hybrid offline-first sync model to a **Direct Online-Only Local Server Model** (as defined in [`2026-08-04-online-only-local-server-design.md`](file:///c:/Users/Nick/Documents/Projects/Fine/Project/fine_backend/docs/superpowers/specs/2026-08-04-online-only-local-server-design.md)).

Under this architecture:
- The backend runs centrally on a local server on the LAN (or HTTPS cloud).
- Desktop/Web clients communicate directly over REST API endpoints using Sanctum Bearer tokens and `X-Operating-Unit-ID` headers.
- All operations (work orders, inventory movements, foam batches, sales, treasury, accounting) execute in real-time within database transactions (`DB::transaction()`).
- Sync engines, SQLite local outboxes, sync conflicts (`sync_conflicts`), and `/api/v1/sync/*` routes are fully eliminated.

This specification outlines the systematic refactoring of all documentation in `docs/` to eliminate offline/sync ambiguities and present a single, consistent online-only system reference.

---

## 2. Documentation Scope & Modifications

### 2.1 Legacy Spec Banners (Superseded Tagging)
Add a prominent warning banner at the top of legacy hybrid sync specs:
- [`docs/superpowers/specs/2026-07-27-financial-aware-offline-sync-design.md`](file:///c:/Users/Nick/Documents/Projects/Fine/Project/fine_backend/docs/superpowers/specs/2026-07-27-financial-aware-offline-sync-design.md)
- [`docs/superpowers/specs/2026-08-01-operational-sync-design.md`](file:///c:/Users/Nick/Documents/Projects/Fine/Project/fine_backend/docs/superpowers/specs/2026-08-01-operational-sync-design.md)

**Banner Format**:
> [!IMPORTANT]
> **SUPERSEDED SPECIFICATION**: This specification describes an earlier hybrid offline-first sync model. The ERP architecture has been updated to a **100% Direct Online-Only Local Server Model**. Refer to [`2026-08-04-online-only-local-server-design.md`](file:///c:/Users/Nick/Documents/Projects/Fine/Project/fine_backend/docs/superpowers/specs/2026-08-04-online-only-local-server-design.md) for the active system architecture.

---

### 2.2 Master Implementation Plan & Architecture Guide
- [`docs/IMPLEMENTATION_PLAN.md`](file:///c:/Users/Nick/Documents/Projects/Fine/Project/fine_backend/docs/IMPLEMENTATION_PLAN.md):
  - Replace Section 2 "Simple Offline Sync (Pure Local-First SQLite)" decision with "Direct Online REST API & LAN Server Architecture".
  - Remove `sync_conflicts` table from Phase 01 deliverables.
  - Update Section 10 success criteria to confirm 100% live API connection without client sync outboxes.
- [`docs/erp-simple-architecture.md`](file:///c:/Users/Nick/Documents/Projects/Fine/Project/fine_backend/docs/erp-simple-architecture.md):
  - Update title and introduction from "Simple offline-first ERP" to "Simple Centralized Online ERP Architecture".
  - Replace outbox/conflict resolution sections with direct REST transaction handling and server-side validation.

---

### 2.3 Phase Documents (`phase-01` through `phase-10`)
Update all 10 phase documents:
1. **`phase-01-foundation.md`**: Remove `sync_conflicts` migration, remove `/api/v1/sync/*` endpoints, replace Sync Engine section with "Direct Central REST API & Local LAN Server Connectivity".
2. **`phase-02-procurement-treasury.md`**: Remove Tier 1 offline read notes; specify all procurement and treasury lookups run against central API.
3. **`phase-03-inventory.md`**: Update inventory movements and stock lookups from SQLite outbox to direct REST API calls.
4. **`phase-04-foam-manufacturing.md`**: Update foam batch logging and chemical consumption to live central API endpoints.
5. **`phase-05-cutter-manufacturing.md`**: Update cutting work orders to direct REST API endpoints.
6. **`phase-06-furniture-manufacturing.md`**: Update BOM lookups and assembly orders to live REST API calls.
7. **`phase-07-sales-pos.md`**: Remove provisional outbox badging and quarantine drawers; specify direct POS checkout via REST API with live credit limit validation.
8. **`phase-08-accounting.md`**: Confirm live double-entry journal creation on central server.
9. **`phase-09-hr-payroll.md`**: Update attendance tracking and workshop labor logging to direct API calls.
10. **`phase-10-dashboard-deployment.md`**: Remove outbox sync quarantine alerts; specify central deployment and real-time LAN server monitoring.

---

## 3. Verification & Compliance Check
- All references to `/api/v1/sync/push` and `/api/v1/sync/pull` removed from active phase and master architecture docs.
- All operational data classifications updated to direct online API execution.
- Markdown formatting checked and verified.
