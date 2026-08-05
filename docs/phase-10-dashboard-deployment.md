# Phase 10: Owner Dashboard, Reporting & Final Integration

> **Duration Estimate:** 3–4 weeks  
> **Team Size:** 1 backend engineer, 2 frontend engineers (Next.js focus)  
> **Dependencies:** Phase 01–09 (all operational and accounting modules)

---

## 10.1 Objective

Build the Next.js Owner Dashboard providing read/oversight access across all operating units. Deliver company-wide KPIs, financial reporting, approval workflows, cross-unit inventory visibility, and real-time operational dashboards. This phase also covers final integration testing, performance optimization, and production deployment preparation.

### Architecture & API Scope
Owner Dashboard and administrative oversight tools operate against central server live data over direct REST API endpoints, rendering real-time company-wide KPIs, financial reports, and system monitoring metrics.

---

## 10.2 Deliverables

| # | Deliverable | Acceptance Criteria |
|---|-------------|---------------------|
| 1 | Next.js Dashboard scaffold | Auth integration with Laravel API; Owner-only access; RTL support |
| 2 | Company-wide KPI widgets | Revenue, COGS, gross margin, net profit, cash position, FX exposure |
| 3 | Unit profitability dashboard | Per-unit revenue, cost, profit/loss comparison |
| 4 | Inventory rollup view | Cross-unit inventory summary; drill-down to unit level |
| 5 | Approval inbox | Pending credit approvals, restock requests, payroll approvals in one place |
| 6 | Operational pipeline views | Import orders, production batches, work orders, production orders by status |
| 7 | Financial reports | Income statement, balance sheet, trial balance, subledger drill-down |
| 8 | Audit trail viewer | Searchable audit log across all modules; filter by user, date, table |
| 9 | Real-time notifications | WebSocket or polling for approvals, alerts, system events |
| 10 | Performance optimization | Core screens <1s response; dashboard widgets load async; pagination on all lists |
| 11 | Production deployment | VPS setup, SSL, backup strategy, monitoring, CI/CD pipeline |

---

## 10.3 Dashboard Architecture

```
Next.js App (Owner Dashboard)
  ├─ Auth Layer
  │   └─ JWT from Laravel API; same auth/permission model as unit clients
  ├─ Data Layer
  │   └─ All reads from Laravel API; no local database
  │   └─ React Query / SWR for caching and refetching
  ├─ Widget Layer
  │   ├─ KPI Cards (revenue, margin, cash)
  │   ├─ Charts (trend lines, bar comparisons, pie charts)
  │   ├─ Tables (paginated, sortable, filterable)
  │   └─ Maps/Timelines (optional enhancements)
  └─ Notification Layer
      └─ WebSocket or polling for real-time alerts
```

---

## 10.4 Dashboard Widgets Specification

### KPI Cards (Top Row)
| Widget | Data Source | Refresh |
|--------|-------------|---------|
| Total Revenue (MTD) | `SUM(journal_lines.credit WHERE account.type=revenue)` | Hourly |
| Gross Margin % | `(Revenue - COGS) / Revenue` | Hourly |
| Cash Position | `SUM(cash_accounts.balance)` | Real-time |
| FX Exposure | `SUM(import_orders.negotiated_price WHERE status<complete)` | Real-time |
| Pending Approvals | `COUNT(credit_approval_requests WHERE status=pending)` | Real-time |

### Unit Comparison (Middle Row)
| Widget | Data Source |
|--------|-------------|
| Revenue by Unit | Bar chart: Foam / Cutter / Furniture / Store |
| Cost by Unit | Stacked bar: Material / Labor / Overhead |
| Inventory Value by Unit | Pie chart: valuation per unit |

### Operational Pipelines (Bottom Row)
| Widget | Data Source |
|--------|-------------|
| Import Orders Pipeline | Status breakdown: Draft → Complete |
| Foam Batches Pipeline | Status breakdown: Planned → Closed |
| Cutter Work Orders | Status breakdown: Requested → Invoiced |
| Furniture Production | Status breakdown: Requested → Completed |

### Approval Inbox (Sidebar)
- Credit Approval Requests awaiting review
- Internal Restock Requests pending approval
- Payroll Runs pending approval
- One-click approve/reject with comment

---

## 10.5 API Endpoints (Dashboard-Specific)

```
GET    /api/v1/dashboard/kpis
GET    /api/v1/dashboard/unit-comparison
GET    /api/v1/dashboard/inventory-rollup
GET    /api/v1/dashboard/operational-pipeline
GET    /api/v1/dashboard/pending-approvals
GET    /api/v1/dashboard/audit-trail
GET    /api/v1/reports/income-statement
GET    /api/v1/reports/balance-sheet
GET    /api/v1/reports/trial-balance
GET    /api/v1/reports/unit-profitability
```

---

## 10.6 Performance Optimization

| Area | Strategy |
|------|----------|
| Dashboard KPIs | Materialized views in PostgreSQL; refresh every hour via scheduled job |
| Large lists | Server-side pagination (50/100/200 per page); cursor-based for audit logs |
| Charts | Aggregate data server-side; send only summarized points to frontend |
| Real-time updates | WebSocket (Laravel Echo + Pusher/Soketi) or polling every 30s |
| API response time | Eager loading on relationships; select only needed columns; DB indexing |
| Frontend caching | React Query/SWR cache with stale-while-revalidate |

---

## 10.7 Testing & Quality Assurance

### Integration Test Matrix

| Flow | Test |
|------|------|
| Import → Inventory → Foam Batch → Block Sale → Cutter → Furniture → POS | Full material and value chain |
| Credit limit enforcement | Over-limit blocked; approved override works |
| Concurrent editing | Two users edit same record; second gets 409 Conflict |
| Offline connectivity loss | Client shows "not connected"; blocks data entry |
| Payroll → Journal Entry | Posted payroll creates correct ledger entries |
| Asset disposal | Gain/loss calculates correctly |
| Unit provisioning | New unit created with full supporting structure |

### Load Testing
- Simulate 10 concurrent users per unit
- Import order pipeline with 100+ orders
- Inventory with 10,000+ serialized stock lots
- POS: 50 transactions per hour

---

## 10.8 Deployment Checklist

### VPS Infrastructure
- [ ] Ubuntu LTS server provisioned
- [ ] PostgreSQL 15+ installed and hardened
- [ ] PHP 8.2+ with required extensions
- [ ] Nginx reverse proxy configured
- [ ] SSL certificate (Let's Encrypt)
- [ ] Firewall (UFW) configured
- [ ] Redis installed (caching + queues)

### Laravel API
- [ ] Environment variables configured (.env)
- [ ] Database migrations run
- [ ] Seeders executed (demo data)
- [ ] Queue worker running (Supervisor)
- [ ] Scheduler cron job configured
- [ ] Storage permissions set

### Next.js Dashboard
- [ ] Built and exported or running as Node.js app
- [ ] Nginx location block for dashboard routes
- [ ] Environment variables for API base URL

### Unit Web Clients
- [ ] Built and deployed to static hosting or served by Laravel
- [ ] Per-unit access URLs configured
- [ ] Arabic/RTL CSS verified

### Backup & Recovery
- [ ] PostgreSQL daily automated backups (pg_dump)
- [ ] Backup storage off-site (S3-compatible)
- [ ] Restore procedure tested monthly
- [ ] Point-in-time recovery enabled (WAL archiving)

### Monitoring
- [ ] Application error tracking (Sentry or similar)
- [ ] Server monitoring (CPU, memory, disk)
- [ ] Database monitoring (slow queries, connections)
- [ ] Uptime monitoring with alerting
- [ ] Log rotation configured

---

## 10.9 Security Checklist

- [ ] All API endpoints authenticated
- [ ] Authorization enforced server-side (not just UI hiding)
- [ ] SQL injection prevention (Eloquent ORM used exclusively)
- [ ] XSS prevention (input sanitization, output encoding)
- [ ] CSRF protection on web routes
- [ ] Rate limiting on auth endpoints
- [ ] Password policy enforced (min length, complexity)
- [ ] Sensitive data encrypted at rest (if applicable)
- [ ] HTTPS only (HSTS header)
- [ ] Security headers (CSP, X-Frame-Options, etc.)

---

## 10.10 Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Dashboard performance slow with real data | High | Materialized views; async widget loading; caching layers |
| WebSocket infrastructure complex | Medium | Start with polling (30s); upgrade to WebSocket in v1.5 |
| Backup failure unnoticed | Critical | Automated backup verification; test restore monthly; alerting |
| RTL UI bugs | Medium | Thorough QA with Arabic text; test on multiple browsers |
| Data migration from existing system | High | Plan data migration as separate project; start with clean slate |

---

## 10.11 Phase Exit Criteria

- [ ] Dashboard loads all KPI widgets in under 3 seconds
- [ ] Approval inbox allows one-click approve/reject
- [ ] All financial reports generate correctly and balance
- [ ] Full end-to-end integration test passes (import to POS sale)
- [ ] Concurrent edit conflict returns 409 as expected
- [ ] Backup and restore tested successfully
- [ ] Security audit checklist complete
- [ ] All unit clients and dashboard functional in Arabic/RTL
- [ ] Production deployment live and accessible
- [ ] Training materials prepared for each unit
