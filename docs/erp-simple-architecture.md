## Simple Centralized Online ERP — Architecture & Implementation Guide

> [!IMPORTANT]
> **ARCHITECTURAL UPDATE**: The system architecture has been updated from a hybrid offline-first sync model to a **100% Direct Online-Only Local Server Model**. Clients connect directly over LAN to the central Laravel server REST API. Refer to [`2026-08-04-online-only-local-server-design.md`](file:///c:/Users/Nick/Documents/Projects/Fine/Project/fine_backend/docs/superpowers/specs/2026-08-04-online-only-local-server-design.md) for the active specification.

The architecture connects Electron and Web clients directly to the central Laravel backend over REST API endpoints:

```
React / Electron  →  Laravel REST API (Sanctum Auth)  →  Service & Controller Layer  →  PostgreSQL + Redis
```

That's it. **Direct realtime REST API transactions against the central database source of truth.** All operations (inventory movements, work orders, sales, treasury, double-entry ledgers) execute in real-time within database transactions (`DB::transaction()`).

---

## The one idea that makes everything else make sense

Normally, an app talks to a server, and the server is "the truth." If there's no internet, the app is stuck.

Here, the app talks to its **own local database (SQLite)** for everything — every screen, every save, every list. The server (Laravel + MySQL) is not gone, it's just not *in the way*. A separate, small background process copies local changes up to the server when there's internet, and pulls other people's changes down.

The React app never knows or cares if it's online. It only ever talks to SQLite. That's the whole trick.

---

## Piece 1 — Repository layer

**Why it exists:** if every React component called SQLite directly, you'd end up with database code scattered across 50 files. When you need to change how "save a sale" works, you'd have to hunt through the whole app. A repository is just one file per module with a small set of functions — `create`, `update`, `list`, `delete` — that both the UI and the sync engine call. Everything else in the app goes through it instead of touching the database directly.

**How it works:** React components never write SQL. They call a plain function like `InventoryRepository.adjustStock(...)`. That function writes to SQLite. That's the entire contract.

**Example — adjusting stock after a delivery arrives:**

```ts
// repositories/inventoryRepository.ts
export const InventoryRepository = {
  async recordStockMovement(sku: string, branchId: string, quantityDelta: number, reason: string) {
    const movement = {
      id: crypto.randomUUID(),
      sku,
      branchId,
      quantityDelta,
      reason,
      createdAt: new Date().toISOString(),
      syncStatus: "pending",
    };

    await db.insert(inventoryMovements).values(movement);
    await Outbox.enqueue("inventory_movements", "create", movement);

    return movement;
  },
};
```

Notice the two lines at the bottom: write the real data, then tell the outbox about it. That pairing — **always write the data and the outbox entry together** — is the single most important rule in the whole system. If you only remember one thing from this document, remember that.

**Can a mid-level developer build this in a few days?** Yes — it's plain functions wrapping Drizzle queries. No new concepts.

---

## Piece 2 — SQLite (the local database)

**Why it exists:** so the app works with zero internet, full stop. Every screen — the inventory count, the sales order form, the POS screen — reads from here, always, whether the laptop is online or not.

**How it works:** Drizzle ORM defines the tables (same shape as MySQL, described in the tables section below). React reads through TanStack Query, which calls the repository, which queries SQLite. When online, this data also happens to be getting copied to the server in the background — but the UI doesn't need to know that.

**Example flow — cashier opens the POS screen:**
1. React calls `useQuery(['products'], () => ProductRepository.list())`.
2. `ProductRepository.list()` reads straight from the local `products` table in SQLite.
3. Screen renders instantly. There was no network call in this chain at all.

**Can a mid-level developer build this?** Yes — it's a normal local database with an ORM. The only unusual part is remembering that *this*, not the API, is what the UI talks to. That's a habit, not a hard technical problem.

---

## Piece 3 — Outbox (the "to-do list" for syncing)

**Why it exists:** you need a record of "what changed locally that the server hasn't seen yet." Without it, you'd have to compare the entire local database against the entire server database every time you sync — slow, and error-prone. The outbox is just a to-do list: "here are the 12 things that happened while offline, in order."

**How it works:** it's one table. Every time the repository writes something, it also adds a row here describing what happened. A background job reads this table, sends the rows to the server, and marks them done when the server confirms.

**Example — a Work Order gets marked complete while offline:**

```
outbox row:
{ id: "abc123", table: "work_orders", operation: "update",
  payload: { id: "WO-2044", status: "completed" }, status: "pending" }
```

When the laptop reconnects, the sync service sees this row, sends it to Laravel, and once Laravel confirms, updates the row to `status: "synced"`.

**Can a mid-level developer build this?** Yes — it's one table and an insert statement added to each repository function. This is genuinely simple; don't let anyone talk you into anything fancier here.

---

## Piece 4 — Sync Service (the background loop)

**Why it exists:** something has to actually move data between SQLite and MySQL. This is that something. It's a small, boring loop — not a "sync engine" in the enterprise sense, just a timer that runs a function every 20–30 seconds (plus "run it now" when the app detects it just came back online).

**How it works — in plain English, step by step:**

1. Check: is there internet? If not, do nothing, try again in 30 seconds.
2. **Push**: look at the outbox table for rows marked `pending`. Send them to Laravel's `/sync/push` endpoint in one batch. For each one Laravel confirms, mark it `synced`.
3. **Pull**: ask Laravel's `/sync/pull` endpoint "what's changed since the last time I asked?" Apply those changes into local SQLite.
4. Update the little "last synced 2 minutes ago" indicator in the UI.
5. Wait, repeat.

That's the entire sync engine. There's no conflict-resolution algorithm to design up front — see the "keeping it simple on conflicts" section below for how we handle the rare cases that need a decision.

**Example code structure:**

```ts
// sync/syncService.ts
export async function runSyncCycle() {
  if (!navigator.onLine) return;

  const pending = await Outbox.getPending();
  if (pending.length > 0) {
    const result = await api.post("/sync/push", { operations: pending });
    await Outbox.markSynced(result.confirmedIds);
  }

  const lastVersion = await SyncState.getLastPulledVersion();
  const { changes, latestVersion } = await api.get(`/sync/pull?since=${lastVersion}`);
  await applyChangesToLocalDb(changes);
  await SyncState.setLastPulledVersion(latestVersion);
}

setInterval(runSyncCycle, 30_000);
window.addEventListener("online", runSyncCycle);
```

**Can a mid-level developer build this?** Yes — this is literally a `setInterval` and two API calls. It looks unimpressive on purpose. That's a feature, not a shortcoming.

---

## Piece 5 — Laravel API (`/sync/push` and `/sync/pull`)

**Why it exists:** it's the only door between all the desktop apps and the shared MySQL database. Nothing fancy — two endpoints, plus the normal CRUD endpoints Laravel already gives you for admin/reporting screens on the web side, if you have any.

**How `/sync/push` works, step by step:**
1. Receive a batch of operations from a desktop client.
2. For each one: does a record with this ID already exist? If the operation is `create`, insert it. If `update`, update it. If it's a stock movement, **add** the quantity delta rather than overwriting a total (see below — this one rule prevents almost every sync bug you'd otherwise hit).
3. Send back which ones succeeded.

**How `/sync/pull` works, step by step:**
1. Receive "give me everything since version 4021."
2. Query MySQL for anything changed after that version number (every row gets a simple auto-incrementing `sync_version` column — no timestamps, because laptop clocks drift and that causes real bugs).
3. Send those rows back, plus the new highest version number.

**Example controller (kept intentionally plain):**

```php
// app/Http/Controllers/Sync/SyncPushController.php
public function push(Request $request)
{
    $confirmed = [];

    foreach ($request->input('operations') as $op) {
        match ($op['table']) {
            'inventory_movements' => InventoryMovement::create($op['payload']), // additive, see below
            'work_orders'         => WorkOrder::updateOrCreate(['id' => $op['payload']['id']], $op['payload']),
            'sales_orders'        => SalesOrder::updateOrCreate(['id' => $op['payload']['id']], $op['payload']),
            default               => null,
        };
        $confirmed[] = $op['id'];
    }

    return response()->json(['confirmedIds' => $confirmed]);
}
```

**Can a mid-level Laravel developer build this?** Yes — it's a `match` statement and Eloquent's normal `updateOrCreate`. Nothing here requires knowing anything beyond standard Laravel.

---

## The one tricky part, made simple: stock quantities

Here's the one place where "just overwrite the row" breaks. Two branches count inventory offline at the same time. If you sync by sending "stock is now 40 units," whichever branch syncs *last* wins, and the other branch's count is silently lost.

**The simple fix, without any conflict-resolution theory:** for stock movements specifically, never store or sync "the new total." Only ever store and sync the *change* — "+5" or "-3". Laravel adds these up. Two branches both syncing "-3" and "+5" just both apply — no conflict exists, because nothing overwrote anything.

```
Branch A offline: records "-3" (sold 3 units)
Branch B offline: records "+5" (received 5 units)
Both sync: MySQL applies both. Final total is correct regardless of order.
```

This isn't a CRDT or a distributed-systems trick — it's just "store the change, not the total," which is how a checkbook register works. It solves the one real conflict risk in this system without needing anything more sophisticated. Everything else in this ERP (a sales order's customer name, a work order's assigned technician) is edited by one person at a time in practice, so plain "last save wins" is genuinely fine for those.

*If you ever outgrow this:* the more advanced version is per-field version numbers so two people editing different fields of the same record don't clobber each other at all — worth knowing this exists, not worth building until you actually see it happen in practice.

---

## Handling the rare "actual" conflict

For the handful of cases where two offline edits truly collide (same field, same record, both offline) — don't build automatic resolution. Just:

1. Keep both versions in a small `sync_conflicts` table.
2. Show a manager a simple screen: "Branch A says the customer's credit limit is $5,000, Branch B says $6,000 — pick one." One button click resolves it.

This is dramatically simpler than automatic merge logic, and for an ERP, a human should be looking at money-related conflicts anyway.

---

## Folder structure

**Desktop client (Electron + React):**

```
desktop-client/
├── src/
│   ├── db/
│   │   ├── schema.ts              # Drizzle table definitions
│   │   └── client.ts
│   ├── repositories/
│   │   ├── inventoryRepository.ts
│   │   ├── productionRepository.ts
│   │   ├── salesOrderRepository.ts
│   │   └── posRepository.ts
│   ├── sync/
│   │   ├── outbox.ts               # enqueue / getPending / markSynced
│   │   ├── syncService.ts          # the loop from Piece 4
│   │   └── syncState.ts            # tracks last pulled version
│   ├── stores/                     # Zustand — UI state only (filters, active screen)
│   ├── hooks/                      # TanStack Query hooks, one per repository
│   └── screens/
│       ├── Inventory/
│       ├── Production/
│       ├── SalesOrders/
│       └── POS/
```

**Backend (Laravel):**

```
app/
├── Http/Controllers/
│   ├── Sync/
│   │   ├── SyncPushController.php
│   │   └── SyncPullController.php
│   ├── InventoryController.php     # normal CRUD for any web/admin screens
│   ├── ProductionController.php
│   ├── SalesOrderController.php
│   └── PosController.php
├── Models/
│   ├── InventoryMovement.php
│   ├── WorkOrder.php
│   ├── SalesOrder.php
│   └── PosSale.php
```

That's genuinely the whole structure. No modules-within-modules, no separate "domain" folder — one controller and one model per thing, one repository per thing on the frontend.

---

## Database tables

**Business tables** (same shape in SQLite and MySQL):

```sql
-- inventory_movements — see "the one tricky part" above: quantity_delta, never a total
CREATE TABLE inventory_movements (
  id            TEXT PRIMARY KEY,
  sku           TEXT NOT NULL,
  branch_id     TEXT NOT NULL,
  quantity_delta REAL NOT NULL,
  reason        TEXT,
  created_at    TEXT NOT NULL,
  sync_version  INTEGER   -- NULL locally until the server assigns one
);

-- work_orders — Production module
CREATE TABLE work_orders (
  id           TEXT PRIMARY KEY,
  product_sku  TEXT NOT NULL,
  quantity     REAL NOT NULL,
  status       TEXT NOT NULL,   -- 'open' | 'in_progress' | 'completed'
  branch_id    TEXT NOT NULL,
  updated_at   TEXT NOT NULL,
  sync_version INTEGER
);

-- sales_orders
CREATE TABLE sales_orders (
  id           TEXT PRIMARY KEY,
  customer_id  TEXT NOT NULL,
  branch_id    TEXT NOT NULL,
  status       TEXT NOT NULL,
  total        REAL NOT NULL,
  created_at   TEXT NOT NULL,
  sync_version INTEGER
);

-- pos_sales
CREATE TABLE pos_sales (
  id           TEXT PRIMARY KEY,
  branch_id    TEXT NOT NULL,
  register_id  TEXT NOT NULL,
  total        REAL NOT NULL,
  created_at   TEXT NOT NULL,
  sync_version INTEGER
);
```

**Sync tables** (these two are new; everything above is just "your normal ERP tables"):

```sql
-- outbox — the to-do list, lives only on the desktop client
CREATE TABLE outbox (
  id           TEXT PRIMARY KEY,
  table_name   TEXT NOT NULL,
  operation    TEXT NOT NULL,     -- 'create' | 'update'
  payload      TEXT NOT NULL,     -- JSON blob
  status       TEXT NOT NULL DEFAULT 'pending',  -- 'pending' | 'synced'
  created_at   TEXT NOT NULL
);

-- sync_state — one row, tracks how far we've pulled
CREATE TABLE sync_state (
  id                  INTEGER PRIMARY KEY CHECK (id = 1),
  last_pulled_version INTEGER NOT NULL DEFAULT 0
);
```

**On the server (MySQL), add one column to every synced table:**
```sql
ALTER TABLE inventory_movements ADD COLUMN sync_version BIGINT AUTO_INCREMENT UNIQUE;
```
(Repeat for `work_orders`, `sales_orders`, `pos_sales`.) This one column is what makes "give me everything since version X" a simple, fast query — no clocks, no timestamps, no drift.

**Only if a real conflict happens (rare, per module above):**
```sql
CREATE TABLE sync_conflicts (
  id            CHAR(36) PRIMARY KEY,
  table_name    VARCHAR(50),
  record_id     VARCHAR(36),
  client_value  JSON,
  server_value  JSON,
  resolved      BOOLEAN DEFAULT FALSE,
  created_at    TIMESTAMP
);
```

---

## API endpoints

| Endpoint | Purpose |
|---|---|
| `POST /sync/push` | Send a batch of local changes up |
| `GET /sync/pull?since=<version>` | Get everything that changed after a version number |
| `GET /inventory`, `POST /inventory`, etc. | Normal CRUD, for any web/reporting screens outside the desktop app |

That's it — two sync endpoints, plus whatever ordinary REST endpoints you'd build anyway.

---

## Development phases

**Phase 1 (1 week): Prove the pipe works**
Pick one boring entity (a "notes" table works fine) and get it flowing through all six pieces: React → repository → SQLite → outbox → sync service → Laravel → MySQL. Don't touch real ERP data yet. The goal is just proving the six pieces connect, so debugging real inventory bugs doesn't get mixed up with debugging plumbing bugs.

**Phase 2 (1–2 weeks): Inventory**
Real stock movements, using the additive quantity-delta approach. This is the module worth getting right first, since Production and POS both depend on stock numbers being correct.

**Phase 3 (2 weeks): Production (Work Orders)**
Mostly reuses Phase 2's patterns. Status field uses simple last-write-wins (fine, since one person owns a Work Order at a time in practice).

**Phase 4 (1 week): Sales Orders**
Same pattern again — by now this should feel repetitive, which is the point.

**Phase 5 (2 weeks): POS**
Add the offline sale flow and a receipt-numbering scheme (ask me about this separately if your country requires strictly sequential invoice numbers — it's a small addition, not a big one).

**Phase 6 (ongoing): Polish**
Sync status indicator in the UI, the conflict-review screen for the rare real conflicts, and testing what happens if the app is closed mid-sync (answer: nothing bad, because the outbox row just stays `pending` until the next cycle picks it up again).

**Total: roughly 8–10 weeks for the four modules you listed, for two developers.** Later modules (Purchasing, Accounting, CRM, Payroll) reuse the same six pieces — they're additional folders, not additional architecture.

---

## What I'm deliberately leaving out, and why that's fine here

- **No message broker** — a `setInterval` loop calling two REST endpoints does the same job at this scale (hundreds of users, not millions).
- **No event sourcing** — you don't need to replay history to know the current state; the additive quantity-delta trick above handles the one place that actually needed it.
- **No CQRS** — read and write both go through the same repository function; splitting them adds a layer with no payoff at this size.
- **No version vectors** — a single incrementing `sync_version` column solves "what's changed since X" without needing to reason about distributed clocks.

If the business grows to a scale where these start to matter (thousands of concurrent writers to the same rows, need for full audit replay, etc.), that's a good problem to have — and a good reason to bring in someone to redesign that one piece, not a reason to build for it now.
