# Handover: Self-Order Kiosk Thesis Project

Prepared October 30, 2026, revised after reviewing the approved BAB 1. Audience: the student, and a command-line coding agent (DeepSeek V4-Flash with repo access).

## 0. How to use this document

- Suggested setup: this file lives at the repo root as `Handover Self-Order Kiosk Thesis Project.md`;
  the agent rules in Section 13 are mirrored in the root `AGENTS.md`. Sections 1-4 give context,
  Sections 5-9 the technical specification, Sections 10-12 the work plan, evidence plan and thesis
  mapping, Section 14 a continuation prompt, Section 15 the open items.
- Nothing here is assumed silently. Anything marked \[OPEN\] is unknown, and the agent must ask the student instead of guessing. Anything marked \[CONFIRM\] is a proposal that needs the student's approval.
- The Midtrans facts in Section 8 were checked against Midtrans's official documentation on October 30, 2026. The agent must still re-read the official pages before coding.

## 0.5 BAB 1 alignment (read this first)

BAB 1 is approved by the lecturer, so it is the scope contract. Save it in the repo as `docs/BAB1.md`. Anything this document adds beyond BAB 1 is marked \[LECTURER\] and must not be built until the lecturer approves it.

| BAB 1 commitment | How this document treats it | Status |
| --- | --- | --- |
| Payment through a mock adapter only; no real gateway, signature verification or reconciliation (limitation 4 and the background paragraph) | The student chose the Midtrans sandbox (Core API) instead of the mock | \[LECTURER\] approval needed, and BAB 1 must be rewritten to match |
| Black-box testing of critical scenarios, plus UAT with the real GMFS manager and tenant representatives | Section 11 is built around the four BAB 1 scenarios and a UAT plan | In scope |
| No load testing and no advanced security testing (limitation 8) | Concurrency checks stay small and are reported as functional duplicate-submission cases | In scope |
| 80:20 settlement shown as a summary on the operator dashboard; no payout, tax, refund, gateway fees or tenant electricity cost (limitation 5) | Settlement rule in Section 6; refund handling removed | In scope |
| Modular monolith, Laravel REST API, React, PostgreSQL, single VPS (limitations 2, 3 and 6) | Unchanged | In scope |
| R&D with prototyping and user feedback at each iteration | Section 10 is organized as prototype iterations | In scope |
| Case study GMFS with 13 tenants, desktop or tablet browser, no physical kiosk | Seed 13 tenants | In scope |
| BAB 1 cites an initial interview with the manager on \[tanggal\] | The student reports an informal two-way conversation through a contact at GMFS | Student action: describe the source accurately, and confirm the 13 tenants, the 80:20 rule and the manual recap with the manager in a short documented interview |

If the lecturer approves the Midtrans sandbox, ask for limitation 4 and the background sentence about the mock adapter to be rewritten. A draft for that discussion: Integrasi pembayaran menggunakan Midtrans Core API pada lingkungan sandbox tanpa dana riil; penggunaan lingkungan produksi, dana riil, refund, dan penyaluran dana otomatis tidak dilakukan. Also decide whether signature verification, which Midtrans lists as standard notification handling, enters the scope, and whether reconciliation stays out.

If the lecturer does not approve: implement a mock adapter on the same payment interface, with notification fields modeled on Midtrans. Every section stays valid except Section 8 and the tests that depend on Midtrans specifics.

## 1. Goal cascade

Thesis -> Project -> Tasks. Every task must trace to a project requirement, and every requirement to a thesis claim.

- **Thesis.** Working title (adjustable): Perancangan dan Implementasi Sistem Self-Order Kiosk dengan Arsitektur Multi-Tenant serta Penanganan Pembayaran Idempoten. The theme, a self-order website, is fixed and must not change. BAB 1 is written and approved by the lecturer. The chapter outline is BAB I to VI (Section 12).
- **Project.** GMFS Self-Order: a web self-order system for the Gajah Mada Food Street food court in Jakarta Pusat, with 13 tenants. A customer puts items from several tenants into one cart, checks out once and pays centrally. The checkout is split into one order per tenant, and repeated payment notifications are handled idempotently. Tenants have a dashboard to queue paid orders for preparation. The operator has a dashboard with a cross-tenant settlement summary (80% tenant, 20% manager).
- **Focus of this document.** The implementation and evidence side of the thesis, not the thesis prose.
- **Current status (October 31, 2026).** The MVP is built and committed on `main`: Laravel API, React
  SPA, Midtrans QRIS adapter, 13-tenant seeder, 38 tests / 219 assertions green. Sections 6, 7, 9, 10
  and 11 below have been reconciled from "proposal" to "as built"; what is genuinely still open is in
  Section 15. `docs/PRD.md`, `docs/BSD.md`, `docs/API.md`, `docs/DIAGRAMS.md` and `docs/EVIDENCE.md`
  describe the same system and win over this document where they are more specific.
- **Reading order for an agent.** Root `AGENTS.md` → `docs/PRD.md` → `docs/BSD.md` → `docs/API.md` →
  this document. `docs/BAB1.md` does not exist yet; when the approved BAB 1 is saved there, it
  becomes the scope contract described in Section 0.5.

## 2. Student profile and working agreement

- The student is comfortable reading Java and Python, less so Laravel, and knows fundamentals but not framework details. The student is not currently a practicing developer.
- The working mode is agentic engineering, not unreviewed vibe coding. The student must be able to explain and defend every line in the core zones (Section 10) to examiners, who will test the system.
- The agent therefore explains core-zone designs in plain language, works in small steps, and never hides complexity.
- Whether the university allows AI-assisted coding, and whether it must be disclosed, is \[OPEN\]. Regardless, keep an AI-use log (Section 13).

## 3. Decisions

Confirmed by the student:

- The deliverable is a working software product: GMFS Self-Order, a self-order website with centralized multi-tenant architecture and idempotent payment handling.
- Payment uses the Midtrans sandbox (Core API, the student's own payment UI) instead of the mock adapter in BAB 1. This needs lecturer approval \[LECTURER\].
- Stack per BAB 1 and the README: Laravel REST API as a modular monolith, React (with TypeScript and Vite per the README), PostgreSQL, one VPS.
- Evaluation per BAB 1: black-box testing and UAT. The UAT participants are the real GMFS manager and tenant representatives. Examiners will also test the system.
- A command-line agent with repo access does the coding.

Resolved since the first draft:

- `docs/PRD.md` exists and matches the build.
- Versions: PHP 8.5, Laravel 13.36, Node 24.18, Vite 8, React 19; the development OS is Windows 11,
  the target database is PostgreSQL 16+ (SQLite is used only for the fast test suite).
- Docs are written in English; UI copy is Indonesian.
- The demo payment method is QRIS only.
- Split at checkout, 80/20 stored at confirmation, `queued` as the terminal kitchen state.

Open \[OPEN\]: the deadline and next supervisor meeting; the AI-use policy; whether a Midtrans
sandbox account and a public HTTPS tunnel are available; the UAT size, instrument and date; who
gives feedback between prototype iterations; the menu data for the 13 tenants; and lecturer
approval for the scope changes in Section 0.5.

## 4. Product scope (MVP)

Actors: customer (anonymous, at the kiosk), tenant manager (per stall), operator (cross-tenant).

Core flows: browse tenants and products; build one cart across tenants; get a server-side price quote; check out once, which splits into one unpaid order per tenant; pay centrally through Midtrans; a payment notification marks the orders paid; each tenant sees only its own orders and can queue a paid order for preparation (an unpaid order is rejected); the operator views the cross-tenant settlement summary.

Dropped: the waiting-line board is not in BAB 1. Record it in `docs/BACKLOG.md` as future work.

Out of scope per BAB 1: production payments and real funds, automated payout, tax, refund, payment gateway fees, tenant electricity cost, menu recommendation, personalization, paid ads, microservices, Kubernetes and multi-server scaling, load testing, advanced security testing, and physical kiosk devices. Customer accounts, delivery and inventory are also out.

## 5. The two thesis claims

**G1 Tenant isolation.** A tenant user can never read or change another tenant's menu, orders or revenue summary, including through background jobs and webhooks. The operator has explicit cross-tenant access for settlement only. Evidence: a cross-tenant black-box test suite, plus engineering checks that fail when the safeguards are removed.

**G2 Idempotent payment handling.** A repeated payment notification never creates a second paid state, duplicate tenant orders or duplicate settlement records. This is the formal claim in BAB 1. Supporting measures: protection against duplicate checkout submission and against stale or out-of-order notifications. Evidence: black-box test cases with observed record counts.

For the defense: customers shop across tenants, so the catalog and checkout are shared while the tenant back office is isolated. The data model must show this boundary explicitly.

## 6. Architecture (as built)

- Modular monolith, one deployment unit. Modules: Catalog, Tenancy, Cart/Quote, Checkout, Payments,
  Tenant orders, Settlements. The folder layout is in README section 9 and the design detail in
  `docs/BSD.md`.
- Tenancy model: shared database, shared schema, a tenant discriminator column (`tenant_id`).
  Enforcement is **explicit**, not a global scope: every tenant-order query starts with
  `TenantOrder::query()->visibleTo($user)` and `TenantOrderPolicy` repeats the check. A fail-closed
  global scope was rejected because the public kiosk must read the tenant-owned `products` and
  `outlets` tables with no authenticated user; a global scope would either break the catalog or
  force `withoutGlobalScopes()` into public code (ADR-2 in `docs/BSD.md`). PostgreSQL RLS is
  rejected for this prototype: the demo connects as the database owner, which bypasses RLS.
- Tables:
  - Global: `tenants`, `users`, `checkouts`, `checkout_items`, `payment_attempts`,
    `payment_notifications`. There is no separate `idempotency_keys` table: `checkouts.idempotency_key`
    is a unique column, and there is no `settlement_entries` table.
  - Tenant-owned: `outlets`, `products`, `tenant_orders`. Products are publicly readable, writable
    only by the owning tenant (not exposed as an endpoint in the MVP).
- Money: integers in rupiah, never floats, never cents.
- Settlement rule (BAB 1): 80% tenant / 20% manager, whole rupiah, shown only on the operator
  dashboard. **As built:** `platform_fee_amount = intdiv(total_amount * 20, 100)` and
  `tenant_share_amount = total_amount - platform_fee_amount`, stored on `tenant_orders` when the
  payment is confirmed and never recomputed on read. This gives the remainder to the tenant, so at
  Rp 33,333 the split is 26,667 / 6,666. The earlier proposal in this document floored the tenant
  share instead (26,666 / 6,667); the two rules differ by one rupiah. **\[CONFIRM\]** which direction
  the thesis states, because the test suite pins the current one.
- No payout, tax, refund, gateway fee or tenant electricity cost is modeled.
- Server authority: the server computes all prices and totals from product records. The client sends
  `product_id` and `quantity` only; amount, price and `tenant_id` are never accepted from the client.
- Order lifecycle: the checkout is split at creation into one unpaid tenant order per tenant, with a
  unique constraint on `(checkout_id, tenant_id)`. Payment confirmation marks the orders paid and
  stores the split on the same rows, in the same transaction. A tenant can queue only a paid order
  for preparation; queuing an unpaid order is rejected with `422`.
- State machines (Mermaid sources in `docs/DIAGRAMS.md`):
  - Checkout: `unpaid -> paid | expired | cancelled`. `paid` is terminal for money; `cancelled` is
    reserved and has no HTTP route. There is no `failed` or `pending_payment` state.
  - Payment attempt: `pending -> settled | denied | expired | cancelled | failed`. A terminal attempt
    state is never rewritten by a later event.
  - Tenant order: `unpaid -> paid -> queued`, plus `unpaid -> cancelled`. `queued` is terminal in the
    MVP; the longer `preparing/ready/collected` chain is out of scope.

## 7. Multi-tenancy specification (as built)

1. Every tenant-order query starts from `TenantOrder::query()->visibleTo($request->user())`. The
   scope is a query scope, not a global scope; the catalog and cart read `products` and `outlets`
   directly and are intentionally public.
2. The scope fails closed: the operator sees all orders, a tenant manager sees only
   `tenant_id = own`, and any other caller sees `whereRaw('1 = 0')`.
3. Operator access is an explicit branch inside the scope and the policy, not a missing filter.
4. `TenantOrderPolicy::view()` repeats the tenant check for direct reads; `queuePreparation()`
   allows only the owning manager and never the operator. Both are covered by tests.
5. Guessing another tenant's order id returns **`403`**, not `404`, and the test asserts it so the
   behaviour cannot silently regress into a leak. Either is defensible; the thesis should state
   this choice.
6. There are no raw `DB::table` / `DB::select` queries on tenant tables and no
   `withoutGlobalScopes()` in request code. A grep for both is part of the review checklist.
7. There is no queue worker in the MVP. Expiry and reconciliation are scheduled Artisan commands;
   if a worker is added later it must receive IDs and set tenant context explicitly.
8. PostgreSQL RLS is documented as a rejected alternative: the demo connects as the database owner,
   which bypasses RLS, so enabling it would give false confidence without a second role and
   `SET LOCAL` per transaction.

## 8. Midtrans Core API: verified facts

**Status.** The Midtrans Core API adapter is implemented (`backend/app/Services/MidtransGateway.php`)
with QRIS only. Signature verification and the `payments:reconcile` command are implemented too, even
though BAB 1 excluded them; that is part of the scope change awaiting lecturer approval. The mock
adapter was deleted, not kept behind a flag. If the lecturer refuses the change, the fallback is a
mock adapter behind the same `MidtransGateway`-shaped interface with Midtrans-style notification
fields, and the signature/reconciliation tests must be removed with the student's explicit approval.

Docs index for agents: https://docs.midtrans.com/llms.txt. Append `.md` to any docs page URL for markdown. Sandbox API host: https://api.sandbox.midtrans.com.

**Idempotency-Key header** (https://docs.midtrans.com/reference/api-headers):

- Optional. Accepted on all POST requests except /token and /account.
- Maximum length 46; ignored if longer. A UUID is recommended.
- Midtrans saves only successful responses and returns the same response for the same key regardless of the request body.
- The key lives five minutes. After that the request is processed again, and a second charge with the same order\_id may fail with a conflict.
- The key is global across endpoints, so use a new key for each different operation on the same transaction.
- A retry while the first request is still processing gets HTTP 202.
- Not supported for Permata Virtual Account, CIMB Clicks, KlikBCA and Indomaret.
- Consequence for the thesis: Midtrans's idempotency is a short retry cushion, not a durable guarantee. The system needs its own durable layer (Section 9).

**Notification handling** (https://docs.midtrans.com/reference/best-practices-to-handle-notification):

- Use an HTTPS endpoint on standard port 80 or 443, with no self-signed certificates.
- Handle notifications idempotently. Midtrans says order\_id can be the key for tracking entries.
- Verify `signature_key` on every notification.
- Check status\_code 200, fraud\_status accept, and transaction\_status settlement or capture before treating a payment as successful.
- Notifications can be delayed or arrive out of order (for example settlement before pending). Ignore stale ones and confirm with the Get Status API.
- Typical delivery: settlement up to 20 seconds, expiry up to 90 seconds. If a notification is late, call Get Status.
- Respond fast: the timeout is 15 seconds, so aim for under 5. A 2xx means no retry; 500 retries once; 503 four times; 400 or 404 twice; other failures five times. At most five retries, at about 2 min, 10 min, 30 min, 1.5 h and 3.5 h.
- Parse the JSON non-strictly, because new fields appear over time.

**Sandbox testing.** Notifications need a public HTTPS URL, so use a tunnel (ngrok or cloudflared). The notification URL is set in the Midtrans dashboard, and the official PHP library can also override it per transaction.

**To confirm in the docs at implementation time:** the exact `signature_key` formula, the charge request fields and response shape for each chosen payment method, the custom expiry fields, the sandbox test credentials, and the Cancel, Expire, Refund and Get Status APIs.

## 9. Idempotency and payment design

Four layers, each independently durable. All four are implemented.

1. **Client intent key.** `frontend/src/pages/Kiosk.tsx` generates one UUID per cart signature, keeps
   it in `localStorage` under `gmfs:intent:<cart>` so reloads reuse it, and sends it as
   `Idempotency-Key` on `POST /checkouts`. A genuinely new cart gets a new key. A disabled button is
   a UX nicety, not protection.
2. **Checkout creation.** `checkouts.idempotency_key` is unique. The service looks the key up first;
   a replay with the same `request_hash` returns `200` with the original checkout, a replay with a
   different cart throws `IdempotencyConflictException` → `409`, and a missing header is `422`. If two
   requests race, the unique index keeps one and the loser re-reads the winner's row.
   `UniqueViolation::matches()` checks SQLSTATE `23505` (PostgreSQL) and `23000` (SQLite)
   specifically, never every `QueryException`. The same transaction creates the unpaid tenant
   orders, with a unique `(checkout_id, tenant_id)`.
3. **Payment attempts.** One row per attempt, unique `(checkout_id, attempt_no)` and unique
   `gateway_order_id = GMFS-<reference-without-dashes>-<attempt>`. The row is inserted **before**
   the charge. A retry after a failure cancels the previous pending attempt and creates attempt
   `n+1`; the checkout is not recreated. Midtrans's own `Idempotency-Key` header is deliberately not
   used: it lives five minutes and is ignored above 46 characters, so it is a retry cushion, not a
   durable guarantee (ADR-6). A crash between insert and charge leaves a `pending` attempt that
   `payments:reconcile` resolves through Get Status.
4. **Notification handling.** Every delivery is signature-verified; `payment_notifications` is unique
   on `(gateway_order_id, transaction_status, status_code, signature_key)`, so a byte-identical
   replay is stored once and answered `duplicate`. The signature is part of the identity on purpose:
   a forged delivery with a bad signature cannot occupy the slot and make the genuine Midtrans retry
   look like a duplicate. The state change runs in one transaction under row locks on the attempt and
   the checkout; child orders are updated, not created. Respond `200` for every judged delivery
   except an invalid signature (`403`). Reconciliation synthesises the signature the gateway would
   have sent, so repeated runs dedupe normally.

Rules that follow from an earlier review of a weaker design, and how the code satisfies them:

- The amount is computed by the server. A notification whose `gross_amount` differs from
  `payment_attempts.amount` is flagged `amount_mismatch` and the checkout stays `unpaid`.
- A late payment on a stale attempt: the first confirmed payment wins. A later duplicate is recorded
  as `already_paid`, and a payment after the window as `late_payment`; both are audited and left for
  manual handling, because refunds are out of scope (BAB 1, limitation 5).
- `payment_notifications` stores the payload, validity and outcome for every delivery; every attempt
  carries a `failure_reason`. That is the audit trail used in BAB IV.

## 10. Work plan (phases, status, definition of done)

Core zones (the student reviews every line, tests first): tenant context and scope, payment attempts,
webhook handling, state transitions, order splitting, settlements, authorization. Lightly reviewed
zones: kiosk screens, styling, seed data, dashboards.

- **P0 Intake — done.** The repo audit, gap list and ADRs are recorded in `docs/BSD.md` and
  `docs/AI_LOG.md`.
- **Iteration 1, foundation prototype — done.** Laravel API, roles, schema, 13-tenant seed, explicit
  tenant scope and the isolation tests (`TenantIsolationTest`).
- **Iteration 2, cart and checkout prototype — done.** Catalog endpoints, server-side quote, checkout
  creation with the client intent key, split into unpaid tenant orders (`CheckoutFlowTest`).
- **Iteration 3, payment prototype — done in code; a live charge is blocked.** Midtrans sandbox
  adapter, payment attempts, notification handling, paid transition and stored split
  (`PaymentNotificationTest`). A live charge needs sandbox keys and a CA bundle; a live webhook needs
  a public HTTPS tunnel.
- **Iteration 4, dashboards prototype — done.** Tenant order list and queue-for-preparation action,
  operator settlement summary (`SettlementTest`).
- **Iteration 5, evidence — done, except UAT.** The test matrix, the recorded demo run, the black-box
  table (BB-01 to BB-14) and the harness are in `docs/EVIDENCE.md`. `scripts/mutation-check.php`
  reports 8 of 9 mutations killed and explains the survivor. The whole suite also passes on
  PostgreSQL 17.6 (section 2.1 there), and `scripts/break-it.php` was run against four app processes
  sharing one PostgreSQL database at N = 50 and N = 100: one checkout, one paid state, one
  notification row, one stored split, zero server errors. A single-process control run is recorded
  to show the four-process run is genuinely parallel.
- **UAT — not started.** Needs the manager, tenant representatives, a room and a date.
- **Freeze — done for documentation.** README, diagrams, run instructions and limitations are
  written. BAB IV/V material is collected, but the chapters are not drafted.

After each iteration the build was demoed and recorded; feedback from the supervisor and the GMFS
contact has **not** been collected yet, so `docs/FEEDBACK.md` does not exist. BAB 1 promises feedback
at every iteration; who gives it is \[OPEN\].

Every task: one session, one small vertical slice, one commit, tests run, one entry added to
`docs/AI_LOG.md`.

## 11. Test and evidence plan

Report BAB V results as black-box test-case tables built around the four critical scenarios named in BAB 1. The engineering checks support them but are not reported as load testing or security testing, which BAB 1 excludes.

**A. Critical scenarios from BAB 1**

1. **Split-order accuracy (T-SPL).** (a) A cart with items from three tenants creates exactly three unpaid tenant orders with the right items and subtotals. (b) The subtotals add up to the checkout total. (c) A failure midway rolls everything back. (d) Resubmitting the same checkout creates no extra orders.
2. **Tenant data isolation (T-TEN).** (a) A manager of tenant A cannot list, read or update tenant B's menu or orders, and guessing IDs returns 403 (implemented; tests assert 403). (b) A queue job, console command or webhook handler with no logged-in user does not return other tenants' data. (c) Customers cannot read tenant order lists. (d) Only the operator can read cross-tenant settlement.
3. **Preparation refused before payment is confirmed (T-PRE).** (a) Queuing an unpaid order is rejected. (b) After the payment confirmation the same action succeeds. (c) Queuing another tenant's order returns 403.
4. **Repeated payment notifications (T-PAY).** (a) The same notification delivered three times produces one paid transition and no duplicate settlement entries. (b) An out-of-order notification (settlement before pending) ends in the correct state. (c) A gross\_amount mismatch is flagged, not paid. (d) An expired checkout cannot become paid. (e) An invalid signature is rejected \[LECTURER: only if signature verification enters the scope\].

**B. Settlement (T-SET)**

1. Each paid tenant order produces one 80:20 entry in whole rupiah, and the two parts add up to the order total for edge amounts such as Rp 1, Rp 7777 and Rp 50001.
2. Unpaid orders produce no entry.

**C. Supporting engineering checks (not reported as load or security tests)**

- 10 to 20 parallel identical checkout submissions with the same key produce one checkout. Keep N small so this stays a functional duplicate-submission case.
- The same key with a different request body is rejected.
- Mutation checks: remove each unique constraint or guard in turn and confirm that its test fails.
- Crash recovery between the payment attempt and the charge, only if a reconciliation job is approved.

**Recorded status (October 31, 2026).** `php artisan test` → **38 tests / 219 assertions passing** on
SQLite and again on PostgreSQL 17.6. `scripts/mutation-check.php` → **8 of 9 mutations killed**; the
survivor (removing only the unique `(checkout_id, tenant_id)` index) is explained below.

| Scenario | Test class / command | Status |
| --- | --- | --- |
| T-SPL split accuracy and idempotent resubmission | `CheckoutFlowTest` | done |
| T-TEN isolation (list, read, queue, guest, settlement) | `TenantIsolationTest` | done |
| T-PRE queuing blocked before payment | `CheckoutFlowTest` | done |
| T-PAY replay, amount, expiry, signature, reconciliation | `PaymentNotificationTest` | done |
| T-SET 80/20 stored split, edge amounts, unpaid excluded | `SettlementTest` | done |
| Mutation checks M1–M8 | `php scripts/mutation-check.php` | 8 killed, 1 survivor |
| Same suite on PostgreSQL 17.6 | `DB_CONNECTION=pgsql php artisan test` | 38/219 pass |
| Parallel duplicate submissions, PostgreSQL, 4 processes | `php scripts/break-it.php <urls> 50` and `100` | **done, PASS** |
| Parallel duplicate submissions, single process | `php scripts/break-it.php <url> 100` | control run, PASS but serialised |
| UAT | `docs/EVIDENCE.md` section 7 | **not started** |

The mutation survivor is deliberate evidence, not a gap: dropping the unique
`(checkout_id, tenant_id)` index alone does not break the replay test, because the unique
notification event and the monotonic `paid` state catch the duplicate first. Removing all three in
M2b does break the test. That is the layered defence the thesis claims.

**D. UAT plan**

Participants: the real GMFS manager and tenant representatives (number \[OPEN\]). Instrument \[OPEN\]: a scenario-based task list plus a Likert questionnaire on usefulness, ease of use and fit with their workflow. The System Usability Scale is a common choice, but pick what your lecturer expects. Record the date, participants, tasks, scores and comments, with consent and anonymized names.

**Evidence format for BAB V.** Test-case table columns: ID, scenario, preconditions, input, expected result, actual result, status. Keep the scripts in the repo so examiners can re-run them with one command. State the limitations honestly: sandbox only, one database, one VPS, no load or advanced security testing. A filled table with 14 rows (BB-01 to BB-14) is in `docs/EVIDENCE.md` section 3.5.

## 12. Thesis mapping (implementation side)

The outline is set by BAB 1 (section I.6):

- **BAB II Kajian Pustaka:** self-service technology, self-order kiosk, multi-tenant architecture, split order, QRIS and payment gateway, payment idempotency, software testing.
- **BAB III Metodologi Penelitian:** R&D with prototyping; requirements analysis (PRD, use cases, business rules including the 80:20 split); architecture and database design (ERD showing the global versus tenant-owned boundary, the three state machines, sequence diagrams for checkout, payment and notification, and the ADRs on tenancy model, idempotency layers and payment integration); interface design; evaluation method (black-box testing and UAT).
- **BAB IV Hasil Penelitian:** implementation of the backend, frontend, split-order mechanism, payment flow and operator dashboard, with key code excerpts for the core zones.
- **BAB V Validasi Hasil dan Diskusi:** black-box results (Section 11), UAT results, and limitations: sandbox only, one VPS, no load or advanced security testing, plus the five-minute limit of Midtrans idempotency.
- **BAB VI Kesimpulan dan Saran:** conclusions and future work, including menu recommendation.

## 13. Agent rules (copy into AGENTS.md)

- Read root `AGENTS.md`, `docs/PRD.md`, `docs/BSD.md`, `docs/API.md` and this document first. The
  intake phase is over and the MVP exists, so change it in small vertical slices instead of
  re-planning it from scratch.
- The approved BAB 1 is the scope contract. Save it as `docs/BAB1.md` when it is available; anything
  this document adds beyond it is marked \[LECTURER\] and is not built without the student's
  approval. Do not add load testing, advanced security testing, payout, refund, tax or
  recommendation features.
- Never assume. When something is \[OPEN\] or ambiguous, ask the student.
- Work in small vertical slices: one task per session, one commit per task. Run the tests before every commit.
- In core zones write the tests first. Never delete, skip or weaken a test or assertion without the student's explicit approval.
- The server is the authority for prices, totals and tenant identity. Never trust the client for them.
- Money is an integer in rupiah. The 80:20 settlement is computed in whole rupiah and shown only on the operator dashboard.
- Fail closed on any missing tenant context.
- No raw SQL on tenant tables without flagging it for review.
- Secrets live in `.env` only. Commit `.env.example`. The Midtrans server key never reaches the frontend.
- For Midtrans, read the official docs first (https://docs.midtrans.com/llms.txt; add `.md` to a page URL). Do not rely on memory. Cite the doc URL in a comment where behavior is non-obvious.
- Explain each core-zone design in plain language so the student can defend it.
- Do not expand scope. Put new ideas in `docs/BACKLOG.md`.
- After each task append to `docs/AI_LOG.md`: what changed, why it was accepted, which checks passed, and the remaining risks.
- Use relative paths in docs and scripts.

## 14. Kickoff prompt (paste into the CLI agent)

Read root `AGENTS.md`, `docs/PRD.md`, `docs/BSD.md`, `docs/API.md`, `docs/EVIDENCE.md` and this document completely, then read the repository. The MVP is already built; do not rebuild it. First: (1) run `cd backend && php artisan test` and `cd frontend && npm run build && npm run lint`, and report the actual numbers; (2) list every contradiction between the documents and the code; (3) confirm which Section 15 items are still open and ask only those questions, one group at a time; (4) re-read the official Midtrans pages before touching payment code; (5) propose the next vertical slice with its tests and the evidence it will produce. Then implement it under the rules in `AGENTS.md`.

## 15. Open items for the student

Answer these before the next iteration:

1. Save the approved BAB 1 as `docs/BAB1.md`, and fill its placeholders truthfully (the interview date and source, the UAT participants). Describe the GMFS source accurately, and confirm the 13 tenants, the 80:20 rule and the manual recap with the manager in a short documented interview.
2. Ask the lecturer to approve replacing the mock adapter with the Midtrans sandbox, to rewrite limitation 4 and the background sentence, and to decide whether signature verification and reconciliation are in scope. Confirm that QRIS-only is acceptable for the demo.
3. Confirm the rounding direction. The code floors the 20% fee and gives the remainder to the tenant (33,333 → 26,667 / 6,666). The earlier draft floored the 80% tenant share (33,333 → 26,666 / 6,667). Both sum exactly; pick one and the test suite will pin it.
4. UAT: the number of participants, the instrument and the date. Also who gives feedback between prototype iterations, and when.
5. The university policy on AI-assisted coding and disclosure.
6. The deadline and the next supervisor meeting.
7. Midtrans sandbox account status, and whether you can run an HTTPS tunnel.
8. Menu and price data for the 13 GMFS tenants, for seeding.
9. Confirm the retained tenant-order states (`unpaid`, `paid`, `queued`, `cancelled`, with `queued` terminal) and that the longer kitchen lifecycle stays out of scope.

## Sources

- Midtrans, API Headers and Idempotency. https://docs.midtrans.com/reference/api-headers
- Midtrans, Best Practices to Handle Notification. https://docs.midtrans.com/reference/best-practices-to-handle-notification
- Midtrans docs index for agents. https://docs.midtrans.com/llms.txt
- Midtrans PHP library (notification URL override). https://github.com/Midtrans/midtrans-php/blob/master/README.md
- Snap redirect versus Core API overview. https://www.lib.rs/crates/midtransclient
- Webhook tunnel guidance for local development. https://django-subscription-midtrans.readthedocs.io/en/latest/webhooks.html
- Multi-tenancy isolation models. https://codemia.io/archive/multi-tenancy-database-isolation
- PostgreSQL RLS pitfalls. https://www.bytebase.com/blog/postgres-row-level-security-footguns/
- PostgreSQL row security documentation. https://www.postgresql.org/docs/19/ddl-rowsecurity.html
- Laravel queue multi-tenancy trap. https://dev.to/sharjeelz/the-laravel-queue-multi-tenancy-trap-that-cost-me-3-hours-3c3d
- Laravel createOrFirst pull request. https://github.com/laravel/framework/pull/47973
- Idempotency in payment gateways. https://zackius.substack.com/p/67a
- Exactly-once money movement. https://dev.to/dannstorm/exactly-once-money-movement-surviving-retries-and-duplicate-webhooks-3laa
- Vibe Coding in Practice, grey literature review. https://arxiv.org/html/2510.00328v1
- DeepSeek-V4-Flash specifications. https://www.cometapi.com/en/models/deepseek/deepseek-v4-flash
