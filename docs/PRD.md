# Central Self-Order Kiosk — Product Requirements

Status: implemented MVP, aligned with BAB 1 decision B (Midtrans sandbox replaces the mock gateway).
Last updated: October 30, 2026.

## 1. Problem and scope

GMFS is a food court with 13 independent tenants sharing one seating area and one cashier desk. A
customer who wants food from two stalls must queue twice and pay twice, and the cashier must
reconcile money that belongs to different businesses.

This project builds an academic MVP of a **central self-order kiosk**: the customer builds one cart
across tenants, pays once, and the system splits the payment into one order per tenant. Tenant
managers see only their own orders. The operator sees cross-tenant settlement.

The deliverable is a working prototype plus measured evidence, not a production payment system.

## 2. Actors

| Actor | Authenticated | Can do |
| --- | --- | --- |
| Customer | No | Browse the catalog, build a cross-tenant cart, check out, scan QRIS, follow the receipt |
| Tenant manager | Yes (bearer token, `tenant_id` bound) | List and read only their own tenant orders, move a paid order to the preparation queue |
| Operator | Yes (bearer token, role `operator`) | Read all tenant orders and the cross-tenant settlement summary |
| Midtrans | No (signature-verified) | Deliver HTTP payment notifications |
| Scheduler | No (CLI) | Expire stale checkouts, reconcile attempts that never received a notification |

## 3. Stack

- Backend: Laravel 13 REST API, versioned under `/api/v1`, modular monolith, PHP 8.5.
- Frontend: React 19 + TypeScript + Vite 8 SPA, hash routing, no router dependency.
- Database: PostgreSQL 16+ for real runs and for concurrency evidence. The automated test suite
  defaults to in-memory SQLite for speed and is re-run on PostgreSQL before release; concurrency
  tests must run on PostgreSQL because SQLite serialises writers and would hide the race. PostgreSQL
  17.6 has been used for both (see `EVIDENCE.md` sections 2.1 and 4).
- Payments: **Midtrans Core API, QRIS only**, sandbox environment.
- Deployment target: one affordable VPS; no queue worker is required in the MVP.

## 4. Business rules

1. A cart may contain products from any number of tenants.
2. The server is the only authority for price, line total, grand total and tenant identity. The
   client sends `product_id` and `quantity` only.
3. Money is an integer number of rupiah everywhere: request, response, database column, and
   settlement. No floats, no cents.
4. The checkout is created as a parent row with `status = unpaid`, and the per-tenant child orders
   are created **at checkout time**, also `unpaid`. Splitting is a structural property of the
   checkout, not a side effect of the webhook.
5. A tenant order may move to `queued` only after the payment is confirmed and `paid_at` is set.
   Payment is confirmed only by a signature-valid Midtrans notification or by the reconciliation
   command calling Get Status.
6. Duplicate, replayed, delayed or out-of-order payment events must have at most one effect: one
   paid checkout, exactly one tenant order per tenant, one settlement value per tenant order.
7. A checkout that has passed `expires_at` can never become paid. A payment that arrives after the
   window is recorded as `late_payment` for the audit trail and does not move money.
8. Tenant isolation is enforced server-side: a tenant manager can never read or change another
   tenant's order, even by guessing the numeric ID. The response is `403`.
9. Only the operator can read settlements. Tenant managers receive `403`.
10. Settlement split is **80% tenant / 20% platform**, computed in whole rupiah with
    `intdiv(total × 20, 100)` for the fee and the remainder for the tenant, so the two parts always
    sum exactly to the order total. The split is stored when the payment is confirmed and is never
    recomputed on read.
11. No refunds, no payouts, no tax handling, no delivery fee. The prototype records what each
    tenant is owed; it does not move money to them.
12. The demo payment method is QRIS only.

## 5. State machines

**Checkout** (`checkouts.status`)

```
unpaid ──settlement/capture+accept──> paid      (terminal for money)
   │
   ├──expires_at passed / expire event──> expired
   └──explicit cancellation──────────────> cancelled   (reserved, not exposed in the MVP UI)
```

Transitions are monotonic: once `paid`, a later `settlement` is answered `already_paid` and changes
nothing.

**Payment attempt** (`payment_attempts.status`)

```
pending ──settlement/capture──> settled
   ├──deny────────────────────> denied
   ├──cancel──────────────────> cancelled
   ├──expire──────────────────> expired
   ├──failure─────────────────> failed
   └──replaced by a new attempt> cancelled
```

**Tenant order** (`tenant_orders.status`)

```
unpaid ──payment confirmed──> paid ──tenant action──> queued
   └──checkout expired/cancelled──> cancelled
```

The longer kitchen lifecycle (`preparing`, `ready`, `collected`) from the original handover draft
was deliberately dropped: it adds UI states the thesis does not test. `queued` is the terminal
state in the MVP.

## 6. Data model

Global tables:

- `tenants` — food-court stalls (`name`, `slug`, `is_active`).
- `users` — `role` (`operator` | `tenant_manager`), nullable `tenant_id`, hashed `api_token`.
- `checkouts` — `reference` (UUID, public), `idempotency_key` (unique), `request_hash`,
  `total_amount`, `status`, `expires_at`, `paid_at`.
- `checkout_items` — price/name snapshot per line: `product_id`, `tenant_id`, `outlet_id`,
  `product_name`, `unit_price`, `quantity`, `line_total`.
- `payment_attempts` — one row per QRIS charge: `attempt_no`, `gateway_order_id` (unique),
  `status`, `amount`, `qr_string`, `qr_image_url`, `expires_at`, `settled_at`, raw
  `gateway_response`.
- `payment_notifications` — the audit log: `gateway_order_id`, `transaction_status`,
  `status_code`, `fraud_status`, `signature_key`, `signature_valid`, `outcome`, `payload`,
  `processed_at`, unique on
  `(gateway_order_id, transaction_status, status_code, signature_key)`.

Tenant-owned tables:

- `outlets` — one or more per tenant.
- `products` — publicly readable, owned by one tenant and one outlet; `price` integer rupiah.
- `tenant_orders` — child order: `public_code` (unique, e.g. `GMFS-7K2QX9`), `total_amount`,
  `tenant_share_amount`, `platform_fee_amount`, `status`, `paid_at`, `queued_at`,
  unique on `(checkout_id, tenant_id)`.

The unique `(checkout_id, tenant_id)` index is the structural guarantee that a duplicated
notification cannot split a checkout twice; the row lock in `PaymentService::markPaid()` is the
runtime guarantee that two simultaneous notifications cannot both win.

## 7. API modules

| Module | Endpoints |
| --- | --- |
| Catalog | `GET /catalog/tenants`, `GET /catalog/tenants/{tenant}/products` |
| Cart quote | `POST /cart/quote` |
| Checkout | `POST /checkouts` (requires `Idempotency-Key`), `GET /checkouts/{reference}` |
| Payment | `POST /checkouts/{reference}/payment-attempts`, `GET /checkouts/{reference}/qris`, `POST /payments/midtrans/notification` |
| Tenant orders (auth) | `GET /tenant/orders`, `GET /tenant/orders/{id}`, `POST /tenant/orders/{id}/queue-preparation` |
| Settlement (auth, operator) | `GET /operator/settlements?from=&to=` |

Full request/response examples are in [`API.md`](API.md).

## 8. Acceptance criteria

- [x] A cart mixing tenants produces one checkout with one child order per tenant.
- [x] Child orders are `unpaid` at creation and cannot enter the kitchen queue before payment.
- [x] A signature-valid `settlement` notification marks the checkout `paid` and each child order
      `paid`, storing the 80/20 split.
- [x] Replaying the same notification three times yields one paid state, one notification row, and
      one split.
- [x] An invalid signature changes no state and is stored with `signature_valid = false`.
- [x] A `gross_amount` mismatch is flagged, not paid.
- [x] An expired checkout cannot become paid.
- [x] A lost notification is repaired by `payments:reconcile` through Get Status.
- [x] A duplicate `Idempotency-Key` with a different cart returns `409`.
- [x] The same `Idempotency-Key` with the same cart returns the original checkout and does not
      create a second charge.
- [x] Cross-tenant reads and queue actions return `403`; guests get `401`.
- [x] The settlement report reconciles: `tenant_share + platform_fee = gross` for every row and in
      total.
- [x] A cart mixing three tenants produces three child orders whose subtotals sum to the checkout.
- [x] The 80/20 split is whole rupiah for edge amounts (Rp 1, 7,777, 33,333, 50,001) and never
      loses a rupiah to rounding.
- [x] Seeder creates 13 tenants, one manager per tenant, and one operator.
- [x] Removing a safeguard breaks the test that is supposed to catch it: 8 of 9 mutations killed,
      with the survivor explained in [`EVIDENCE.md`](EVIDENCE.md).
- [x] All of the above is covered by the automated suite (38 tests, 219 assertions).

## 9. Evaluation

Black-box functional testing of the four BAB 1 scenarios (see [`EVIDENCE.md`](EVIDENCE.md)) plus a
UAT session with the real GMFS manager and tenant representatives. The UAT script, the observation
sheet and the mapping from each scenario to a chapter are in `EVIDENCE.md`.

## 10. Non-goals

- Production payment settlement, refunds, chargebacks, payouts to tenants.
- Customer accounts, loyalty, delivery, inventory, printers, kitchen display hardware.
- Schema-per-tenant or database-per-tenant isolation (documented as a rejected alternative).
- Microservices, Kubernetes, message brokers.
- A waiting-line board (dropped from scope to keep the thesis testable).
