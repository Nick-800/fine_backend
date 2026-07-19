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

## 13. Complete Entity Inventory Table

| # | Entity | Module | Key Purpose |
|---|--------|--------|-------------|
| 1 | `companies` | Foundation | Company-wide settings (costing policy, transfer pricing) |
| 2 | `operating_units` | Foundation | Generic unit abstraction for dynamic provisioning |
| 3 | `unit_blueprints` | Foundation | Reusable templates for spinning up new units |
| 4 | `warehouses` | Inventory | Physical stock locations per unit |
| 5 | `users` | Auth | System login accounts |
| 6 | `roles` | Auth | Role definitions |
| 7 | `user_roles` | Auth | Many-to-many user-role with unit scoping |
| 8 | `permissions` | Auth | Granular permission definitions |
| 9 | `role_permissions` | Auth | Role-permission bridge |
| 10 | `audit_logs` | Audit | Full change tracking |
| 11 | `suppliers` | Procurement | Foreign vendors |
| 12 | `import_orders` | Procurement | Foreign purchase orders with state machine |
| 13 | `payment_requests` | Treasury / Procurement | Treasury payment execution |
| 14 | `bank_holds` | Treasury | Bank hold/release buffer tracking |
| 15 | `landed_cost_lines` | Procurement | Per-component landed cost |
| 16 | `goods_receipts` | Procurement / Inventory | Physical receipt confirmation |
| 17 | `fx_rates` | Treasury | Exchange rate snapshots |
| 18 | `cash_accounts` | Treasury | Multi-currency treasury accounts |
| 19 | `inventory_items` | Inventory | Product/SKU master data |
| 20 | `stock_lots` | Inventory | Serialized or weighted-avg inventory units |
| 21 | `stock_movements` | Inventory | All inventory transactions |
| 22 | `production_batches` | Foam Mfg | One foam machine run |
| 23 | `consumption_reports` | Foam Mfg | Machine-reported chemical draw |
| 24 | `tank_stocks` | Foam Mfg | Weighted-average chemical tank tracking |
| 25 | `consumption_lines` | Foam Mfg | Per-chemical consumption per batch |
| 26 | `foam_blocks` | Foam Mfg | Individually graded block outputs |
| 27 | `cutter_work_orders` | Cutter Mfg | Cutting production orders |
| 28 | `work_order_lines` | Cutter Mfg | Per-line shape/template requirements |
| 29 | `foam_block_consumptions` | Cutter Mfg | Specific block-to-line consumption |
| 30 | `byproduct_yields` | Cutter Mfg | Automatic byproduct generation |
| 31 | `slices` | Cutter Mfg / Inventory | Slice item definitions |
| 32 | `products` | Furniture Mfg | Finished goods catalog |
| 33 | `boms` | Furniture Mfg | Bill of Materials versions |
| 34 | `bom_component_lines` | Furniture Mfg | Material requirements per BOM |
| 35 | `labor_requirements` | Furniture Mfg | Labor needs per BOM |
| 36 | `production_orders` | Furniture Mfg | Build orders |
| 37 | `labor_logs` | Furniture Mfg / HR | Actual labor time logged |
| 38 | `clients` | Sales | External customers with credit limits |
| 39 | `sales_orders` | Sales | Unified order model (external + internal) |
| 40 | `sales_order_lines` | Sales | Order line items |
| 41 | `credit_approval_requests` | Sales | Over-limit escalation |
| 42 | `pos_sales` | POS | Retail counter sales |
| 43 | `pos_sale_lines` | POS | POS line items |
| 44 | `internal_restock_requests` | POS / Inventory | Store-to-unit restock workflow |
| 45 | `chart_of_accounts` | Accounting | CoA header |
| 46 | `accounts` | Accounting | Ledger accounts |
| 47 | `journal_entries` | Accounting | Double-entry headers |
| 48 | `journal_lines` | Accounting | Debits & credits |
| 49 | `subledgers` | Accounting | Per-unit period rollups |
| 50 | `overhead_expenses` | Accounting | Non-operational costs |
| 51 | `overhead_allocation_rules` | Accounting | Distribution rules |
| 52 | `overhead_allocations` | Accounting | Allocated postings |
| 53 | `fixed_assets` | Accounting | Capitalized assets |
| 54 | `depreciation_entries` | Accounting | Period depreciation |
| 55 | `employees` | HR | Employee master records |
| 56 | `attendances` | HR | Daily presence |
| 57 | `labor_role_rates` | HR | Versioned pay rates |
| 58 | `payroll_runs` | HR | Payroll periods |
| 59 | `payslips` | HR | Individual payroll results |
| 60 | `leave_requests` | HR | Time-off requests |

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
