# Phase 08: Full Accounting, Overhead & Fixed Assets

> **Duration Estimate:** 5–6 weeks  
> **Team Size:** 2 backend engineers, 1 frontend engineer (shared with Phase 09)  
> **Dependencies:** Phase 01 (Foundation), Phase 02–07 (all operational modules)

---

## 8.1 Objective

Build the complete double-entry accounting ledger that automatically posts balanced journal entries for every business event defined in the cross-module integration list (Section 17 of SRS). Includes chart of accounts, journal entries with polymorphic source document linking, per-unit subledgers, landed cost allocation, FX gain/loss, overhead expense tracking and allocation, fixed asset register, and depreciation processing.

---

## 8.2 Deliverables

| # | Deliverable | Acceptance Criteria |
|---|-------------|---------------------|
| 1 | Chart of Accounts | Hierarchical CoA with account codes, types (asset/liability/equity/revenue/expense), multi-currency support |
| 2 | Auto journal posting engine | Every event from Section 17 auto-creates balanced JournalEntry via observers/services |
| 3 | Journal entry management | View, filter, search by source document, date, account; manual adjustment capability for corrections |
| 4 | Subledger reporting | Per-unit period rollups derived from JournalLine.unit_id |
| 5 | Landed cost allocation | ImportOrder.Complete allocates all LandedCostLines into raw-material inventory value |
| 6 | FX gain/loss posting | Auto-calculated and posted when realized rate differs from booked estimate |
| 7 | Overhead expense tracking | Water, electricity, rent, maintenance entry per unit or company-wide |
| 8 | Overhead allocation rules | Configurable methods: even_split, usage_based, headcount_based, manual_percentage |
| 9 | Full-absorption costing toggle | Company-level `overhead_absorption_enabled` flag affecting inventory valuation |
| 10 | Fixed asset register | Acquisition, depreciation method, useful life, salvage value tracking |
| 11 | Depreciation processing | Monthly straight-line or declining-balance; auto-posts journal entries |
| 12 | Asset disposal | Gain/loss calculation against remaining book value |
| 13 | Unit client: Accounting screens | Journal entry viewer, CoA tree, subledger reports, trial balance |
| 14 | Owner dashboard: Financial KPIs | Revenue, COGS, gross margin, net profit, unit profitability |

---

## 8.3 Database Migrations (This Phase)

- `chart_of_accounts`
- `accounts`
- `journal_entries`
- `journal_lines`
- `subledgers`
- `overhead_expenses`
- `overhead_allocation_rules`
- `overhead_allocations`
- `fixed_assets`
- `depreciation_entries`

---

## 8.4 Journal Entry Templates by Event

### Event: ImportOrder.Complete
```
DR  Raw Material Inventory (at landed cost per unit)
CR  Accounts Payable — Supplier
CR  FX Gain/Loss Clearing (if applicable)

// Landed cost allocation:
DR  Raw Material Inventory
CR  Landed Cost Clearing (customs)
CR  Landed Cost Clearing (freight)
CR  Landed Cost Clearing (local transport)
```

### Event: ProductionBatch.Closed (Foam)
```
DR  Finished Goods Inventory — Foam Blocks
CR  Work In Process — Foam Production

// If labor cost included:
DR  Finished Goods Inventory — Foam Blocks
CR  Wages Payable / Labor Cost Clearing
```

### Event: CutterWorkOrder.Completed
```
DR  Finished Goods Inventory — Cut Pieces
DR  Finished Goods Inventory — Slices
DR  Finished Goods Inventory — Byproduct Fill
CR  Work In Process — Cutter Production
CR  Raw Materials Inventory — Foam Blocks
```

### Event: FurnitureProductionOrder.ReadyForCollection
```
DR  Finished Goods Inventory — Furniture
CR  Work In Process — Furniture Production
CR  Wages Payable / Labor Cost Clearing
```

### Event: SalesOrder.Fulfilled (External)
```
DR  Accounts Receivable — Client
CR  Sales Revenue

// COGS:
DR  Cost of Goods Sold
CR  Finished Goods Inventory — [Selling Unit]
```

### Event: SalesOrder.Fulfilled (Internal)
```
// At cost (v1 default):
DR  Inventory — Buyer Unit
CR  Inventory — Seller Unit

// At transfer price (future):
DR  Inventory — Buyer Unit (at transfer price)
CR  Inventory — Seller Unit (at cost)
CR  Inter-Unit Profit (difference)
```

### Event: POSSale.Completed
```
DR  Cash / Card Receivable
CR  Sales Revenue — POS

// COGS:
DR  Cost of Goods Sold
CR  Finished Goods Inventory — Store
```

### Event: PaymentRequest.Paid
```
// If realized rate differs from booked:
DR  FX Loss (if realized > booked)
CR  FX Gain (if realized < booked)
CR  Accounts Payable
```

### Event: PayrollRun.Posted
```
DR  Wage Expense — [Unit]
CR  Cash / Bank (net pay)
CR  Social Security Payable (if applicable)
CR  Tax Payable (if applicable)
```

### Event: DepreciationEntry (Monthly)
```
DR  Depreciation Expense — [Unit]
CR  Accumulated Depreciation — [Asset]
```

### Event: Asset Disposal
```
DR  Cash (proceeds)
DR  Accumulated Depreciation (total)
DR  Loss on Disposal (if proceeds < book value)
CR  Fixed Asset (original cost)
CR  Gain on Disposal (if proceeds > book value)
```

---

## 8.5 Overhead Allocation

### OverheadExpense Entry
```
// Company-wide overhead (e.g., HQ rent):
DR  Overhead Expense — Allocated
CR  Cash / Payable

// Unit-specific overhead (e.g., Foam unit electricity):
DR  Overhead Expense — Foam Unit
CR  Cash / Payable
```

### Allocation Rules

| Method | Description |
|--------|-------------|
| `even_split` | Divide equally across all active units |
| `usage_based` | Divide by measured usage (e.g., kWh for electricity) |
| `headcount_based` | Divide by employee count per unit |
| `manual_percentage` | Explicit percentage per unit |

### Allocation Posting
```
// If overhead_absorption_enabled = true:
DR  Work In Process — [Unit]
CR  Overhead Expense — Allocated

// If overhead_absorption_enabled = false:
// Overhead stays as period expense; no WIP absorption
```

---

## 8.6 Depreciation Calculation

### Straight-Line
```
annual_depreciation = (acquisition_cost - salvage_value) / useful_life_years
monthly_depreciation = annual_depreciation / 12
```

### Declining Balance
```
rate = 2 / useful_life_years  // double-declining
annual_depreciation = book_value_at_start × rate
monthly_depreciation = annual_depreciation / 12
```

### Asset State Machine
```
Active ──[Monthly depreciation run]──► Active
  • Creates DepreciationEntry
  • Posts Depreciation Expense / Accumulated Depreciation JE

Active ──[Repair needed]──► UnderMaintenance
  • Optional: pause depreciation (configurable per company policy)

UnderMaintenance ──[Repair complete]──► Active

Active ──[Sold/scrapped]──► Disposed
  • Posts disposal journal entry
  • Gain/loss = proceeds - remaining_book_value
```

---

## 8.7 API Endpoints (This Phase)

### Chart of Accounts
```
GET    /api/v1/chart-of-accounts
POST   /api/v1/chart-of-accounts
GET    /api/v1/accounts
POST   /api/v1/accounts
GET    /api/v1/accounts/{id}
PUT    /api/v1/accounts/{id}
GET    /api/v1/accounts/{id}/ledger
```

### Journal Entries
```
GET    /api/v1/journal-entries
POST   /api/v1/journal-entries                        ← manual entries only
GET    /api/v1/journal-entries/{id}
GET    /api/v1/journal-entries/for-document/{type}/{id}
GET    /api/v1/journal-entries/unposted
POST   /api/v1/journal-entries/{id}/post
```

### Subledgers & Reports
```
GET    /api/v1/subledgers
GET    /api/v1/reports/trial-balance
GET    /api/v1/reports/income-statement
GET    /api/v1/reports/balance-sheet
GET    /api/v1/reports/unit-profitability
```

### Overhead
```
GET    /api/v1/overhead-expenses
POST   /api/v1/overhead-expenses
POST   /api/v1/overhead-expenses/{id}/allocate
```

### Fixed Assets
```
GET    /api/v1/fixed-assets
POST   /api/v1/fixed-assets
GET    /api/v1/fixed-assets/{id}
PUT    /api/v1/fixed-assets/{id}
POST   /api/v1/fixed-assets/{id}/depreciate            ← manual run
POST   /api/v1/fixed-assets/{id}/dispose
GET    /api/v1/fixed-assets/{id}/depreciation-schedule
```

---

## 8.8 Key Business Rules

| Rule ID | Description | Enforcement |
|---------|-------------|-------------|
| ACC-01 | Every journal entry must balance (sum debits = sum credits) | Validation before save; reject if unbalanced |
| ACC-02 | Every financially impactful event auto-posts a journal entry | Event observers in each operational module call `AccountingService::postJournal()` |
| ACC-03 | Journal entries link to source document via polymorphic relation | `source_document_type` + `source_document_id` required on auto-posted entries |
| ACC-04 | Manual journal entries allowed for corrections only | Policy: only Accounting Manager+ can create manual entries |
| ACC-05 | Subledger rollups are read-only derived views | Computed from journal_lines; not directly editable |
| ACC-06 | Overhead allocation respects company-level `overhead_absorption_enabled` flag | Service checks flag before posting WIP absorption |
| ACC-07 | Depreciation runs monthly via scheduled job | Laravel Scheduler command; can also be triggered manually |
| ACC-08 | Asset disposal calculates gain/loss correctly | Service method: `proceeds - (acquisition_cost - accumulated_depreciation)` |
| ACC-09 | FX gain/loss posted to dedicated account | CoA account code for FX Gain/Loss required |
| ACC-10 | Landed cost allocated on ImportOrder.Complete, not on receipt | Observer triggers only on Complete transition |

---

## 8.9 Testing Strategy

- **Unit tests:** Depreciation formulas, overhead allocation calculations, gain/loss on disposal
- **Feature tests:** Each event from Section 17 produces correct journal entry; trial balance balances
- **Integration tests:** End-to-end: ImportOrder → LandedCost → JournalEntry → Trial Balance

---

## 8.10 Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Journal entries become unbalanced due to rounding | Medium | Store amounts with 4 decimal precision; validation check with small tolerance |
| Auto-posting creates incorrect entries | High | Extensive unit tests per event type; manual review dashboard for Accounting Manager |
| Depreciation scheduled job fails silently | Medium | Log every run; alert on failure; idempotent re-runs |
| Subledger performance degrades | Low | Materialized view or caching; subledgers refreshed nightly, not real-time |

---

## 8.11 Open Questions to Resolve

1. **Full-absorption costing:** Does the business want overhead loaded into inventory/COGS, or period-expense-only? (SRS 19.4) — *Default: period-expense-only for v1; schema supports toggle.*
2. **Internal transfer pricing:** At-cost or transfer price? (SRS Section 18) — *Default: at-cost for v1.*

---

## 8.12 Phase Exit Criteria

- [ ] Every business event from Section 17 produces a balanced journal entry
- [ ] Trial balance is always in balance
- [ ] Chart of Accounts supports hierarchical structure and multi-currency
- [ ] Subledger reports show accurate per-unit financials
- [ ] Overhead expenses allocate correctly per configured rules
- [ ] Depreciation entries calculate and post correctly monthly
- [ ] Asset disposal calculates gain/loss accurately
- [ ] Owner dashboard shows financial KPIs (revenue, COGS, margins)
- [ ] Unit client accounting screens functional in Arabic/RTL
