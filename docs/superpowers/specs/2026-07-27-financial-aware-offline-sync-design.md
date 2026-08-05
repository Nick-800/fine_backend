## Financial-Aware Hybrid Offline-Online Sync Architecture Design Specification

> [!IMPORTANT]
> **SUPERSEDED SPECIFICATION**: This specification describes an earlier hybrid offline-first sync model. The ERP architecture has been updated to a **100% Direct Online-Only Local Server Model**. Refer to [`2026-08-04-online-only-local-server-design.md`](file:///c:/Users/Nick/Documents/Projects/Fine/Project/fine_backend/docs/superpowers/specs/2026-08-04-online-only-local-server-design.md) for the active system architecture.

**Date**: 2026-07-27  
**Status**: SUPERSEDED by 2026-08-04 Online-Only Local Server Model  
**Scope**: Integration of offline-first sync capabilities for operational data in Electron + Laravel ERP, while maintaining strict online-only controls for financial and monetary ledger operations.

---

## 1. Overview & Architectural Goals

This specification defines the multi-tier synchronization model enabling the Electron desktop clients to work seamlessly online and offline while protecting financial integrity and central accounting balances.

### Key Objectives
* **Strict Financial Control**: Prevent silent financial drift, duplicate payouts, or invalid ledger entries by requiring live API connectivity for all monetary ledger actions.
* **Operational Autonomy**: Enable inventory lookups, foam batch tracking, cutter work order logging, BOM lookups, and attendance logging to function 100% offline.
* **Controlled POS Outbox**: Allow showroom POS cashiers to complete sales offline with provisional stock reservation and visual status badging ("Provisional - Sync Pending").
* **Deterministic Conflict Resolution**: Field-level auto-merging for non-conflicting operational attributes, with a dedicated **Sync Quarantine Drawer** in Electron for manual manager resolution when business rules fail.

---

## 2. Multi-Tier Data Classification Strategy

All domain entities in the ERP system are categorized into 3 distinct tiers:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           MULTI-TIER DATA MATRIX                            │
├─────────────────┬───────────────────────────────────┬───────────────────────┤
│ Tier            │ Domain Entities                   │ Operational Behavior  │
├─────────────────┼───────────────────────────────────┼───────────────────────┤
│ Tier 1          │ InventoryItems, StockLots,        │ Full Read & Write     │
│ Operational Data│ StockMovements, Foam Batches,     │ Local SQLite outbox   │
│                 │ Cutter Work Orders, BOMs,         │ Push/Pull sync diffs  │
│                 │ Entity Contacts, Attendance Logs  │ Field-level auto-merge│
├─────────────────┼───────────────────────────────────┼───────────────────────┤
│ Tier 2          │ Customer Credit Limits,           │ Cached Read-Only      │
│ Financial       │ Price Lists, Chart of Accounts,   │ Pulled on sync        │
│ Snapshots       │ Bank Account Lists, FX Rates      │ Electron UI disabled  │
│                 │                                   │ for editing offline   │
├─────────────────┼───────────────────────────────────┼───────────────────────┤
│ Tier 3A         │ Payment Requests, Treasury Cash,  │ Strict Online-Only    │
│ Strict Money    │ Bank Holds, FX Executions,        │ Direct HTTPS API      │
│                 │ Payroll Approvals, GL Journals    │ Blocked if offline    │
├─────────────────┼───────────────────────────────────┼───────────────────────┤
│ Tier 3B         │ Sales Orders, POS Checkout        │ Write-to-Outbox       │
│ POS Outbox      │ Receipts, Restock Requests        │ Provisional Badging   │
│                 │                                   │ Sync Quarantine Gate  │
└─────────────────┴───────────────────────────────────┴───────────────────────┘
```

---

## 3. Data Schema & Synchronization Conventions

### 3.1 Local SQLite Schema (Electron Side)

Local SQLite tables mirroring Tier 1 and Tier 3B data models MUST contain the following required synchronization tracking columns:

| Column | Type | Description |
|--------|------|-------------|
| `id` | UUID | Primary key generated client-side via UUID v4 to prevent ID collisions. |
| `version` | INTEGER | Integer version incremented by server upon each successful update. Default `1`. |
| `base_version` | INTEGER | The version number this client loaded before making local modifications. |
| `sync_status` | VARCHAR(30) | Enum: `'synced'`, `'pending_settlement'`, `'quarantined'`. |
| `last_synced_at` | TIMESTAMP | Timestamp of last successful server push/pull confirmation. |
| `updated_at` | TIMESTAMP | Client local modification timestamp. |
| `deleted_at` | TIMESTAMP | Soft-delete marker (never hard delete synced data). |

### 3.2 Server Engine API Contracts

#### `GET /api/v1/sync/pull?since={ISO_8601_TIMESTAMP}`
Returns delta changes across Tier 1 & Tier 2 tables updated or soft-deleted on the server after `$since`.

```json
{
  "server_timestamp": "2026-07-27T11:50:00Z",
  "changes": {
    "inventory_items": [],
    "stock_lots": [
      {
        "id": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
        "version": 3,
        "status": "available",
        "updated_at": "2026-07-27T11:45:00Z"
      }
    ],
    "credit_limit_snapshots": []
  }
}
```

#### `POST /api/v1/sync/push`
Sends queued local outbox actions to the server.

```json
{
  "device_id": "electron-pos-unit-01",
  "outbox": [
    {
      "action_id": "action-uuid-001",
      "table": "sales_orders",
      "record_id": "order-uuid-999",
      "operation": "create",
      "base_version": 1,
      "data": {
        "client_id": "client-uuid-123",
        "total_amount": 1500.00,
        "payment_type": "credit"
      }
    }
  ]
}
```

---

## 4. Conflict Detection & Sync Quarantine Queue

### 4.1 Server Processing & Field-Level Merging
1. Server receives outbox batch inside a `DB::transaction`.
2. For each outbox item:
   - If `base_version == record.version`: Apply changes, increment `version`.
   - If `base_version != record.version`: Calculate modified fields between incoming payload and server state since `base_version`.
     - **Non-overlapping fields**: Auto-merge attributes and apply.
     - **Overlapping fields OR business rule failure** (e.g. credit limit exceeded, stock lot already consumed): Flag action as `quarantined`.
3. Return batch results detailing success vs. quarantined items.

### 4.2 Sync Quarantine Workflow (Electron UI)

```
                       ┌────────────────────────┐
                       │  Outbox Push Executed  │
                       └───────────┬────────────┘
                                   │
                           Server Response?
                                   │
                 ┌─────────────────┴─────────────────┐
                 │                                   │
          [Status: Synced]                [Status: Quarantined]
                 │                                   │
       Update Local SQLite               Display Top Bar Alert
       sync_status = 'synced'            Open Sync Quarantine Drawer
                                                     │
                                       ┌─────────────┴─────────────┐
                                       │    Manager Action Menu    │
                                       └─────────────┬─────────────┘
                                                     │
               ┌─────────────────────────────────────┼─────────────────────────────────────┐
               │                                     │                                     │
      [1. Force Override]                  [2. Edit & Resubmit]                   [3. Void & Reverse]
               │                                     │                                     │
    Requires Supervisor Pin               Modify terms or items                 Cancel local order
    Server posts exception log            Re-queue in local outbox              Release provisional stock
    Order & GL post successfully          Re-push to API                        Set status = 'voided'
```

---

## 5. Verification Plan

### 5.1 Automated Tests (Pest PHP & Electron Specs)
* **Server Push/Pull Tests (`tests/Feature/SyncControllerTest.php`)**:
  * Delta pull correctly returns only records modified after `$since`.
  * Non-conflicting field update auto-merges and increments version.
  * Overlapping field update or credit limit overrun returns `quarantined` payload.
* **Local Offline Simulation (`fine-desktop/src/tests/syncEngine.test.ts`)**:
  * Pinging `HEAD /api/v1/ping` correctly switches online/offline network status.
  * Tier 3A operations fail gracefully when offline with alert message.
  * Tier 3B sales orders save locally with `pending_settlement` badge.

### 5.2 Manual Verification
* Disconnect Electron client network interface, create a POS sales order, verify provisional badge and SQLite insertion.
* Reconnect network, run sync cycle, verify server settlement or quarantine drawer presentation.
