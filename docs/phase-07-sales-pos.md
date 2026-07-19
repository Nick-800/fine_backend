# Phase 07: Sales, POS & Credit

> **Duration Estimate:** 4–5 weeks  
> **Team Size:** 2 backend engineers, 1 frontend engineer  
> **Dependencies:** Phase 01 (Foundation), Phase 03 (Inventory), Phase 04–06 (Manufacturing)

---

## 7.1 Objective

Build the unified sales module handling both external client sales (credit-limit gated) and internal unit-to-unit transfers (no credit limit). Includes the POS retail system for Store/Showroom, internal restock requests with unit manager approval, and credit approval escalation workflow. The SalesOrder model serves both external sales and internal transfers via `buyer_type` and `channel` fields.

---

## 7.2 Deliverables

| # | Deliverable | Acceptance Criteria |
|---|-------------|---------------------|
| 1 | Client management | CRUD for external clients with credit_limit and current_balance tracking |
| 2 | Unified SalesOrder model | Same model for external sales and internal transfers; distinguished by `buyer_type` |
| 3 | External sales lifecycle | Draft → CreditCheck → PendingApproval → Confirmed → Fulfilled → Paid/PartiallyPaid |
| 4 | Credit limit enforcement | Auto-check on Draft → CreditCheck; blocks or escalates if exceeded |
| 5 | Credit approval workflow | CreditApprovalRequest routed to Accounting Manager / GM / Owner |
| 6 | Internal unit-to-unit sales | Same order model, skips credit checks entirely |
| 7 | POS retail sales | Fast counter checkout; cash/card/credit payment; channel = pos |
| 8 | Internal restock requests | Store requests stock from manufacturing unit; requires unit manager approval |
| 9 | Invoice generation | Auto-generated from fulfilled sales orders |
| 10 | Unit client: Sales screens | Order creation, credit check status, fulfillment, invoicing |
| 11 | Unit client: POS screen | Fast product search, barcode entry (future), payment, receipt printing |

---

## 7.3 Database Migrations (This Phase)

- `clients`
- `sales_orders`
- `sales_order_lines`
- `credit_approval_requests`
- `pos_sales`
- `pos_sale_lines`
- `internal_restock_requests`
- `internal_restock_request_lines`

---

## 7.4 State Machine: External Sales Order

```
Draft ──[Sales creates order]──► CreditCheck
  • System evaluates: client.current_balance + order_total > client.credit_limit ?

CreditCheck (within limit) ──[Auto-pass]──► Confirmed

CreditCheck (over limit) ──[Escalation]──► PendingApproval
  • Creates CreditApprovalRequest

PendingApproval ──[Manager reviews]──► Confirmed  OR  Rejected
  • If approved: credit_limit override logged against order

Confirmed ──[Goods issued]──► Fulfilled
  • Decrements selling unit inventory
  • Posts AR + revenue journal entries

Fulfilled ──[Payment received]──► Paid  OR  PartiallyPaid
  • Updates client.current_balance
  • Posts cash/AR journal entries
```

### State Machine: Internal Sales Order (Unit-to-Unit)

```
Draft ──[Sales/internal creates order]──► Confirmed
  • Skips CreditCheck entirely

Confirmed ──[Goods issued]──► Fulfilled
  • Decrements seller unit inventory
  • Increments buyer unit inventory
  • Posts internal transfer journal entry

Fulfilled ──[Transfer completes]──► Completed
```

### State Machine: Internal Restock Request

```
Requested ──[Store submits to manufacturing unit]──► PendingApproval

PendingApproval ──[Target unit manager reviews]──► Approved  OR  Rejected

Approved ──[Stock transferred]──► Fulfilled
  • Decrements source unit inventory
  • Increments Store inventory
  • Posts internal transfer journal entry
```

---

## 7.5 Credit Limit Logic

```php
// On SalesOrder creation (Draft → CreditCheck)
$proposed_balance = $client->current_balance + $sales_order->total_amount;

if ($proposed_balance > $client->credit_limit) {
    // Block and create escalation
    $sales_order->status = 'pending_approval';
    CreditApprovalRequest::create([
        'sales_order_id' => $sales_order->id,
        'amount_over_limit' => $proposed_balance - $client->credit_limit,
        'status' => 'pending'
    ]);
} else {
    $sales_order->status = 'confirmed';
}
```

**Important:** No credit limit enforcement between internal units (explicit business rule — SRS Section 18, 22.2).

---

## 7.6 POS Design Decision

**Recommendation:** Reuse the same `SalesOrder` model with `channel = 'pos'` flag.

**Rationale:**
- Accounting and inventory logic is not duplicated
- Reporting is unified (all sales in one table)
- POS-specific fields (payment_method, quick checkout) can live on the same model or a lightweight extension

**POS Sale Flow:**
```
1. Store clerk scans/selects items
2. System decrements Store inventory immediately (POS = immediate fulfillment)
3. Payment collected (cash/card)
4. Sale marked Completed
5. Auto-posts revenue + COGS journal entries
```

---

## 7.7 Internal Transfer Pricing

The data model supports both modes via company-level configuration:

```php
// On company settings
$transfer_pricing_mode = 'at_cost' | 'transfer_price';  // configurable

// At cost (default for v1):
// Internal transfer posts at cost of goods
// No profit recognized between units

// Transfer price (future):
// Internal transfer posts at configured transfer price
// Profit center accounting enabled
```

**Journal Entry (At Cost — v1 Default):**
```
DR  Inventory — Buyer Unit (at cost)
CR  Inventory — Seller Unit (at cost)
```

**Journal Entry (Transfer Price — Future):**
```
DR  Inventory — Buyer Unit (at transfer price)
CR  Inventory — Seller Unit (at cost)
CR  Inter-Unit Profit (difference)
```

---

## 7.8 API Endpoints (This Phase)

### Clients
```
GET    /api/v1/clients
POST   /api/v1/clients
GET    /api/v1/clients/{id}
PUT    /api/v1/clients/{id}
GET    /api/v1/clients/{id}/sales-orders
GET    /api/v1/clients/{id}/balance-history
```

### Sales Orders
```
GET    /api/v1/sales-orders
POST   /api/v1/sales-orders                    ← creates external or internal based on buyer_type
GET    /api/v1/sales-orders/{id}
PUT    /api/v1/sales-orders/{id}
POST   /api/v1/sales-orders/{id}/transition
POST   /api/v1/sales-orders/{id}/submit        → triggers CreditCheck
POST   /api/v1/sales-orders/{id}/fulfill       → Confirmed → Fulfilled
POST   /api/v1/sales-orders/{id}/record-payment → Fulfilled → Paid/PartiallyPaid
GET    /api/v1/sales-orders/{id}/invoice
```

### Credit Approvals
```
GET    /api/v1/credit-approval-requests
PUT    /api/v1/credit-approval-requests/{id}/approve
PUT    /api/v1/credit-approval-requests/{id}/reject
```

### POS
```
POST   /api/v1/pos/sales                       ← quick checkout
GET    /api/v1/pos/sales/{id}
GET    /api/v1/pos/daily-report                ← daily reconciliation
```

### Internal Restock
```
GET    /api/v1/internal-restock-requests
POST   /api/v1/internal-restock-requests
PUT    /api/v1/internal-restock-requests/{id}/approve
PUT    /api/v1/internal-restock-requests/{id}/reject
POST   /api/v1/internal-restock-requests/{id}/fulfill
```

---

## 7.9 Key Business Rules

| Rule ID | Description | Enforcement |
|---------|-------------|-------------|
| SALE-01 | External sales enforce credit_limit per client | Auto-check on submit; block or escalate |
| SALE-02 | Over-limit sales require CreditApprovalRequest | Auto-created; blocks order until approved/rejected |
| SALE-03 | Internal unit-to-unit sales skip credit checks entirely | Conditional: if `buyer_type == 'internal_unit'`, skip CreditCheck state |
| SALE-04 | POS sales reuse SalesOrder model with channel = pos | `channel` enum field; POS-specific UI but same backend |
| SALE-05 | Internal restock requests require target unit manager approval | State machine guard on PendingApproval |
| SALE-06 | Client current_balance updated on every payment | Observer on payment record; recalculates from open invoices |
| SALE-07 | Fulfilled external sale decrements seller inventory and posts AR + revenue | Auto-journal on fulfill transition |
| SALE-08 | Fulfilled internal sale decrements seller, increments buyer, posts transfer JE | Auto-journal on fulfill transition |
| SALE-09 | POS sale is immediately fulfilled (no separate fulfill step) | Status goes directly to Completed |
| SALE-10 | Invoice auto-generated from fulfilled SalesOrder | PDF generation service triggered on fulfill |

---

## 7.10 Unit Client Screens

### Sales (All Units)
1. **Client List:** Search, credit limit indicator, balance
2. **Order Creation:** Select client → add items → submit → see credit check result
3. **Order Pipeline:** Filter by status; approve fulfillments
4. **Invoice View:** PDF preview, download, email (future)

### POS (Store Only)
1. **Checkout Screen:** Product search, quantity, running total
2. **Payment:** Cash received / change due; card reference
3. **Receipt:** Print / digital copy
4. **Daily Close:** Reconciliation of cash drawer vs. system sales

### Internal Restock (Store)
1. **Request Form:** Select target unit, items, quantities
2. **Request Status:** Pending → Approved → Fulfilled

---

## 7.11 Testing Strategy

- **Unit tests:** Credit limit calculation, balance update logic, transfer pricing modes
- **Feature tests:** Full external sale lifecycle with credit escalation; internal sale without credit check; POS checkout
- **Policy tests:** Sales role cannot approve credit overrides; Stores cannot create external sales

---

## 7.12 Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Credit limit calculation race condition | Medium | Optimistic locking on client record; recalculate balance atomically |
| POS performance under counter pressure | High | Minimal API calls; pre-load product catalog; offline product cache OK (read-only) |
| Invoice generation slow | Low | Async queue (Laravel Queues + Redis) for PDF generation |
| Internal transfer pricing decision delayed | Medium | Build for at-cost now; schema supports transfer price toggle |

---

## 7.13 Open Questions to Resolve

1. **POS model:** Confirm reuse of SalesOrder with channel=pos vs. separate lightweight model (SRS Section 13) — *Default: reuse SalesOrder.*
2. **Transfer pricing:** Confirm at-cost vs. transfer price for internal sales (SRS Section 18) — *Default: at-cost for v1.*

---

## 7.14 Phase Exit Criteria

- [ ] External sales enforce credit limits correctly
- [ ] Over-limit sales route to CreditApprovalRequest and unblock on approval
- [ ] Internal sales skip credit checks and post transfer journal entries
- [ ] POS checkout completes sale and decrements inventory in under 2 seconds
- [ ] Internal restock requests require and receive unit manager approval
- [ ] Client balance updates correctly on payments
- [ ] Invoices auto-generate from fulfilled orders
- [ ] Unit client sales and POS screens functional in Arabic/RTL
