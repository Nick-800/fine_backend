# Foam-to-Furniture Manufacturing ERP — Entity Relationship Diagram

> Full ERD for the Foam Furniture ERP as defined in the SRS.  
> Stack: Laravel API + PostgreSQL | Next.js Owner Dashboard | Browser-based Unit Clients

---

## 1. Core / Foundation Layer

```mermaid
erDiagram
    companies ||--o{ operating_units : "owns"
    companies ||--o{ chart_of_accounts : "defines"
    companies {
        uuid id PK
        string name
        string default_currency
        boolean overhead_absorption_enabled
        string transfer_pricing_mode
        string timezone
        timestamps
    }

    operating_units ||--o{ warehouses : "has"
    operating_units ||--o{ employees : "employs"
    operating_units ||--o{ users : "assigns"
    operating_units ||--o{ stock_lots : "holds"
    operating_units ||--o{ fixed_assets : "owns"
    operating_units ||--o{ overhead_expenses : "incurs"
    operating_units ||--o{ journal_lines : "posts to"
    operating_units {
        uuid id PK
        uuid company_id FK
        uuid blueprint_id FK
        string name
        enum unit_type "manufactory|store|office"
        string currency
        enum status "provisioning|active|inactive"
        timestamps
    }

    unit_blueprints ||--o{ operating_units : "templates"
    unit_blueprints {
        uuid id PK
        string name
        json workflow_set
        json default_role_template
        json default_inventory_config
        timestamps
    }

    warehouses ||--o{ stock_lots : "stores"
    warehouses {
        uuid id PK
        uuid operating_unit_id FK
        string name
        boolean is_internal_unit
        timestamps
    }
```

---

## 2. Authentication & Authorization Layer

```mermaid
erDiagram
    users ||--o{ user_roles : "has"
    users ||--o{ audit_logs : "generates"
    users {
        uuid id PK
        string name
        string email UK
        string password_hash
        boolean is_active
        integer record_version
        soft_delete
        timestamps
    }

    roles ||--o{ user_roles : "assigned via"
    roles ||--o{ role_permissions : "grants"
    roles {
        uuid id PK
        string name UK
        string slug UK
        string description
        timestamps
    }

    user_roles {
        uuid id PK
        uuid user_id FK
        uuid role_id FK
        uuid operating_unit_id FK "nullable — null = company-wide"
        timestamps
    }

    permissions ||--o{ role_permissions : "assigned to"
    permissions {
        uuid id PK
        string name UK
        string slug UK
        string module "procurement|treasury|inventory|..."
        enum action "view|create|edit|approve|delete"
        timestamps
    }

    role_permissions {
        uuid id PK
        uuid role_id FK
        uuid permission_id FK
        timestamps
    }

    audit_logs {
        uuid id PK
        uuid user_id FK "nullable"
        string table_name
        uuid record_id
        string action "create|update|delete"
        json old_values
        json new_values
        string ip_address
        timestamp created_at
    }
```

---

## 3. Procurement & Import Management

```mermaid
erDiagram
    suppliers ||--o{ import_orders : "supplies"
    suppliers {
        uuid id PK
        string name
        string contact
        string default_currency
        text address
        timestamps
    }

    import_orders ||--o{ landed_cost_lines : "accumulates"
    import_orders ||--o{ goods_receipts : "receives"
    import_orders ||--o{ payment_requests : "triggers"
    import_orders ||--o{ journal_entries : "sources"
    import_orders ||--o{ stock_movements : "receives into"
    import_orders {
        uuid id PK
        uuid supplier_id FK
        string currency
        decimal negotiated_price
        decimal quantity
        enum status "draft|pending_payment|awaiting_bank_approval|awaiting_transfer|paid|in_transit|at_port|awaiting_receipt|received|complete"
        integer record_version
        timestamps
    }

    payment_requests ||--o| bank_holds : "may have"
    payment_requests ||--o{ journal_entries : "sources"
    payment_requests {
        uuid id PK
        uuid import_order_id FK
        enum route "bank|market"
        string invoice_ref
        decimal amount_requested
        enum status "pending|paid|rejected"
        decimal fx_rate_used
        timestamps
    }

    bank_holds {
        uuid id PK
        uuid payment_request_id FK
        decimal held_amount_lyd
        decimal exact_amount_used
        decimal released_amount
        string bank_reference
        timestamps
    }

    landed_cost_lines {
        uuid id PK
        uuid import_order_id FK
        enum type "supplier_price|fx_spread|customs|freight|local_transport|other"
        decimal amount
        string currency
        boolean is_confirmed
        timestamps
    }

    goods_receipts {
        uuid id PK
        uuid import_order_id FK
        uuid warehouse_id FK
        decimal received_qty
        text condition_notes
        timestamps
    }

    fx_rates {
        uuid id PK
        string from_currency
        string to_currency
        decimal rate
        timestamp captured_at
        timestamps
    }
```

---

## 4. Treasury

```mermaid
erDiagram
    cash_accounts ||--o{ journal_lines : "posts to"
    cash_accounts {
        uuid id PK
        string name
        string currency
        enum account_type "cash|bank|escrow"
        decimal balance
        timestamps
    }

    bank_holds ||--o{ journal_entries : "sources"
```

---

## 5. Inventory (Serialized & Weighted-Average)

```mermaid
erDiagram
    inventory_items ||--o{ stock_lots : "instantiates"
    inventory_items ||--o{ bom_component_lines : "used in"
    inventory_items {
        uuid id PK
        string name
        string sku UK
        enum item_type "raw_material|foam_block|cut_template_piece|slice|byproduct_fill|furniture_finished_good|packaging|barrel|pallet"
        enum unit_of_measure "each|m3|kg|meter|liter"
        timestamps
    }

    stock_lots ||--o{ stock_movements : "moved by"
    stock_lots ||--o{ work_order_lines : "consumed by"
    stock_lots ||--o{ production_orders : "reserved by"
    stock_lots {
        uuid id PK
        uuid inventory_item_id FK
        uuid warehouse_id FK
        uuid production_batch_id FK "nullable — for foam blocks"
        string lot_number UK
        decimal quantity
        decimal length_m "nullable"
        decimal width_m "nullable"
        decimal height_m "nullable"
        decimal volume_m3 "nullable"
        decimal weight_kg "nullable"
        decimal unit_cost
        enum grade "standard|acceptable_variant|defective_usable|reject" "nullable"
        enum status "available|reserved|consumed|damaged|quarantined"
        integer record_version
        soft_delete
        timestamps
    }

    stock_movements ||--o{ journal_entries : "sources"
    stock_movements {
        uuid id PK
        uuid stock_lot_id FK
        uuid from_warehouse_id FK "nullable"
        uuid to_warehouse_id FK "nullable"
        enum movement_type "receipt|issue|transfer|adjustment|consumption|production_output|byproduct_yield|sale"
        decimal quantity
        uuid reference_document_id "polymorphic FK via type"
        string reference_document_type "ImportOrder|ProductionBatch|SalesOrder|CutterWorkOrder|InternalRestockRequest|..."
        timestamps
    }
```

---

## 6. Foam Manufacturing — Batch Production

```mermaid
erDiagram
    production_batches ||--o{ consumption_reports : "generates"
    production_batches ||--o{ foam_blocks : "produces"
    production_batches ||--o{ journal_entries : "sources"
    production_batches {
        uuid id PK
        uuid requested_by_client_id FK "nullable — null = stock run"
        uuid operating_unit_id FK
        json formula_params "length|width|height|pressure|color|chemical_formula"
        enum status "planned|configured|running|consumed|curing|ready_for_grading|graded|closed"
        decimal material_cost
        integer record_version
        timestamps
    }

    consumption_reports ||--o{ consumption_lines : "contains"
    consumption_reports {
        uuid id PK
        uuid production_batch_id FK
        timestamp reported_at
        timestamps
    }

    tank_stocks ||--o{ consumption_lines : "drawn by"
    tank_stocks {
        uuid id PK
        uuid chemical_inventory_item_id FK
        uuid operating_unit_id FK
        decimal quantity_on_hand
        decimal weighted_avg_unit_cost
        timestamps
    }

    consumption_lines {
        uuid id PK
        uuid consumption_report_id FK
        uuid tank_stock_id FK
        decimal quantity_consumed
        decimal unit_cost_at_consumption "snapshot of tank_stock weighted_avg at runtime"
        timestamps
    }

    foam_blocks ||--o| stock_lots : "is a"
    foam_blocks {
        uuid id PK
        uuid production_batch_id FK
        uuid stock_lot_id FK "polymorphic link"
        decimal length_m
        decimal width_m
        decimal height_m
        decimal volume_m3
        enum grade "standard|acceptable_variant|defective_usable|reject"
        timestamps
    }
```

---

## 7. Cutter Manufacturing — Work Orders

```mermaid
erDiagram
    cutter_work_orders ||--o{ work_order_lines : "contains"
    cutter_work_orders ||--o{ byproduct_yields : "generates"
    cutter_work_orders ||--o{ journal_entries : "sources"
    cutter_work_orders {
        uuid id PK
        uuid client_id FK "nullable — external"
        uuid requesting_unit_id FK "nullable — internal"
        uuid operating_unit_id FK
        enum buyer_type "external_client|internal_unit"
        enum status "requested|confirmed|in_production|awaiting_byproduct_weigh_in|quality_check|completed|invoiced"
        integer record_version
        timestamps
    }

    work_order_lines ||--o{ foam_block_consumptions : "consumes"
    work_order_lines {
        uuid id PK
        uuid cutter_work_order_id FK
        text requested_spec "client-facing shape description"
        json template_shape "bounding L×W×H for production/costing"
        integer quantity
        decimal unit_cost
        timestamps
    }

    foam_block_consumptions {
        uuid id PK
        uuid work_order_line_id FK
        uuid foam_block_stock_lot_id FK
        decimal volume_consumed_m3
        enum consumption_type "full|partial"
        timestamps
    }

    byproduct_yields {
        uuid id PK
        uuid cutter_work_order_id FK
        enum type "offcut_fill|hard_top_fill"
        decimal weight_kg
        timestamps
    }

    slices {
        uuid id PK
        uuid inventory_item_id FK "references InventoryItem of type=slice"
        decimal width
        decimal length
        decimal thickness
        decimal unit_cost
        timestamps
    }
```

---

## 8. Furniture Manufacturing — BOM & Production

```mermaid
erDiagram
    products ||--o{ production_orders : "built by"
    products ||--o{ boms : "has versions of"
    products {
        uuid id PK
        string name
        string description
        uuid base_bom_id FK "nullable"
        timestamps
    }

    boms ||--o{ bom_component_lines : "contains"
    boms ||--o{ labor_requirements : "requires"
    boms {
        uuid id PK
        uuid product_id FK
        string version_name
        boolean is_active
        decimal estimated_total_cost
        timestamps
    }

    bom_component_lines {
        uuid id PK
        uuid bom_id FK
        uuid inventory_item_id FK
        decimal quantity_required
        timestamps
    }

    labor_requirements {
        uuid id PK
        uuid bom_id FK
        enum role "tailor|carpenter|operator|assembler|upholsterer|other"
        decimal estimated_hours
        timestamps
    }

    production_orders ||--o{ labor_logs : "logs"
    production_orders ||--o{ journal_entries : "sources"
    production_orders {
        uuid id PK
        uuid product_id FK
        uuid adapted_bom_id FK "nullable — for custom orders"
        uuid client_id FK "nullable — for stock"
        uuid operating_unit_id FK
        enum status "requested|bom_confirmed|in_production|quality_check|ready_for_collection|completed"
        decimal total_labor_cost
        decimal total_material_cost
        integer record_version
        timestamps
    }

    labor_logs {
        uuid id PK
        uuid production_order_id FK
        uuid employee_id FK
        enum role "tailor|carpenter|operator|assembler|upholsterer|other"
        decimal hours_logged
        decimal hourly_rate_at_log
        timestamps
    }
```

---

## 9. Sales, POS & Credit

```mermaid
erDiagram
    clients ||--o{ sales_orders : "places"
    clients {
        uuid id PK
        string name
        string contact
        decimal credit_limit
        decimal current_balance
        enum client_type "individual|company"
        timestamps
    }

    sales_orders ||--o{ sales_order_lines : "contains"
    sales_orders ||--o{ credit_approval_requests : "may trigger"
    sales_orders ||--o{ journal_entries : "sources"
    sales_orders {
        uuid id PK
        uuid seller_unit_id FK
        enum buyer_type "external_client|internal_unit"
        uuid client_id FK "nullable — if external"
        uuid buyer_unit_id FK "nullable — if internal"
        enum status "draft|credit_check|pending_approval|confirmed|fulfilled|paid|partially_paid|rejected"
        string currency
        decimal total_amount
        enum channel "sales|pos"
        integer record_version
        timestamps
    }

    sales_order_lines {
        uuid id PK
        uuid sales_order_id FK
        uuid inventory_item_id FK
        uuid stock_lot_id FK "nullable — serialized items"
        integer quantity
        decimal unit_price
        decimal line_total
        timestamps
    }

    credit_approval_requests {
        uuid id PK
        uuid sales_order_id FK
        uuid requested_by_user_id FK
        decimal amount_over_limit
        enum status "pending|approved|rejected"
        uuid approved_by_user_id FK "nullable"
        text approval_notes
        timestamps
    }

    pos_sales ||--o{ pos_sale_lines : "contains"
    pos_sales ||--o{ journal_entries : "sources"
    pos_sales {
        uuid id PK
        uuid sales_order_id FK "nullable — if linked to unified order model"
        uuid store_unit_id FK
        enum payment_method "cash|card|credit"
        decimal total_amount
        enum status "draft|completed|refunded"
        timestamps
    }

    pos_sale_lines {
        uuid id PK
        uuid pos_sale_id FK
        uuid inventory_item_id FK
        uuid stock_lot_id FK "nullable"
        integer quantity
        decimal unit_price
        timestamps
    }

    internal_restock_requests ||--o{ journal_entries : "sources"
    internal_restock_requests {
        uuid id PK
        uuid requesting_store_unit_id FK
        uuid target_unit_id FK
        enum status "requested|pending_approval|approved|fulfilled|rejected"
        uuid approved_by_user_id FK "nullable"
        timestamps
    }

    internal_restock_request_lines {
        uuid id PK
        uuid internal_restock_request_id FK
        uuid inventory_item_id FK
        integer quantity
        timestamps
    }
```

---

## 10. Full Accounting — Double-Entry Ledger

```mermaid
erDiagram
    chart_of_accounts ||--o{ accounts : "contains"
    chart_of_accounts {
        uuid id PK
        uuid company_id FK
        string name
        timestamps
    }

    accounts ||--o{ journal_lines : "debited/credited"
    accounts {
        uuid id PK
        uuid chart_of_accounts_id FK
        string account_code UK
        string name
        enum type "asset|liability|equity|revenue|expense"
        string currency
        uuid parent_account_id FK "nullable — for hierarchical CoA"
        timestamps
    }

    journal_entries ||--o{ journal_lines : "balanced by"
    journal_entries {
        uuid id PK
        uuid source_document_id "polymorphic FK"
        string source_document_type "ImportOrder|SalesOrder|ProductionBatch|CutterWorkOrder|ProductionOrder|PaymentRequest|FixedAsset|PayrollRun|..."
        string reference_number UK
        date entry_date
        text description
        boolean is_posted
        timestamps
    }

    journal_lines {
        uuid id PK
        uuid journal_entry_id FK
        uuid account_id FK
        uuid operating_unit_id FK "nullable — for subledger rollup"
        decimal debit
        decimal credit
        string currency
        decimal fx_rate "nullable"
        timestamps
    }

    subledgers {
        uuid id PK
        uuid operating_unit_id FK
        uuid account_id FK
        date period
        decimal opening_balance
        decimal closing_balance
        timestamps
    }
```

---

## 11. Overhead, Fixed Assets & Depreciation

```mermaid
erDiagram
    overhead_expenses ||--o{ overhead_allocations : "distributed via"
    overhead_expenses {
        uuid id PK
        uuid operating_unit_id FK "nullable — null = company-wide"
        enum type "water|electricity|rent|maintenance|other"
        decimal amount
        string currency
        string period "YYYY-MM"
        timestamps
    }

    overhead_allocation_rules ||--o{ overhead_allocations : "applies"
    overhead_allocation_rules {
        uuid id PK
        uuid overhead_expense_id FK
        enum method "even_split|usage_based|headcount_based|manual_percentage"
        json allocation_weights "{unit_id: percentage}"
        timestamps
    }

    overhead_allocations {
        uuid id PK
        uuid overhead_expense_id FK
        uuid operating_unit_id FK
        uuid journal_entry_id FK
        decimal allocated_amount
        timestamps
    }

    fixed_assets ||--o{ depreciation_entries : "depreciates via"
    fixed_assets ||--o{ journal_entries : "sources"
    fixed_assets {
        uuid id PK
        uuid operating_unit_id FK
        string name
        enum category "machine|vehicle|building|equipment|furniture"
        decimal acquisition_cost
        date acquisition_date
        integer useful_life_years
        decimal salvage_value
        enum depreciation_method "straight_line|declining_balance"
        enum status "active|under_maintenance|disposed"
        timestamps
    }

    depreciation_entries {
        uuid id PK
        uuid fixed_asset_id FK
        uuid journal_entry_id FK
        string period "YYYY-MM"
        decimal depreciation_amount
        decimal accumulated_depreciation
        decimal book_value_after
        timestamps
    }
```

---

## 12. HR & Payroll

```mermaid
erDiagram
    employees ||--o{ labor_logs : "logs"
    employees ||--o{ attendances : "has"
    employees ||--o{ payslips : "receives"
    employees ||--o{ leave_requests : "submits"
    employees {
        uuid id PK
        uuid operating_unit_id FK
        uuid user_id FK "nullable — links to login if employee has system access"
        string name
        string job_title
        enum labor_role "tailor|carpenter|operator|assembler|upholsterer|other|null"
        enum employment_type "salaried|daily_wage|hourly"
        decimal base_rate
        enum payment_frequency "monthly|biweekly|weekly"
        date hire_date
        timestamps
    }

    attendances {
        uuid id PK
        uuid employee_id FK
        date attendance_date
        enum status "present|absent|leave|half_day"
        decimal hours_worked
        timestamps
    }

    labor_role_rates {
        uuid id PK
        enum role "tailor|carpenter|operator|assembler|upholsterer|other"
        decimal rate
        date effective_from
        timestamps
    }

    payroll_runs ||--o{ payslips : "generates"
    payroll_runs ||--o{ journal_entries : "sources"
    payroll_runs {
        uuid id PK
        date period_start
        date period_end
        enum status "draft|calculated|pending_approval|approved|paid|posted"
        timestamps
    }

    payslips {
        uuid id PK
        uuid payroll_run_id FK
        uuid employee_id FK
        decimal gross_pay
        json deductions "[{type, amount, description}]"
        decimal net_pay
        uuid journal_entry_id FK "nullable — after posting"
        timestamps
    }

    leave_requests {
        uuid id PK
        uuid employee_id FK
        date date_start
        date date_end
        enum type "annual|sick|unpaid|other"
        enum status "pending|approved|rejected"
        timestamps
    }
```

---

## 13. Complete Entity Inventory Table (Authoritative 75 Tables)

The authoritative schema consists of **75 tables** across all domain modules and framework infrastructure (code-wins source of truth). Every table corresponds to an explicit database migration in `fine_backend/database/migrations/`.

| # | Table / Entity | Module | Classification | Key Purpose |
|---|----------------|--------|----------------|-------------|
| 1 | `companies` | Foundation | Core Domain | Company-wide multi-tenant configuration and financial settings |
| 2 | `operating_units` | Foundation | Core Domain | Operating unit abstraction (factories, showrooms, warehouses, admin) |
| 3 | `unit_blueprints` | Foundation | Core Domain | Reusable configuration templates for spinning up new units |
| 4 | `entities` | Foundation / CRM | Core Domain | Master identity records for legal entities, clients, employees, contractors |
| 5 | `entity_roles` | Foundation / CRM | Core Domain | Polymorphic roles assigned to entities (client, employee, vendor, employer) |
| 6 | `entity_contacts` | Foundation / CRM | Core Domain | Contact information, addresses, phone, and email records for entities |
| 7 | `users` | Auth & Security | Core Domain | User authentication accounts with password hash and credentials |
| 8 | `roles` | Auth & Security | Core Domain | Role-based access control role definitions |
| 9 | `user_roles` | Auth & Security | Core Domain | User-role assignment scoped by operating unit |
| 10 | `permissions` | Auth & Security | Core Domain | Fine-grained capability definitions |
| 11 | `role_permissions` | Auth & Security | Core Domain | M:N association of permissions to roles |
| 12 | `personal_access_tokens` | Auth & Security | Framework (Sanctum) | Bearer API authentication tokens |
| 13 | `password_reset_tokens` | Auth & Security | Framework (Laravel) | Password reset credentials |
| 14 | `sessions` | Auth & Security | Framework (Laravel) | HTTP session storage |
| 15 | `audit_logs` | Audit | Core Domain | Immutable audit trail for critical system actions |
| 16 | `suppliers` | Procurement | Core Domain | Foreign and local raw material vendors |
| 17 | `import_orders` | Procurement | Core Domain | International procurement orders governed by a 10-stage lifecycle |
| 18 | `landed_cost_lines` | Procurement | Core Domain | Landed cost lines (freight, customs, clearance, transit) capitalized to inventory |
| 19 | `goods_receipts` | Procurement / Inventory | Core Domain | Physical warehouse receipt confirmation |
| 20 | `payment_requests` | Treasury / Procurement | Core Domain | Commercial invoice payment requests with FX rates |
| 21 | `bank_holds` | Treasury | Core Domain | Central bank letter of credit buffer holds and release tracking |
| 22 | `payable_settlements` | Treasury / Accounting | Core Domain | Settlement register for liabilities (AP 2100, Payroll 2210, Landed Cost 2300) |
| 23 | `cash_accounts` | Treasury | Core Domain | Multi-currency cash, safe, and bank accounts |
| 24 | `fx_rates` | Treasury | Core Domain | Official and parallel foreign exchange rate history |
| 25 | `warehouses` | Inventory | Core Domain | Storage facilities and bins per operating unit |
| 26 | `inventory_items` | Inventory | Core Domain | Master item catalog (raw chemicals, foam blocks, cut foam, furniture, retail) |
| 27 | `item_categories` | Inventory | Core Domain | Hierarchical item taxonomy and classification |
| 28 | `inventory_attribute_definitions` | Inventory | Core Domain | Custom attribute definitions for flexible item and lot metadata |
| 29 | `inventory_item_attribute_definitions` | Inventory | Core Domain | Item-to-attribute mapping schema |
| 30 | `stock_lots` | Inventory | Core Domain | Tracked inventory lots (serialized blocks, rolls, bundles, tanks) |
| 31 | `inventory_movements` | Inventory | Core Domain | Immutable ledger of all inventory transactions (INV-06) |
| 32 | `stock_adjustment_requests` | Inventory | Core Domain | Approval-gated physical stock reconciliation requests |
| 33 | `internal_restock_requests` | Inventory / POS | Core Domain | Multi-unit stock replenishment headers |
| 34 | `internal_restock_request_lines` | Inventory / POS | Core Domain | Stock replenishment line details |
| 35 | `production_batches` | Foam Mfg | Core Domain | Continuous foam pouring runs with operation identity |
| 36 | `tank_stocks` | Foam Mfg | Core Domain | Chemical bulk storage tank levels and weighted-average costs |
| 37 | `consumption_reports` | Foam Mfg | Core Domain | Chemical consumption recording for foam runs |
| 38 | `consumption_lines` | Foam Mfg | Core Domain | Detailed chemical weight and cost consumption lines |
| 39 | `cutter_work_orders` | Cutter Mfg | Core Domain | Block-cutting production orders |
| 40 | `cutter_work_order_lines` | Cutter Mfg | Core Domain | Shape and piece cutting line specifications |
| 41 | `foam_block_consumptions` | Cutter Mfg | Core Domain | Traceability linking parent foam block to cutting jobs |
| 42 | `byproduct_yields` | Cutter Mfg | Core Domain | Secondary yield tracking (crumb, scrap, residual pieces) |
| 43 | `products` | Furniture Mfg | Core Domain | Finished goods catalog for manufactured furniture |
| 44 | `boms` | Furniture Mfg | Core Domain | Versioned Bills of Materials |
| 45 | `bom_component_lines` | Furniture Mfg | Core Domain | Required raw material components per BOM |
| 46 | `labor_requirements` | Furniture Mfg | Core Domain | Standard labor role times and sequence per BOM |
| 47 | `production_orders` | Furniture Mfg | Core Domain | Furniture manufacturing work orders |
| 48 | `labor_logs` | Furniture Mfg / HR | Core Domain | Actual labor hours logged by workers against production orders |
| 49 | `clients` | Sales & POS | Core Domain | Customers with credit limits, payment terms, and balances |
| 50 | `sales_orders` | Sales & POS | Core Domain | Unified orders (wholesale, counter POS, internal transfer) |
| 51 | `sales_order_lines` | Sales & POS | Core Domain | Sales order line items |
| 52 | `credit_approval_requests` | Sales & POS | Core Domain | Credit limit override escalations to company management |
| 53 | `pos_daily_closes` | Sales & POS | Core Domain | POS daily register close and Z-report financial sessions |
| 54 | `chart_of_accounts` | Accounting | Core Domain | Chart of accounts header definition |
| 55 | `accounts` | Accounting | Core Domain | General ledger accounts (Assets, Liabilities, Equity, Revenue, COGS, Expenses) |
| 56 | `journal_entries` | Accounting | Core Domain | Double-entry journal transaction headers |
| 57 | `journal_lines` | Accounting | Core Domain | Balanced debit and credit ledger lines with operating unit scoping |
| 58 | `overhead_expenses` | Accounting | Core Domain | Factory and corporate overhead cost pools |
| 59 | `overhead_allocation_rules` | Accounting | Core Domain | Allocation basis rules across operating units |
| 60 | `overhead_allocations` | Accounting | Core Domain | Executed overhead allocation runs |
| 61 | `fixed_assets` | Accounting | Core Domain | Capitalized tangible assets and equipment |
| 62 | `depreciation_entries` | Accounting | Core Domain | Monthly straight-line asset depreciation records |
| 63 | `employees` | HR & Payroll | Core Domain | Workforce master records and wage assignments |
| 64 | `external_employers` | HR & Payroll | Core Domain | Staffing agencies and contracted labor providers |
| 65 | `attendances` | HR & Payroll | Core Domain | Daily presence and hours worked |
| 66 | `labor_role_rates` | HR & Payroll | Core Domain | Effective dated labor rates for costing and payroll |
| 67 | `payroll_runs` | HR & Payroll | Core Domain | Monthly payroll cycle headers |
| 68 | `payslips` | HR & Payroll | Core Domain | Detailed employee payslips with earnings and deductions |
| 69 | `leave_requests` | HR & Payroll | Core Domain | Employee time-off requests and approvals |
| 70 | `cache` | System | Framework (Laravel) | Key-value application cache storage |
| 71 | `cache_locks` | System | Framework (Laravel) | Distributed atomic locks |
| 72 | `jobs` | System | Framework (Laravel) | Asynchronous background job queue |
| 73 | `job_batches` | System | Framework (Laravel) | Batched background job tracking |
| 74 | `failed_jobs` | System | Framework (Laravel) | Dead-letter queue for failed asynchronous jobs |
| 75 | `work_orders` | Legacy / Offline | Superseded Artifact | Table from superseded 2026-08-01 offline sync design (retained) |

---

### 13.1 Divergences & Deliberate Architectural Decisions

A rigorous audit of the code base against the original design specifications reveals 6 deliberate naming or structural consolidations, and 1 derived structure:

1. **`stock_movements` → `inventory_movements`**:
   - *Code reality*: Implemented as `inventory_movements` (`2026_08_01_082530_create_inventory_movements_table.php`).
   - *Design rationale*: Aligns with the core inventory model `InventoryMovement` and enforces the INV-06 append-only transaction ledger invariant across all warehouses and production flows.

2. **`work_order_lines` → `cutter_work_order_lines`**:
   - *Code reality*: Implemented as `cutter_work_order_lines` (`2026_08_12_140000_create_cutter_tables.php`).
   - *Design rationale*: Disambiguates cutter line specifications from furniture assembly orders and legacy work order tables.

3. **`foam_blocks` → Consolidated into `stock_lots`**:
   - *Code reality*: Handled as serialized `stock_lots` with `production_batch_id` and foam-block identity columns (`2026_08_10_150001_add_foam_block_identity_to_stock_lots_table.php`).
   - *Design rationale*: Eliminates redundant inventory tracking; blocks are first-class stock lots supporting warehouse moves, cutting consumption, or direct sales (HANDOFF §3 "Foam block identity"; spec `2026-08-10-foam-block-identity-and-batches-design.md`).

4. **`slices` → Consolidated into `inventory_items` & `stock_lots`**:
   - *Code reality*: Implemented via `inventory_items` (`item_type = 'slice'`) and standard lots.
   - *Design rationale*: Standardizes pricing, BOM consumption, and stock valuation through the unified item model (Phase 05 §4.2; FUR-02).

5. **`pos_sales` & `pos_sale_lines` → Unified with `sales_orders` & `sales_order_lines`**:
   - *Code reality*: POS transactions create standard `sales_orders` with `channel = 'pos'` (`2026_08_12_180000_create_sales_tables.php`). Daily financial closes and Z-reports persist in `pos_daily_closes` (`2026_08_20_130000_create_pos_daily_closes_table.php`).
   - *Design rationale*: Avoids split business logic between retail and wholesale channels while maintaining full auditing and register reconciliation (Phase 07 §4.2; SALE-04; K10).

6. **`subledgers` → Derivable from `journal_lines`**:
   - *Code reality*: No separate static `subledgers` table exists.
   - *Design rationale*: Every journal line carries `operating_unit_id`. Operating-unit subledgers and trial balances are derived dynamically from line postings on demand, eliminating out-of-sync aggregation caches (HANDOFF §3; ACC-05; migration `2026_08_12_100000` L44–46).

7. **`work_orders` → Retained Superseded Artifact**:
   - *Code reality*: Table created in migration `2026_08_01_082522` under the initial offline-sync prototype.
   - *Design rationale*: Superseded by the Direct Online-Only Local Server Model (`2026-08-04-online-only-local-server-design.md`). Retained in the database to avoid destructive migration rewrites.

---

## 14. Cross-Module Relationship Summary

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         CROSS-MODULE DATA FLOW MAP                          │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  PROCUREMENT ──► TREASURY (PaymentRequest, BankHold, FX)                    │
│       │                                                                     │
│       ▼                                                                     │
│  GOODS RECEIPT ──► INVENTORY (Main Warehouse, weighted-avg raw materials)   │
│       │                                                                     │
│       ▼                                                                     │
│  TANK REFILL ──► FOAM MFG (ProductionBatch + ConsumptionReport)             │
│       │                                                                     │
│       ▼                                                                     │
│  FOAM BLOCKS ──► INVENTORY (Serialized StockLots, graded)                   │
│       │                                                                     │
│       ├──► SALES (external, credit-gated)                                   │
│       │                                                                     │
│       └──► CUTTER MFG (WorkOrder, manual block selection)                   │
│                  │                                                          │
│                  ├──► BYPRODUCT ──► INVENTORY (loose fill, kg)              │
│                  │                                                          │
│                  ├──► SLICES ──► INVENTORY                                  │
│                  │                                                          │
│                  └──► TEMPLATE PIECES ──► INVENTORY                         │
│                            │                                                │
│                            ├──► SALES (external / internal)                 │
│                            │                                                │
│                            └──► FURNITURE MFG (BOM components)              │
│                                          │                                  │
│                                          ▼                                  │
│                                   FINISHED GOODS ──► INVENTORY              │
│                                          │                                  │
│                                          ├──► SALES (external)              │
│                                          │                                  │
│                                          └──► STORE (InternalRestock)       │
│                                                    │                        │
│                                                    ▼                        │
│                                              POS SALES                      │
│                                                                             │
│  ALL EVENTS ──► ACCOUNTING (auto JournalEntry per Section 17 event list)    │
│                                                                             │
│  LABOR ──► HR (Attendance, LaborLog) ──► PayrollRun ──► Payslip ──► ACCT    │
│                                                                             │
│  FIXED ASSETS ──► DepreciationEntry ──► ACCT                                │
│                                                                             │
│  OVERHEAD ──► OverheadExpense ──► Allocation ──► ACCT                       │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 15. Indexing Strategy (PostgreSQL)

| Table | Index(es) | Rationale |
|-------|-----------|-----------|
| `stock_lots` | `(inventory_item_id, status, warehouse_id)` | Fast inventory lookups |
| `stock_lots` | `(grade, volume_m3)` | Block selection filtering for cutter |
| `stock_movements` | `(reference_document_type, reference_document_id)` | Polymorphic lookup |
| `journal_entries` | `(source_document_type, source_document_id)` | Trace from event to ledger |
| `journal_lines` | `(account_id, operating_unit_id, created_at)` | Subledger reporting |
| `sales_orders` | `(client_id, status)` | Credit limit evaluation |
| `import_orders` | `(supplier_id, status)` | Procurement pipeline views |
| `production_batches` | `(operating_unit_id, status)` | Batch pipeline per unit |
| `cutter_work_orders` | `(operating_unit_id, status)` | Work order pipeline |
| `audit_logs` | `(table_name, record_id, created_at)` | Record-level history |
| `users` | `(email)` | Auth lookups |
| `inventory_items` | `(sku)` | SKU uniqueness |
| `fx_rates` | `(from_currency, to_currency, captured_at DESC)` | Latest rate lookup |
