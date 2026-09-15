# FINE ERP Backend API

An enterprise multi-tenant ERP backend built with **Laravel 12**, **PHP 8.4**, and **Sanctum Authentication**. Engineered for high-throughput manufacturing, inventory, procurement, POS, HR, accounting, and multi-unit operating architectures.

---

## Requirements

- **PHP**: 8.4 or higher
- **Composer**: 2.x
- **Database**: SQLite (default for development), PostgreSQL, or MySQL
- **Extensions**: `pdo`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`

---

## Installation & Setup Guide

### 1. Clone the Repository
```bash
git clone <repository-url> fine_backend
cd fine_backend
```

### 2. Environment Configuration
Copy the environment template file:
```bash
# On Linux/macOS
cp .env.example .env

# On Windows PowerShell
Copy-Item .env.example .env
```

### 3. Install Dependencies
Install all PHP packages via Composer:
```bash
composer install
```

### 4. Generate Application Key
```bash
php artisan key:generate
```

### 5. Configure Database
By default, the project uses **SQLite**. Create the database file if it does not exist:

**PowerShell (Windows):**
```powershell
New-Item -ItemType File -Path database\database.sqlite -Force
```

**Bash (Linux/macOS):**
```bash
touch database/database.sqlite
```

*(Optional)* If using MySQL or PostgreSQL, update the `.env` file with your database credentials:
```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=fine_erp
DB_USERNAME=postgres
DB_PASSWORD=secret
```

---

## Database Migrations & Seeders

The project ships with **two tiers** of seeders: a **system bootstrap** that provisions everything the application needs to function in a semi-production way, and a **dummy data** seeder that fills every model with moderate-volume fake data so reports, lists and dashboards have something to render.

### 1. System Bootstrap — `db:seed:bootstrap`

Idempotent. Seeds company, roles, permissions, chart of accounts, all 5 blueprints, all 5 operating units (provisions warehouses), FX rates, opening cash + inventory GL balances, master inventory (categories, attribute definitions, container items, 7 chemical lots, tank stocks, foam block/slice/scrap items, a stock adjustment request), 2 suppliers + 2 import orders with payment requests / bank holds / landed costs / goods receipts (and their GL postings), entity-backed client + employee, 13 standard users with the password `password`, and one full-lifecycle foam batch.

```bash
# Fresh database + bootstrap (typical first-run)
php artisan migrate:fresh --seed

# Run the bootstrap alone on an already-migrated database
php artisan db:seed:bootstrap
```

Re-running on a populated database is a no-op — every record is keyed on a natural unique (`slug`, `sku`, `email`, `order_number`, …).

The bootstrap refuses to run in the `production` environment unless `--force` is supplied:

```bash
php artisan db:seed:bootstrap --force
```

### 2. Dummy Data — `db:seed:dummy`

Builds on top of the bootstrap. Generates moderate-volume faker data across every module (~5–10 rows per model) using the same services the application uses at runtime, so the seeded records exercise real guards and ledger postings.

Available scopes (any combination, comma-separated):

| Scope | What it populates |
| :--- | :--- |
| `entities` | Clients, Employees, External Employers |
| `inventory` | Cut pieces + finished goods + lots, Products, BOMs, Component lines, Labor requirements |
| `production` | Extra foam batches, Cutter work orders, Furniture production orders, Material requests, Internal restock requests |
| `sales` | Sales orders in every state (draft / confirmed / fulfilled / paid / pending_approval with a credit approval request), POS daily closes |
| `procurement` | Extra suppliers, Import orders across the lifecycle, Payment requests, Bank holds, Landed cost lines, Goods receipts, Payable settlements |
| `overhead` | Overhead expenses across categories, Allocation rules |
| `assets` | Fixed assets + 3 months of depreciation entries via `FixedAssetService` |
| `payroll` | Payroll runs + payslips via `PayrollService` (Draft → Calculated → Approved) |
| `hr` | Leave requests, Attendance |

```bash
# Run all dummy modules on top of a bootstrapped DB
php artisan db:seed:dummy

# Wipe, bootstrap, then run all dummy modules in one shot
php artisan db:seed:dummy --reset

# Run only one module (useful when iterating)
php artisan db:seed:dummy --scope=production

# Run a subset of modules
php artisan db:seed:dummy --scope=production,sales,hr

# List the available scopes
php artisan db:seed:dummy --help
```

If the bootstrap is missing (e.g. company / operating units / chart of accounts not present) the command exits with a clear message — run `db:seed:bootstrap` first, or use `--reset`.

### Reset & Fresh Seed

```bash
# Drop everything and rebuild from scratch (bootstrap + dummy in one shot)
php artisan db:seed:dummy --reset

# Drop and run only the bootstrap
php artisan migrate:fresh --seed
```

### Typical Workflows

```bash
# 1. Brand-new dev environment — full demo data
php artisan migrate:fresh --seed
php artisan db:seed:dummy

# 2. Iterating on the sales module — keep existing data, top up sales
php artisan db:seed:dummy --scope=sales

# 3. Clean slate for a stakeholder demo
php artisan db:seed:dummy --reset
```

---

## Default Seeded Credentials

When running `php artisan db:seed`, the system automatically provisions the initial company (**Al-Amana Foam & Furniture Co.**), operating units, blueprints, and standard user accounts.

All default accounts use the password: `password`

| Role | Email | Scope |
| :--- | :--- | :--- |
| **Global Owner** | `owner@erp.com` | Company-wide (Full Admin) |
| **Accounting Manager** | `accounting@erp.com` | Company-wide |
| **HR Manager** | `hr@erp.com` | Company-wide |
| **Procurement Manager** | `procurement@erp.com` | Central Procurement & Treasury Unit |
| **Treasury Officer** | `treasury@erp.com` | Central Procurement & Treasury Unit |
| **Foam Plant Manager** | `foam@erp.com` | Tajoura Foam Manufactory Unit |
| **Foam Operator** | `foam-op@erp.com` | Tajoura Foam Manufactory Unit |
| **Cutter Manager** | `cutter@erp.com` | Cutter Plant A Unit |
| **Cutter Operator** | `cutter-op@erp.com` | Cutter Plant A Unit |
| **Furniture Manager** | `furniture@erp.com` | Furniture Assembly Unit B |
| **Furniture Assembler** | `assembler@erp.com` | Furniture Assembly Unit B |
| **Showroom Manager** | `showroom@erp.com` | Tripoli Main Showroom |
| **POS Cashier** | `cashier@erp.com` | Tripoli Main Showroom |

*Note: All seeded users have `must_change_password` set to `false` so the demo flows work end-to-end out of the box. The property is still honored by the auth layer for any user you create manually.*

---

## Running the Server

Start the local development server:
```bash
php artisan serve
```

The API will be available at `http://127.0.0.1:8000`.

---

## Architecture & Key System Modules

### Unified Entity System (Decoupled Non-User Support)
- **Decoupled Persons & Organizations**: `entities` (Employees, B2B Clients, Third-Party Employer Agencies) operate as first-class domain entities **without requiring system `User` login accounts**.
- **Multi-Role Assignment**: A single real-world organization can hold multiple roles (`client`, `external_employer`, `vendor`).
- **On-Demand User Provisioning**: Admins can provision software access on demand via `POST /api/v1/entities/{id}/provision-user`.

### Multi-Tenant Operating Unit Scoping
- All unit-scoped requests require the `X-Operating-Unit-ID` HTTP header matching the user's assigned role pivot (`UserRole`).
- Automatic global scope enforcement via `OperatingUnitScoping` middleware.

---

## Testing & Code Quality

### Run Automated Pest Tests
This project uses **Pest PHP** for testing:

```bash
# Run all Pest tests
php artisan test --compact

# Filter specific test suite
php artisan test --compact --filter=EntityManagementTest
```

### Code Formatting (Laravel Pint)
Format PHP code to meet PSR-12 and Laravel guidelines:
```bash
vendor/bin/pint --dirty --format agent
```

---

## API Documentation & Specifications

Detailed module design specs and architectural blueprints are located in `docs/`:
- `docs/superpowers/specs/2026-07-25-unified-entity-system-design.md`: Unified Entity Architecture
- `docs/phase-01-foundation.md`: Multi-Unit Architecture & Provisioning
- `docs/erd.md`: Entity Relationship Blueprint
