# Financial-Aware Hybrid Offline-Online Sync Architecture Guide

> **Target Audience:** Backend Developers (Laravel), Frontend Developers (Electron/React), System Architects, and QA Engineers  
> **System Stack:** Laravel 11 API (PostgreSQL Canonical Server) + Electron Desktop App (SQLite Local Database)  
> **Specification File:** [`docs/superpowers/specs/2026-07-27-financial-aware-offline-sync-design.md`](file:///c:/Users/Nick/Documents/Projects/Fine/Project/fine_backend/docs/superpowers/specs/2026-07-27-financial-aware-offline-sync-design.md)

---

## 1. Executive Summary & Core Philosophy

In a multi-unit manufacturing enterprise, internet connectivity can fluctuate across factories, warehouses, and store showrooms. However, allowing uncontrolled offline access to financial ledgers causes severe risks (duplicate payouts, credit overruns, and silent accounting drift).

To solve this, the application implements a **Financial-Aware Hybrid Sync Architecture**:

1. **The Server is Canonical:** The online Laravel + PostgreSQL server is the single source of truth.
2. **Operational Autonomy:** Factory staff, workshop cutteers, and warehouse personnel can work 100% offline for operational tasks (inventory lookups, foam batching, cutter work orders, BOM assembly, attendance).
3. **Strict Online Financial Ledger:** All monetary movements (Payment Requests, Treasury payouts, Bank Holds, FX execution, Payroll approvals, GL entries) are **strictly online-only**.
4. **Provisional POS & Quarantine Gate:** Showroom POS checkout can happen offline with provisional inventory reservation, but server validation errors route to a dedicated **Sync Quarantine Drawer** for manager resolution.

---

## 2. The 3-Tier Data Classification Matrix

Every model in the ERP is categorized into one of four operational sync tiers:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           MULTI-TIER DATA MATRIX                            │
├─────────────────┬───────────────────────────────────┬───────────────────────┤
│ Tier            │ Domain Entities                   │ Operational Behavior  │
├─────────────────┼───────────────────────────────────┼───────────────────────┤
│ **Tier 1**      │ InventoryItems, StockLots,        │ **Full Read & Write** │
│ Operational Data│ StockMovements, Foam Batches,     │ Writes to local SQLite│
│                 │ Cutter Work Orders, BOMs,         │ Pushes/pulls diffs    │
│                 │ Entity Contacts, Attendance Logs  │ Field-level auto-merge│
├─────────────────┼───────────────────────────────────┼───────────────────────┤
│ **Tier 2**      │ Customer Credit Limits,           │ **Cached Read-Only**  │
│ Financial       │ Price Lists, Chart of Accounts,   │ Downloaded on sync    │
│ Snapshots       │ Bank Account Lists, FX Rates      │ Editing disabled in   │
│                 │                                   │ Electron UI offline   │
├─────────────────┼───────────────────────────────────┼───────────────────────┤
│ **Tier 3A**     │ Payment Requests, Treasury Cash,  │ **Strict Online-Only**│
│ Strict Money    │ Bank Holds, FX Executions,        │ Direct HTTPS API      │
│                 │ Payroll Approvals, GL Journals    │ Blocked if offline    │
├─────────────────┼───────────────────────────────────┼───────────────────────┤
│ **Tier 3B**     │ Sales Orders, POS Checkout        │ **Provisional Outbox**│
│ POS Outbox      │ Receipts, Restock Requests        │ Saved locally         │
│                 │                                   │ Sync Quarantine Drawer│
└─────────────────┴───────────────────────────────────┴───────────────────────┘
```

---

## 3. Local Storage Architecture (Electron Desktop)

### 3.1 Local SQLite Database Schema
The Electron app maintains a local SQLite database (`better-sqlite3` or `knex`). All syncable local tables MUST include the following tracking columns:

| Column | Type | Purpose |
|--------|------|---------|
| `id` | UUID | Globally unique primary key generated client-side via UUID v4 (prevents ID collisions between offline devices). |
| `version` | INTEGER | Current server version number. Defaults to `1`. |
| `base_version` | INTEGER | The version number this client loaded before making local edits. |
| `sync_status` | VARCHAR(30) | Status: `'synced'`, `'pending_settlement'`, or `'quarantined'`. |
| `last_synced_at` | TIMESTAMP | Timestamp of last successful server confirmation. |
| `updated_at` | TIMESTAMP | Local client modification timestamp. |
| `deleted_at` | TIMESTAMP | Soft-delete marker (never hard delete synced data). |

### 3.2 Outbox Queue Table (`sync_outbox`)
Offline actions are stored in a dedicated local outbox queue table:

```sql
CREATE TABLE sync_outbox (
    id TEXT PRIMARY KEY,             -- UUID v4
    action_id TEXT NOT NULL,         -- Client action reference ID
    table_name TEXT NOT NULL,        -- Target table (e.g. 'sales_orders', 'stock_lots')
    record_id TEXT NOT NULL,         -- Target record UUID
    operation TEXT NOT NULL,         -- 'create' | 'update' | 'delete'
    base_version INTEGER NOT NULL,   -- Client base version
    payload TEXT NOT NULL,           -- JSON serialized record data
    created_at TEXT NOT NULL         -- ISO-8601 timestamp
);
```

---

## 4. End-to-End Sync Lifecycle & Data Flow

```
                      ┌──────────────────────────────────────┐
                      │ Electron App (Offline / Connectivity)│
                      └──────────────────┬───────────────────┘
                                         │
                    Pings HEAD /api/v1/ping every 10s
                                         │
                                [isOnline == true]
                                         │
                                         ▼
                     Step 1: PULL Delta Updates from API
                    GET /api/v1/sync/pull?since={last_sync}
                                         │
                       Updates local SQLite database
                                         │
                                         ▼
                     Step 2: PUSH Local Outbox Actions
                         POST /api/v1/sync/push
                       { device_id, outbox: [...] }
                                         │
                                         ▼
                     ┌───────────────────┴───────────────────┐
                     │  Laravel API Server Processing Loop   │
                     └───────────────────┬───────────────────┘
                                         │
               ┌─────────────────────────┴─────────────────────────┐
               │                                                   │
      [Status: "synced"]                                  [Status: "quarantined"]
               │                                                   │
  • Update local SQLite record                      • Set local sync_status = 'quarantined'
  • Remove item from sync_outbox                    • Display Top-Bar Warning:
  • Set sync_status = 'synced'                        "⚠️ 1 Outbox Transaction Quarantined"
                                                    • Open Sync Quarantine Drawer
```

---

## 5. Server Sync Engine & Conflict Resolution (`SyncService`)

### 5.1 Push Processing Rules (`POST /api/v1/sync/push`)
When the client pushes an outbox array to the Laravel backend, `SyncService::processPush()` executes the following steps inside a database transaction (`DB::transaction`):

1. **Rule Check (Tier 3A Strict Money Block):**
   If an outbox item targets a strict financial table (e.g. `payment_requests`, `bank_holds`, `payroll_runs`, `journal_entries`), the server **immediately quarantines** the item with reason:  
   `"Tier 3A monetary ledger operations are strict online-only and cannot be pushed via offline outbox."`

2. **Version Match Check (`base_version == server_version`):**
   - **Match (`base_version == record.version`):** The update is applied directly, and `record_version` is incremented.

3. **Field-Level Diff & Auto-Merge:**
   If `base_version !== record.version` (another user updated the record online while this client was offline):
   - The server compares the client's modified keys against the server's changed keys since `base_version`.
   - **Non-Overlapping Fields:** Auto-merges both sets of changes safely (e.g. User A edited `notes` offline, User B edited `status` online -> both changes persist).
   - **Overlapping Fields or Business Rule Failure:** Quarantines the outbox item and creates a record in `sync_conflicts`.

---

## 6. The Sync Quarantine Lifecycle & Manager Resolution

When an offline POS sale or stock movement fails validation upon sync:

```
                            ┌──────────────────────────────────────┐
                            │     Sync Quarantine Drawer Modal     │
                            │  "Customer Credit Limit Exceeded"    │
                            └──────────────────┬───────────────────┘
                                               │
               ┌───────────────────────────────┼───────────────────────────────┐
               │                               │                               │
      [1. Force Override]             [2. Edit & Resubmit]            [3. Void & Reverse]
               │                               │                               │
  • Manager enters PIN            • Manager adjusts payment       • Cancels offline sale
  • Sends API request with          terms (e.g. requires cash     • Automatically releases
    `action: "override"`            down payment)                   local reserved stock
  • Server approves order AND     • Re-queues corrected outbox    • Marks record as `voided`
    logs an **Audit Exception**     item and re-pushes to API       on server and local DB
```

---

## 7. Backend API Endpoints & Request Contracts

### 7.1 Pull Delta Changes
* **Endpoint:** `GET /api/v1/sync/pull?since=2026-07-27T00%3A00%3A00Z`
* **Headers Required:**
  - `Authorization: Bearer <TOKEN>`
  - `X-Operating-Unit-ID: <OPERATING_UNIT_UUID>`
* **Response (200 OK):**
  ```json
  {
    "server_timestamp": "2026-07-27T14:15:00+00:00",
    "changes": {
      "entities": [
        {
          "id": "c1f7b7e8-4b7d-41a9-b3a1-9f939e6a9f99",
          "name": "Acme Contracting",
          "record_version": 2,
          "updated_at": "2026-07-27T14:05:00Z"
        }
      ],
      "inventory_items": []
    }
  }
  ```

### 7.2 Push Local Outbox
* **Endpoint:** `POST /api/v1/sync/push`
* **Headers Required:** Same headers as pull
* **Request Body:**
  ```json
  {
    "device_id": "electron-showroom-pos-01",
    "outbox": [
      {
        "action_id": "act-101",
        "table": "entities",
        "record_id": "a0000000-0000-0000-0000-000000000001",
        "operation": "create",
        "base_version": 1,
        "data": {
          "name": "Offline B2B Client",
          "entity_type": "organization",
          "is_active": true
        }
      }
    ]
  }
  ```
* **Response (200 OK):**
  ```json
  {
    "processed_count": 1,
    "results": [
      {
        "action_id": "act-101",
        "status": "synced",
        "record_version": 1
      }
    ]
  }
  ```

### 7.3 List Quarantined Conflicts
* **Endpoint:** `GET /api/v1/sync/quarantined?status=quarantined`

### 7.4 Resolve Quarantined Conflict
* **Endpoint:** `POST /api/v1/sync/quarantined/{id}/resolve`
* **Request Body:**
  ```json
  {
    "action": "override" // Options: "override" | "edit" | "void"
  }
  ```
