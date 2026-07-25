# FINE ERP Backend API

An enterprise multi-tenant ERP backend built with **Laravel 12**, **PHP 8.4**, and **Sanctum Authentication**. Engineered for high-throughput manufacturing, inventory, procurement, POS, HR, accounting, and multi-unit operating architectures.

---

## 🚀 Requirements

- **PHP**: 8.4 or higher
- **Composer**: 2.x
- **Database**: SQLite (default for development), PostgreSQL, or MySQL
- **Extensions**: `pdo`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`

---

## 🛠️ Installation & Setup Guide

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

## 🗄️ Database Migrations & Seeders

### Run Migrations & Seeders
Execute database migrations and seed the default initial data (companies, blueprints, operating units, roles, users):

```bash
# Run migrations and seed database
php artisan migrate --seed
```

### Reset & Fresh Seed
To completely reset the database and re-seed all initial records:
```bash
php artisan migrate:fresh --seed
```

---

## 👤 Default Seeded Credentials

When running `php artisan db:seed`, the system automatically provisions the initial company (**Al-Amana Foam & Furniture Co.**), operating units, blueprints, and standard user accounts.

All default accounts use the password: `password`

| Role | Email | Scope |
| :--- | :--- | :--- |
| **Global Owner** | `owner@erp.com` | Company-wide (Full Admin) |
| **Procurement Manager** | `procurement@erp.com` | Central Procurement & Treasury Unit |
| **Foam Plant Manager** | `foam@erp.com` | Tajoura Foam Manufactory Unit |
| **Cutter Manager** | `cutter@erp.com` | Cutter Plant A Unit |
| **Furniture Manager** | `furniture@erp.com` | Furniture Assembly Unit B |
| **Showroom Manager** | `showroom@erp.com` | Tripoli Main Showroom |

*Note: Unit-scoped managers have `must_change_password` set to `true` by default upon initial sign-in.*

---

## ⚡ Running the Server

Start the local development server:
```bash
php artisan serve
```

The API will be available at `http://127.0.0.1:8000`.

---

## 🏛️ Architecture & Key System Modules

### Unified Entity System (Decoupled Non-User Support)
- **Decoupled Persons & Organizations**: `entities` (Employees, B2B Clients, Third-Party Employer Agencies) operate as first-class domain entities **without requiring system `User` login accounts**.
- **Multi-Role Assignment**: A single real-world organization can hold multiple roles (`client`, `external_employer`, `vendor`).
- **On-Demand User Provisioning**: Admins can provision software access on demand via `POST /api/v1/entities/{id}/provision-user`.

### Multi-Tenant Operating Unit Scoping
- All unit-scoped requests require the `X-Operating-Unit-ID` HTTP header matching the user's assigned role pivot (`UserRole`).
- Automatic global scope enforcement via `OperatingUnitScoping` middleware.

---

## 🧪 Testing & Code Quality

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

## 📑 API Documentation & Specifications

Detailed module design specs and architectural blueprints are located in `docs/`:
- `docs/superpowers/specs/2026-07-25-unified-entity-system-design.md`: Unified Entity Architecture
- `docs/phase-01-foundation.md`: Multi-Unit Architecture & Provisioning
- `docs/erd.md`: Entity Relationship Blueprint
