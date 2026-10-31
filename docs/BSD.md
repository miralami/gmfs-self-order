# Business and System Design (BSD)

This document is the design source for **BAB III**. It states what the system does, how the data is
shaped, which state machines exist, and which alternatives were rejected and why. It matches the
code in `/backend`; if they disagree, the code is the bug — fix one of them deliberately.

Renderable Mermaid sources (ERD, state machines, sequences, deployment) are in
[`DIAGRAMS.md`](DIAGRAMS.md).

## 1. Actors and use cases

| Actor | Use case | Trigger | Result |
| --- | --- | --- | --- |
| Customer | Browse catalog | Opens the kiosk | 13 tenants, outlets, active products |
| Customer | Quote cart | Adds/changes items | Server-computed totals per line and per tenant |
| Customer | Create checkout | Presses *Bayar dengan QRIS* | One checkout, one `unpaid` child order per tenant, one QRIS attempt |
| Customer | Pay | Scans the QR with a QRIS app | Signed notification → `paid`, split stored |
| Customer | Follow receipt | Waits on the payment page | Polling flips the page to the receipt with `GMFS-*` codes |
| Customer | Retry payment | Presses *Buat QR baru* | Previous attempt cancelled, attempt `n+1` created |
| Tenant manager | List own orders | Pastes token, loads | Only own tenant orders |
| Tenant manager | Queue order | Presses *Masukkan antrean dapur* | `paid` → `queued` |
| Operator | Read settlement | Loads with date filter | Per-tenant gross, share, fee, totals |
| Midtrans | Notify payment | QRIS settled/expired/denied | Audited delivery + state transition |
| Scheduler | Expire / reconcile | Every 1 / 2 minutes | Stale checkouts closed, lost notifications repaired |

### Use-case boundaries

```
                 ┌──────────────── shared / public ────────────────┐
 Customer ──────►│ catalog · quote · checkout · QRIS · receipt     │
                 └───────────────┬─────────────────────────────────┘
                                 │ creates
                                 ▼
                 ┌──────────── global tables ─────────────┐
                 │ checkouts · checkout_items ·           │
                 │ payment_attempts · payment_notifications│
                 └───────────────┬────────────────────────┘
                                 │ splits into (unique checkout+tenant)
                                 ▼
                 ┌──────────── tenant-owned ──────────────┐
 Tenant manager ►│ tenant_orders (tenant_id)               │
                 │ outlets · products                      │
                 └───────────────┬────────────────────────┘
                                 │ aggregates paid rows
                                 ▼
 Operator ──────► settlement summary (read-only, all tenants)
```

The boundary is the point of the thesis: shared commerce on top, isolated tenancy at the order
level, cross-tenant aggregation only for the operator.

## 2. Data model

### 2.1 Tables

**`tenants`** — id, name, slug (unique), is_active, timestamps.

**`outlets`** — id, tenant_id → tenants, name, is_active, timestamps.
Index `(tenant_id, is_active)`.

**`products`** — id, tenant_id, outlet_id, name, `price` unsigned int (rupiah), is_active,
timestamps. Index `(tenant_id, is_active)`.

**`users`** — id, tenant_id (nullable), name, email (unique), password, role
(`operator` | `tenant_manager`), `api_token` (sha256 of the bearer token), timestamps.
Tenant managers must have a `tenant_id`; the operator must not.

**`checkouts`** — id, `reference` uuid unique, `idempotency_key` varchar(100) **unique**,
`request_hash` char(64), `total_amount` unsigned int, `status`
(`unpaid` | `paid` | `expired` | `cancelled`), `expires_at`, `paid_at`, timestamps. Index on
`status`.

**`checkout_items`** — id, checkout_id, tenant_id, outlet_id, product_id, `product_name` snapshot,
`unit_price`, `quantity`, `line_total`, timestamps. Index `(checkout_id, tenant_id)`.
Quantity, price and name are snapshotted so a later catalog edit cannot rewrite history.

**`tenant_orders`** — id, checkout_id, tenant_id, outlet_id, `public_code` varchar(20) unique,
`total_amount`, `tenant_share_amount` nullable, `platform_fee_amount` nullable, `status`
(`unpaid` | `paid` | `queued` | `cancelled`), `paid_at`, `queued_at`, timestamps.
**Unique `(checkout_id, tenant_id)`** — the structural anti-double-split guarantee.
Index `(tenant_id, status)`.

**`payment_attempts`** — id, checkout_id, `attempt_no`, gateway (`midtrans`), payment_method
(`qris`), `gateway_order_id` **unique**, status
(`pending` | `settled` | `denied` | `expired` | `cancelled` | `failed`), `amount`,
`transaction_id`, `qr_string`, `qr_image_url`, `gateway_response` json, `failure_reason`,
`expires_at`, `settled_at`, timestamps. Unique `(checkout_id, attempt_no)`, index
`(status, expires_at)`.

**`payment_notifications`** — id, payment_attempt_id nullable, `gateway_order_id`,
`transaction_status`, `status_code`, `fraud_status`, `signature_key`, `signature_valid` bool,
`outcome`, `payload` json, `processed_at`, timestamps.
**Unique `(gateway_order_id, transaction_status, status_code, signature_key)`** — the replay
guarantee. The signature is part of the identity so a forged delivery cannot block the genuine
retry.

### 2.2 Relationships

```
tenants 1──n outlets 1──n products
tenants 1──n users
checkouts 1──n checkout_items
checkouts 1──n tenant_orders        (unique checkout_id+tenant_id)
checkouts 1──n payment_attempts     (unique checkout_id+attempt_no)
payment_attempts 1──n payment_notifications
tenants 1──n tenant_orders
```

No foreign key crosses the isolation boundary in a way that lets a tenant read another tenant's
data: everything a manager touches enters through `tenant_orders.tenant_id`.

## 3. State machines

### 3.1 Checkout

```
            settlement|capture (fraud accept)
unpaid ────────────────────────────────────────► paid
  │
  ├── expires_at reached / gateway expire ─────► expired
  └── explicit cancellation (reserved) ────────► cancelled
```

- `paid` is terminal for money. A later settlement → `already_paid`, no writes to child orders.
- A settlement after `expires_at` → `late_payment`, attempt cancelled, checkout unchanged.
- `cancelled` is reserved and has no HTTP route in the MVP; documented rather than hidden.

### 3.2 Payment attempt

```
pending ──settlement|capture──► settled
   │
   ├── deny ──────────────────► denied
   ├── cancel ────────────────► cancelled   (also: replaced by a newer attempt,
   │                                          duplicate-after-paid, late payment)
   ├── expire ────────────────► expired
   └── failure ───────────────► failed      (gateway rejected the charge)
```

Every transition is written with a reason in `failure_reason`, which is what makes the audit trail
readable in BAB IV.

### 3.3 Tenant order

```
unpaid ──payment confirmed──► paid ──owning tenant queues──► queued
   │
   └── checkout expired/cancelled ──────────────────────────► cancelled
```

`queued` is terminal in the MVP. The longer `preparing → ready → collected` chain was removed by
decision D3 so the thesis tests every state it draws.

## 4. Sequence flows

### 4.1 Checkout with split and charge

```
Kiosk            API                         DB                    Midtrans
  │  POST /checkouts + Idempotency-Key
  ├──────────────►│
  │               │ SELECT checkout by key
  │               │  ├─ found, same hash → return 200 (replayed)
  │               │  └─ found, diff hash → 409
  │               │ BEGIN
  │               │  insert checkout (unpaid, total, expires_at)
  │               │  insert checkout_items (price snapshots)
  │               │  insert tenant_orders (unpaid, unique checkout+tenant)
  │               │ COMMIT
  │               │ insert payment_attempt (pending, unique order id)
  │               ├──────────────────────────────────────────► POST /v2/charge
  │               │◄────────────────────────────────────────── qr_string, expiry
  │               │ update attempt (qr_string, image url, expires_at)
  │◄──────────────┤ 201 {checkout, tenant_orders[], payment{qr_string}}
```

If the charge fails, the checkout and child orders remain `unpaid`, the attempt is `failed`, and
`meta.payment_error` explains it. The kiosk can retry without creating a new checkout.

### 4.2 Payment notification

```
Midtrans         API                              DB
  │ POST /payments/midtrans/notification
  ├───────────────►│
  │                │ verify SHA512(order_id+status_code+gross_amount+key)
  │                │ BEGIN
  │                │   insert payment_notifications (unique replay guard)
  │                │   IF invalid signature → outcome=invalid_signature, 403
  │                │   IF amount != attempt.amount → amount_mismatch
  │                │   IF settlement|accept:
  │                │     lock attempt, lock checkout
  │                │     IF checkout paid → already_paid
  │                │     IF expired → late_payment
  │                │     ELSE attempt=settled, checkout=paid
  │                │          for each unpaid child:
  │                │             fee = intdiv(total*20,100)
  │                │             child = paid, share = total-fee, fee
  │                │ COMMIT
  │◄───────────────┤ 200 {outcome}
```

### 4.3 Reconciliation (lost webhook)

```
scheduler ──► payments:reconcile
                │ select pending attempts older than 2 min on unpaid checkouts
                │ for each: GET /v2/{order_id}/status
                │          apply the response through the same applyNotification(trusted: true)
                └─ result: checked, updated, failed
```

### 4.4 Expiry

```
scheduler ──► checkouts:expire
                │ select unpaid checkouts with expires_at <= now
                │ for each, lock the row:
                │   checkout = expired
                │   unpaid child orders = cancelled
                │   pending attempts = expired
                └─ result: count
```

## 5. Settlement rules

- Scope: `tenant_orders` in `paid` or `queued`, with `paid_at not null`.
- Fee: `intdiv(total_amount * 20, 100)`; tenant share: `total_amount - fee`.
- Computed once, at confirmation, and stored on the row. Reads never recompute.
- Grouped per tenant, with a totals row where `sum(share) + sum(fee) = sum(gross)`.
- Operator-only. Tenant managers get `403` even for their own numbers, because the report is a
  cross-tenant view; a per-tenant report is future work.

Worked example: 70,000 → fee 14,000 / share 56,000. 33,333 → fee 6,666 / share 26,667. No rounding
remainder can be lost because the share is defined as the remainder.

## 6. Architecture decisions (ADRs)

### ADR-1 — Shared database, shared schema, tenant discriminator

**Chosen.** One database, one schema, `tenant_id` on tenant-owned rows.
**Rejected.** Schema-per-tenant and database-per-tenant.
**Why.** Thirteen small stalls on one VPS; migrations must stay cheap; cross-tenant settlement is a
single grouped query. Schema-per-tenant would multiply migration and connection management for no
gain at this scale. The cost is that isolation is enforced by application code, which is exactly
what the test suite and the policy exist to prove.

### ADR-2 — Explicit `visibleTo()` scope + policy instead of a global scope

**Chosen.** `TenantOrder::scopeVisibleTo(User)` and `TenantOrderPolicy`.
**Rejected.** A fail-closed Eloquent global scope on every tenant model.
**Why.** The public kiosk must read `products` and `outlets` with no authenticated user. A global
scope would either break the catalog or push `withoutGlobalScope()` into public code, which is the
bypass the isolation rule forbids. The explicit scope keeps the bypass impossible to write by
accident in the endpoint that matters (`tenant_orders`) and is easy to grep: every order query
starts with `visibleTo(`.

### ADR-3 — Split at checkout, not at payment

**Chosen.** Child orders exist as `unpaid` from the moment the checkout is created.
**Rejected.** Creating child orders inside the notification handler.
**Why.** The split becomes a pure function of the cart, testable without a gateway. The webhook only
flips states, so a duplicate delivery cannot invent a different split. The unique
`(checkout_id, tenant_id)` index makes a second split impossible at the database level.

### ADR-4 — Store the 80/20 split at confirmation

**Chosen.** `tenant_share_amount` and `platform_fee_amount` written once, on confirmation. The fee
is floored (`intdiv(total × 20, 100)`) and the tenant receives the remainder, so the two parts always
sum to the order total; decided October 31, 2026 and pinned by `T-PAY-13`/`T-PAY-14`.
**Rejected.** Computing the split on every settlement read. Also rejected: flooring the tenant share
instead, which shifts the odd rupiah to the operator.
**Why.** A later configuration change must not retroactively change what a tenant was owed. Stored
values also make the totals reconcile trivially and make the report auditable.

### ADR-5 — Midtrans Core API over Snap

**Chosen.** Core API `/v2/charge` with QRIS, our own UI.
**Rejected.** Snap redirect/popup.
**Why.** The thesis is about the payment state machine; owning the charge, the QR display and the
notification handling makes the idempotency work visible and defensible. Snap would hide the part
being examined behind a redirect.

### ADR-6 — Do not rely on Midtrans's `Idempotency-Key`

**Chosen.** Unique `gateway_order_id` plus an attempt row written before the charge.
**Rejected.** Treating the gateway header as the primary guarantee.
**Why.** The gateway keeps the key for five minutes and ignores keys longer than 46 characters; a
retry after five minutes is a new charge. A durable unique key in our own database is the only
guarantee that survives process restarts and later retries.

### ADR-7 — QRIS only, no mock adapter

**Chosen.** One adapter, `MidtransGateway`, QRIS only.
**Rejected.** Keeping the mock adapter behind a flag.
**Why.** BAB 1 decision B. Two adapters double the state machine and invite the demo to use the
easier one. Offline demos use CLI commands that still go through the real signature check and state
machine; they are not an HTTP adapter.

### ADR-8 — Trim the kitchen lifecycle

**Chosen.** `unpaid → paid → queued`, plus `cancelled`.
**Rejected.** `preparing → ready → collected`.
**Why.** Each extra state needs a screen, a transition test and a UAT task. The thesis claim is
about payment idempotency and tenant isolation, not kitchen workflow. Document the trim, do not
pretend it does not exist.

## 7. Non-functional notes

- **Consistency.** Money-changing paths use a transaction and `SELECT … FOR UPDATE`. The split
  happens under the checkout lock. Reads never recompute money.
- **Availability.** No queue worker is required: expiry and reconciliation are scheduled commands
  that can also be run by hand.
- **Security.** Bearer tokens are stored as SHA-256 hashes; the Midtrans server key stays in
  `.env`; the notification endpoint is public but signature-verified; the QR image is proxied so the
  gateway URL is not exposed to the browser.
- **Observability.** Every notification is stored with its payload, validity and outcome. Every
  attempt carries a `failure_reason`. That is the audit trail used in BAB IV.
- **Portability.** SQLite for the fast test suite, PostgreSQL for real runs and for concurrency
  evidence. The unique-violation helper handles both SQLSTATEs.
