# Handover: Self-Order Kiosk Thesis Project

Prepared October 30, 2026. Audience: the student, the supervisor, and any coding agent with repo
access.

This document replaces the earlier draft. Where the earlier draft conflicted with the approved
BAB 1, the BAB 1 version wins and the conflict is called out explicitly in section 3.

## 0. How to use this document

- Sections 1–4 give context and the decisions that are already settled. Sections 5–9 describe the
  system **as built**. Sections 10–13 are the work status, the evidence plan and the thesis
  mapping. Section 14 is the rule block for `AGENTS.md`. Section 15 is what is still open.
- Anything already implemented is written in the present tense and can be verified in the repo.
  Anything still required from the student is in section 15 and marked `[OPEN]`.
- Every claim about Midtrans behavior was checked against the official documentation in October
  2026. The links are in the Sources section. Re-read them before changing payment code.

## 1. Goal cascade

Thesis → project → tasks. Every task must trace to a project requirement, and every requirement to
a thesis claim.

- **Thesis.** *Perancangan dan Implementasi Sistem Self-Order Kiosk dengan Arsitektur Multi-Tenant
  serta Penanganan Pembayaran Idempoten.* The theme — a self-order website — is fixed.
- **Method.** Research and Development (R&D) with the prototyping model: requirements → design →
  build → test → evaluate → revise. Chapters I–VI.
- **Project.** A working MVP for the GMFS food court: one cart across tenants, one payment, one
  order per tenant, isolated tenant back office, operator settlement.
- **Scope of this document.** Implementation, evidence and handover. It does not write the thesis
  prose.

## 2. Student profile and working agreement

- The student reads Java and Python comfortably, Laravel less so, and is not a practising
  developer. Every core-zone design must therefore be explainable in plain language.
- The mode is agentic engineering with review, not unreviewed generation. The student must be able
  to defend every line in the core zones (section 10) when examiners test the system.
- The agent works in small vertical slices, explains non-obvious choices, and never hides a
  limitation.
- The university's AI-disclosure policy is `[OPEN]`. Keep the AI-use log described in section 14
  regardless.

## 3. Decisions (settled)

These are no longer proposals. They are the alignment between BAB 1 and the implementation.

| # | Earlier draft / BAB 1 | Decision now | Consequence |
| --- | --- | --- | --- |
| D1 | BAB 1 described a **mock payment**; the draft proposed Midtrans | **Decision B: Midtrans sandbox replaces the mock gateway.** This is a scope change and needs the lecturer's written approval. | One payment adapter only. The mock controller, model, service, request and `payment_events` table were deleted, not kept as dead code. |
| D2 | Split the checkout when payment is confirmed | **Split at checkout.** Parent checkout plus one `unpaid` child order per tenant are created in the same transaction. | The split is structural, not a webhook side effect. A duplicate notification cannot create a second split because `(checkout_id, tenant_id)` is unique. |
| D3 | Tenant order lifecycle `paid → queued → preparing → ready → collected` | **Trimmed to `unpaid | paid | queued | cancelled`.** | Fewer untested UI states. `queued` is terminal in the MVP. Document the trim in BAB 1/III as a scope decision, not an omission. |
| D4 | Settlement unclear | **80% tenant / 20% platform, operator-only, whole-rupiah, stored at payment confirmation.** | Fee = `intdiv(total × 20, 100)`, tenant share = remainder. The two always sum to the total. No rounding drift. |
| D5 | Refunds and payouts implied | **No refunds, no payouts, no tax.** | A late payment is recorded as `late_payment` and handled manually in the Midtrans dashboard. |
| D6 | Evaluation method unspecified | **Black-box functional testing of four BAB 1 scenarios + UAT** with the real GMFS manager and tenant representatives. | The evidence plan in section 11 and `docs/EVIDENCE.md` follows this. |
| D7 | Waiting-line board as a bonus | **Dropped.** | Scope stays testable. |
| D8 | 3 tenants in the seed | **13 tenants seeded** (the real GMFS count), demo and tests focus on 3. | The catalog looks like the real food court; the demo stays short. Replace placeholder names with the interview list when available. |
| D9 | Demo payment methods unspecified | **QRIS only.** | Core API `payment_type: qris`, `acquirer: gopay`. Fewer branches to defend. |

Still `[OPEN]`: lecturer approval for D1, the real tenant list, the Midtrans sandbox account, and
the public HTTPS tunnel.

## 4. Product scope (MVP)

Actors: customer (anonymous kiosk), tenant manager (one per stall), operator (cross-tenant),
Midtrans (notification sender), scheduler (CLI).

Core flows:

1. Browse the catalog of 13 tenants and their active products.
2. Build one cart across tenants; the server quotes every line.
3. Create a checkout with a client-generated `Idempotency-Key`.
4. The backend splits it into `unpaid` child orders and opens one QRIS charge.
5. The customer scans the QR; Midtrans sends a signed notification; the checkout becomes `paid`
   and the 80/20 split is stored.
6. The tenant dashboard lists only that tenant's paid orders; the manager queues them.
7. The operator dashboard shows the cross-tenant settlement report.

Out of scope: production settlement, refunds, customer accounts, delivery, inventory, kitchen
hardware, schema-per-tenant isolation, microservices, waiting-line board.

## 5. The two thesis claims

**G1 — Tenant isolation.** A tenant manager can never read or change another tenant's orders,
products or settlements, including by guessing IDs.

Evidence: `TenantIsolationTest` — manager lists, direct read, queue action, operator read-only
access, guest `401`, settlement `403`. Mutation check: remove the policy or the `visibleTo` scope
and the suite must fail.

**G2 — Exactly-once effect of payment.** Under double taps, retries, timeouts and duplicate,
delayed or out-of-order notifications, a checkout produces at most one paid state, exactly one
tenant order per tenant, and one settlement value per tenant order.

Evidence: `PaymentNotificationTest` (replay, invalid signature, amount mismatch, late payment,
already-paid, unknown order, reconciliation), `CheckoutFlowTest` (same key, different cart, gateway
failure, replay), plus the PostgreSQL concurrency harness `backend/scripts/break-it.php`.

For the defence: customers shop across tenants, so catalog and checkout are shared; the back office
is isolated. The ERD must show that boundary: `checkouts` and `checkout_items` are global,
`tenant_orders` carries `tenant_id` and is the isolation boundary.

## 6. Architecture as built

Modular monolith, one deployment unit. Modules map to folders, not to services.

| Module | Code |
| --- | --- |
| Catalog | `CatalogController`, `Tenant`, `Outlet`, `Product` |
| Cart/quote | `CartController`, `CartService` |
| Checkout + split | `CheckoutController`, `CheckoutService`, `Checkout`, `CheckoutItem`, `TenantOrder` |
| Payments | `PaymentService`, `MidtransGateway`, `PaymentNotificationController`, `PaymentAttempt`, `PaymentNotification` |
| Tenant back office | `TenantOrderController`, `TenantOrderPolicy` |
| Settlement | `SettlementController`, `SettlementService` |
| Maintenance | `checkouts:expire`, `payments:reconcile` |

Design rules that hold in code:

- **Server authority.** The client sends `product_id` and `quantity`. Price, line total, grand
  total and `tenant_id` come from the database.
- **Money is integer rupiah.** `products.price`, `checkouts.total_amount`,
  `checkout_items.unit_price`/`line_total`, `tenant_orders.total_amount`,
  `tenant_share_amount`, `platform_fee_amount`, `payment_attempts.amount`. No floats, no cents.
- **Snapshots.** `checkout_items` stores the product name and unit price at checkout time, so a
  later catalog edit cannot rewrite a paid order.
- **Monotonic state.** A paid checkout never un-pays. `markPaid()` locks the attempt and the
  checkout row with `SELECT … FOR UPDATE`, refuses `already_paid` and `late_payment`, and only then
  updates the child orders.

### Why not a global tenant scope

The earlier draft proposed a fail-closed Eloquent global scope. The implementation uses an explicit
`TenantOrder::scopeVisibleTo(User)` plus `TenantOrderPolicy` instead, for one concrete reason: the
catalog, the cart quote and the checkout must read tenant-owned tables (`products`, `outlets`)
without an authenticated user. A global scope would either break the public kiosk or force
`withoutGlobalScope()` calls into the kiosk path — exactly the bypass the draft warned about.

The chosen rule is narrower and testable: **no endpoint builds a `TenantOrder` query without
`visibleTo()`**, the policy checks the same tenant id, and an unknown role sees `whereRaw('1 = 0')`.
This is documented as an ADR in the thesis, with the global-scope alternative and its failure mode.

## 7. Multi-tenancy specification

1. Every tenant-owned order query starts from `TenantOrder::query()->visibleTo($request->user())`.
2. The scope fails closed: operator sees all, tenant manager sees `tenant_id = own`, anything else
   sees nothing.
3. `TenantOrderPolicy::view()` repeats the check for direct reads; `queuePreparation()` allows only
   the owning manager, never the operator.
4. Guessing another tenant's order id returns `403`, not `404`. Either is defensible; the test suite
   asserts `403` so it cannot silently regress to a leak.
5. Guests get `401` from the `api.token` middleware before any query runs.
6. There are no raw `DB::table` queries on tenant tables and no `withoutGlobalScopes()` calls.
7. No queue worker exists in the MVP. If one is added later, it must receive IDs and re-resolve the
   tenant explicitly.
8. PostgreSQL row-level security is documented as a rejected alternative: the demo connects as the
   database owner, which bypasses RLS, so RLS would give false confidence.

## 8. Midtrans Core API: verified facts and how the code uses them

Docs index for agents: <https://docs.midtrans.com/llms.txt>. Append `.md` to a docs page for
markdown. Sandbox host: `https://api.sandbox.midtrans.com`.

**Charge.** `POST /v2/charge`, HTTP Basic auth with the **server key as username** and an empty
password. Body used:

```json
{
  "payment_type": "qris",
  "transaction_details": { "order_id": "GMFS-<uuid>-<attempt>", "gross_amount": 105000 },
  "qris": { "acquirer": "gopay" }
}
```

Response fields used: `transaction_id`, `qr_string`, `expiry_time`, `actions[]` (look for
`generate-qr-code`), `transaction_status`, `status_code`, `status_message`.

**Get Status.** `GET /v2/{order_id}/status`, same auth. Used by `payments:reconcile` when a
notification never arrived.

**Expire.** `POST /v2/{order_id}/expire`. Used best-effort when replacing a pending attempt; a
failure is swallowed because the first confirmed payment still wins.

**Notification signature.** `SHA512(order_id + status_code + gross_amount + server_key)`. Verified
with `hash_equals()` on every delivery. `gross_amount` is the string Midtrans sent (for example
`"105000.00"`), not a reformatted number.

**Notification handling rules applied:**

- HTTPS endpoint, public, no authentication — authenticity is the signature.
- Parse permissively (`$request->all()`), because Midtrans adds fields over time.
- Store every delivery before deciding, including invalid ones (`signature_valid = false`,
  `outcome = invalid_signature`).
- Treat `settlement` and `capture` with `fraud_status = accept` as paid.
- Compare `gross_amount` to the attempt amount; mismatch is flagged, never paid.
- Respond `200` for every outcome except an invalid signature (`403`), so Midtrans does not retry a
  judged delivery.
- Reconciliation covers lost webhooks; expiry covers abandoned checkouts.

**Idempotency-Key header — deliberate non-use.** Midtrans's own `Idempotency-Key` is a five-minute
retry cushion and is ignored if longer than 46 characters. The application does not rely on it.
Instead, `payment_attempts.gateway_order_id` is unique and the attempt row is inserted **before**
the charge, so a retried or crashed charge can never create a second logical payment. This is the
durable layer the thesis discusses; the header is mentioned in BAB II as a gateway feature whose
guarantee is too short to be the primary mechanism.

**Sandbox testing.** Notifications need a public HTTPS URL. Use ngrok or cloudflared, set the
notification URL in the Midtrans dashboard, and keep the tunnel alive during the demo.

## 9. Idempotency and payment design as built

Four durable layers, each independently sufficient to stop a duplicate effect:

1. **Client intent key.** `Kiosk.tsx` generates one UUID per cart signature, stores it in
   `localStorage` under `gmfs:intent:<cart>`, and reuses it across reloads. A new cart gets a new
   key. The key is sent as `Idempotency-Key` on `POST /checkouts`.
2. **Checkout creation.** `checkouts.idempotency_key` is unique. The service first looks the key up;
   a replay with the same `request_hash` returns the original checkout, a replay with a different
   cart throws `IdempotencyConflictException` → `409`. If two requests race, the unique index wins
   and the loser re-reads the winner's row. `UniqueViolation::matches()` checks SQLSTATE `23505`
   (PostgreSQL) and `23000` (SQLite) specifically, never every `QueryException`.
3. **Payment attempts.** One row per attempt, unique `(checkout_id, attempt_no)` and unique
   `gateway_order_id = GMFS-<reference-without-dashes>-<attempt>`. The pending attempt is cancelled
   before a new one is created. A crash between insert and charge leaves a `pending` attempt that
   `payments:reconcile` resolves through Get Status.
4. **Notification handling.** `payment_notifications` is unique on
   `(gateway_order_id, transaction_status, status_code, signature_key)`, so a byte-identical replay
   is stored once
   and answered `duplicate`. The state change runs inside one transaction that also splits the
   tenant orders; `(checkout_id, tenant_id)` is unique, so a second split is impossible even if the
   row lock were removed.

Amount safety: the notification `gross_amount` must equal `payment_attempts.amount`, otherwise the
attempt is flagged `amount_mismatch` and the checkout stays `unpaid`. A client-supplied amount is
never read.

Late payment: if the checkout is `paid`, the attempt is cancelled with `Duplicate payment after the
checkout was already paid` and the outcome is `already_paid`. If the window closed, the outcome is
`late_payment`. Both are visible in the audit table, which is what BAB IV shows.

Expiry: `checkouts:expire` runs every minute, and `PaymentService::markPaid()` also checks
`expires_at` under the row lock, so a notification that races the scheduler still cannot pay an
expired checkout.

## 10. Work status

Core zones (the student reviews every line): tenant scoping and policy, payment attempts, webhook
handling, state transitions, order splitting, settlement, authorization.
Lightly reviewed zones: kiosk UI, styling, seed data, dashboards.

| Phase | Scope | Status |
| --- | --- | --- |
| P1 | Schema, roles, seeders, tenant scoping, isolation tests | **Done** |
| P2 | Catalog, quote, checkout with idempotency key | **Done** |
| P3 | Midtrans QRIS charge, attempts, QR display | **Done in code**; blocked on sandbox key + tunnel for a live charge |
| P4 | Webhooks, state machine, reconciliation, expiry, split | **Done** |
| P5 | Tenant dashboard, queue action, operator settlement | **Done** |
| P6 | Break-it suite and evidence tables | **Harness written**; must be run on PostgreSQL and recorded |
| P7 | README, limitations, freeze | **Done** |
| P8 | UAT with the GMFS manager and tenant representatives | **Not started**, needs the student to schedule it |
| P9 | Thesis chapters II–VI and the defence deck | **Not started** |

Definition of done for a task: one small slice, tests run, `vendor/bin/pint` clean, one commit,
one entry in `docs/AI_LOG.md`.

## 11. Test and evidence plan

Naming: T-TEN (tenancy), T-CHK (checkout idempotency), T-PAY (payment and webhook), T-SPL (order
split). The automated suite currently runs **32 tests / 168 assertions, all passing** on SQLite.
`docs/EVIDENCE.md` maps every item below to a test name, a command and a result table.

**T-TEN**

1. Manager A cannot list, read or queue tenant B's order. → `TenantIsolationTest`
2. Guessing B's order id returns `403` for read and for queue. → `TenantIsolationTest`
3. Guests get `401`; only the operator reads settlements. → `TenantIsolationTest`
4. The operator sees all tenants but cannot queue. → `TenantIsolationTest`
5. Mutation check: break the policy/scope and watch the suite fail. → manual, recorded in evidence

**T-CHK**

1. Same key + same cart returns the original checkout and creates no second charge.
   → `CheckoutFlowTest`
2. Same key + different cart returns `409`. → `CheckoutFlowTest`
3. Missing key returns `422`. → `CheckoutFlowTest`
4. 50–100 parallel identical requests produce one checkout. → `scripts/break-it.php`, PostgreSQL

**T-PAY**

1. A valid settlement pays the checkout and stores the 80/20 split. → `PaymentNotificationTest`
2. Replay three times → one notification row, one paid state, one split.
   → `PaymentNotificationTest`
3. Invalid signature → `403`, no state change, audit row with `signature_valid = false`.
4. `gross_amount` mismatch → `amount_mismatch`, checkout stays `unpaid`.
5. Expired checkout → `late_payment`, never paid.
6. Second settlement after payment → `already_paid`.
7. Unknown order → recorded, not applied.
8. Lost notification repaired by `payments:reconcile` via Get Status.
9. Expiry command cancels child orders and pending attempts.
10. Checkout can be retried after a gateway failure, creating attempt 2, not a new checkout.
11. Mutation check: drop a unique index and watch its test fail. → manual

**T-SPL**

1. One child order per `(checkout, tenant)`; sum of child totals equals the checkout total.
   → `CheckoutFlowTest`, `SettlementTest`
2. `tenant_share + platform_fee = total` for every row and in the totals. → `SettlementTest`

**Evidence format for BAB IV.** For every test: setup, command, expected result, observed counts,
number of trials, pass/fail, and a screenshot or console excerpt. Keep scripts in the repo so the
examiners can re-run them. State honestly: sandbox only, single database, no production hardening.

## 12. Evaluation

Black-box functional testing plus UAT, per D6.

- **Black-box.** The examiner drives the running kiosk: two tenants in one cart, one payment,
  receipt shows two `GMFS-*` codes, tenant dashboard shows only its own order, operator settlement
  reconciles. The four BAB 1 scenarios map 1:1 to the test groups in section 11.
- **UAT.** With the real GMFS manager and 2–3 tenant representatives. Task list: place a mixed
  order, scan QRIS, find the order on the tenant screen, queue it, read the settlement row.
  Instrument: task success, time on task, 1–5 usefulness/ ease scores, and open comments. Record
  the session; do not coach the participant beyond the task statement.
- **Result reporting.** A table per scenario: participant, task, success, time, score, note.
  Summarise with counts and means; do not over-claim from a small sample. Say "the prototype was
  usable for these tasks by these participants", not "the system is usable".

## 13. Thesis mapping (implementation side)

- **BAB I — Introduction.** Problem: repeated queues and payments in a 13-tenant food court.
  Objectives: one cross-tenant cart and payment; isolated tenant back office; idempotent payment.
  Scope limits: Midtrans sandbox, QRIS only, no refunds/payouts, 13 tenants with 3 in the demo.
  Method: R&D prototyping.
- **BAB II — Literature.** Multi-tenancy isolation models (shared schema vs schema-per-tenant),
  idempotency and exactly-once effects, payment gateway notification patterns, Midtrans Core API,
  Laravel/Eloquent transaction and locking behavior, UAT.
- **BAB III — Analysis and design.** Actors and use cases, ERD with the global/tenant-owned
  boundary, the three state machines, sequence diagrams for checkout, charge, notification,
  reconciliation, settlement; ADRs: shared schema, explicit scope vs global scope, split at
  checkout, 80/20 stored split, Core API over Snap, no Midtrans Idempotency-Key reliance.
- **BAB IV — Implementation and testing.** Module structure, core-zone code excerpts (with the
  reasons, not just the code), env/tunnel configuration, test matrix and results, concurrency
  results, UAT results.
- **BAB V — Discussion.** Why shared schema; the cost of trimming the kitchen lifecycle; the
  five-minute limit of gateway idempotency and why the app owns the durable layer; sandbox and
  single-node limitations; threats to validity in the UAT sample.
- **BAB VI — Conclusion.** Answer each BAB I objective with the measured evidence, then list
  future work: real tenant list, production keys, refunds/payouts, RLS, kitchen lifecycle.

## 14. Agent rules (mirrored in `AGENTS.md`)

- Read `docs/PRD.md`, `docs/API.md` and this document before changing code.
- Never assume. Anything `[OPEN]` is a question for the student, not a guess.
- Small vertical slices: one task, one commit, tests before commit.
- Core zones are test-first. Never delete, skip or weaken a test without explicit approval.
- The server owns prices, totals and tenant identity. Never trust the client for them.
- Money is an integer in rupiah.
- Fail closed on missing tenant context; no `withoutGlobalScopes()` in request code.
- No raw SQL on tenant tables without flagging it for review.
- Secrets live in `backend/.env` only; commit `.env.example`. The Midtrans server key never reaches
  the SPA.
- Read the official Midtrans docs before changing payment code, and cite the URL in a comment where
  behavior is non-obvious.
- Explain each core-zone design in plain language so the student can defend it.
- Do not expand scope. New ideas go to `docs/BACKLOG.md`.
- After each task, append to `docs/AI_LOG.md`: what changed, why, checks that passed, remaining
  risk.
- Use relative paths in docs and scripts.

## 15. Open items

1. **Lecturer approval for D1** (Midtrans sandbox replacing the mock gateway). Without it, BAB I
   and BAB III disagree with the code.
2. **The real 13-tenant list** (names, products, prices). The seeder currently uses placeholders in
   the right shape.
3. **Midtrans sandbox account.** Server and client keys, and confirmation that QRIS is enabled for
   the merchant.
4. **A public HTTPS tunnel.** Without it, live notifications cannot be demonstrated; the CLI
   harness is the documented fallback.
5. **UAT schedule and participants.** The manager plus 2–3 tenant representatives, a room, and a
   device with a QRIS reader.
6. **AI-use and disclosure policy** of the university.
7. **Deadline, next supervisor meeting, and the exact BAB II–VI outline** from the faculty.

## Sources

- Midtrans, API Headers and Idempotency. <https://docs.midtrans.com/reference/api-headers>
- Midtrans, Best Practices to Handle Notification.
  <https://docs.midtrans.com/reference/best-practices-to-handle-notification>
- Midtrans Core API, QRIS charge. <https://docs.midtrans.com/reference/core-api>
- Midtrans docs index for agents. <https://docs.midtrans.com/llms.txt>
- Midtrans PHP library. <https://github.com/Midtrans/midtrans-php>
- PostgreSQL row security. <https://www.postgresql.org/docs/current/ddl-rowsecurity.html>
- Laravel database transactions and pessimistic locking.
  <https://laravel.com/docs/13.x/database#pessimistic-locking>
- Laravel `createOrFirst` discussion. <https://github.com/laravel/framework/pull/47973>
- Exactly-once money movement and duplicate webhooks.
  <https://dev.to/dannstorm/exactly-once-money-movement-surviving-retries-and-duplicate-webhooks-3laa>
- Multi-tenancy isolation models. <https://codemia.io/archive/multi-tenancy-database-isolation>
