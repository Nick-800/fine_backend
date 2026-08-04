# Simple Offline-First Sync Architecture

### Laravel + Electron System — Design Document

> **Design Specification:** Replaces the previous "Financial-Aware Hybrid Sync Design" (3-Tier) with the streamlined Simple ERP architecture.

---

## 1. Overview

This document outlines the simplified architecture for enabling the Laravel + Electron application to work seamlessly **online and offline**. It completely drops complex conflict resolution and tiered network states in favor of a robust, purely **Local-First** approach combined with **Additive Deltas**.

**Core Rules:**
- **Pure Local-First UI:** The React/Electron UI *never* makes direct API calls to Laravel for business logic. It reads and writes exclusively to the local SQLite database.
- **Outbox Pattern:** All local writes simultaneously enqueue an action into a local `outbox` table.
- **Additive Deltas (No Conflicts):** For inventory and financial balances, clients sync the *change* (e.g., `+5` or `-3`), never the absolute total. This allows concurrent offline edits to merge perfectly on the server.
- **Server Auto-Accounting:** The server remains the strict financial source of truth. When operational records sync to the server, server-side Observers generate the necessary double-entry accounting ledgers.

---

## 2. Client Architecture (Electron / React)

### The Repository Layer
Instead of scattering database queries across the app, all UI data access goes through Repositories (e.g., `InventoryRepository.ts`). 

When a user completes an action (like a POS Sale), the Repository does two things in a local transaction:
1. Updates the local SQLite tables (so the UI updates instantly).
2. Inserts a record into the `outbox` table.

```typescript
// Example: repositories/inventoryRepository.ts
export const InventoryRepository = {
  async recordStockMovement(sku: string, branchId: string, quantityDelta: number) {
    const movement = { id: crypto.randomUUID(), sku, branchId, quantityDelta, syncStatus: "pending" };
    
    await db.insert(inventoryMovements).values(movement);
    await Outbox.enqueue("inventory_movements", "create", movement); // Always pair writes with outbox
    return movement;
  }
};
```

### The Outbox Table
A simple "to-do" list of everything that happened offline:
`[id, table_name, operation, payload, status, created_at]`

---

## 3. The Sync Engine (Background Loop)

A background timer runs every 30 seconds (or immediately when internet connects). 

1. **Push:** Look at the `outbox` for `pending` rows. Send them to `POST /api/sync/push`. Mark them `synced` when confirmed.
2. **Pull:** Ask `GET /api/sync/pull?since=<version>`. Apply downloaded changes to SQLite. Update the last pulled version.

---

## 4. Server Architecture (Laravel API)

The server exposes two main endpoints for the clients.

### 4.1 The Pull Endpoint (Solving Clock Drift)
Instead of tracking `updated_at` timestamps (which break when a user's laptop clock is wrong), we use a single, server-controlled `sync_version` column.
- Added to all synced tables: `sync_version BIGINT AUTO_INCREMENT UNIQUE` (or an equivalent sequence implementation).
- The client requests `GET /api/sync/pull?since=4021`.
- Laravel returns any records with a `sync_version` greater than 4021.

### 4.2 The Push Endpoint (Solving Conflicts)
The client pushes a batch of operations. Laravel processes them sequentially.
- **For Standard Records:** (e.g., updating a customer's name) we use a simple "last save wins" approach (`updateOrCreate`). In an ERP, two people rarely edit the exact same basic text field simultaneously offline.
- **For Quantities (The Magic Trick):** (e.g., stock levels, money). The client passes the *additive delta*. 
  - *Branch A offline:* Sold 3 units (`delta: -3`)
  - *Branch B offline:* Received 5 units (`delta: +5`)
  - Both branches sync. Laravel simply adds both values. The final stock level is perfectly accurate. No conflict resolution logic is needed.

### 4.3 Triggering Accounting
When a pushed record is saved (e.g. `PosSale`), Laravel Model Observers trigger the auto-accounting logic on the server to post the financial journals securely.

---

## 5. Summary Decision Table

| Scenario | Resolution |
|----------|------------|
| Two branches edit different Customer text fields offline | Last-write-wins (acceptable edge-case risk for extreme simplicity). |
| Two branches change inventory stock levels offline | Both apply cleanly because we sync `quantity_delta` (e.g. `+5`), not absolute totals. |
| User needs to perform an action while internet is down | Action completes instantly locally, goes to Outbox, syncs automatically later. |
| Very rare true critical conflict | Save to `sync_conflicts` table for admin review (almost never happens with deltas). |
