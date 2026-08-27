# Online-Only Local Server Architecture Design Spec

**Status**: Implemented

## Overview
This design spec revises the ERP system architecture from an offline-first hybrid sync model to a **Direct Online-Only Local Server Model**. The backend runs centrally on a local server on the LAN (Local Area Network). Electron clients interact with the server directly over REST API endpoints using Laravel Sanctum Bearer tokens.

## Key Changes
1. **Sync Infrastructure Removal**:
   - Eliminate `sync_conflicts` table, `SyncController`, sync routes (`/api/v1/sync/*`), and sync documentation.
   - Remove sync metadata columns (`version`, `synced_at`, `client_id`) from domain migrations (`work_orders`, `inventory_movements`, etc.).
2. **Realtime Transaction Processing**:
   - All work orders, inventory movements, sales, and financial records execute directly against the central server database in standard Laravel ACID database transactions (`DB::transaction()`).
3. **Electron Client Connection & Authentication**:
   - Configurable server base URL (`http://<local-server-ip>:<port>`) in Electron configuration.
   - Authentication via standard Sanctum API credentials and Bearer token headers (`Authorization: Bearer <token>`).

## Component Architecture

### 1. Database & Migrations
- Standardize domain tables without sync overhead.
- Primary keys remain UUIDs for globally unique record referencing.
- Multi-tenancy & store isolation enforced via `operating_unit_id` header / context middleware (`X-Operating-Unit-ID`).

### 2. Controllers & API Routes
- Endpoint structure standard: `/api/v1/<domain>/...`
- Return standardized JSON responses and Eloquent API Resources.
- Strict input validation using Laravel Form Requests (`app/Http/Requests/Api/v1/...`).

### 3. Verification & Testing
- Feature tests in Pest verifying REST endpoints for Work Orders and Inventory Movements without sync controller dependency.
- Pint formatting compliance check (`vendor/bin/pint --dirty --format agent`).

## Implementation Scope
- Remove:
  - `app/Http/Controllers/Api/v1/SyncController.php`
  - `database/migrations/2026_07_27_120000_create_sync_conflicts_table.php`
  - `docs/offline-online-sync-architecture.md`
  - `docs/FINANCIAL_AWARE_HYBRID_SYNC_GUIDE.md`
- Update:
  - `routes/api.php`
  - Domain migrations (`2026_08_01_082522_create_work_orders_table.php`, `2026_08_01_082530_create_inventory_movements_table.php`)
  - Feature tests in `tests/Feature/Api/v1/`
