# AI-assisted work log

One entry per task: what changed, why, checks that passed, remaining risk. This log supports the
thesis's AI-use disclosure. It is not a substitute for the student understanding the core zones.

## 2026-10-30 — Align the implementation with BAB 1 decision B

**Why.** BAB 1 described a mock payment gateway. The agreed decision (D1) is to use the Midtrans
sandbox with QRIS only, split the checkout at checkout time, store an 80/20 settlement, trim the
tenant order lifecycle, and evaluate with black-box tests plus UAT.

**Changed.**

- Removed the mock payment path: `MockPaymentController`, `MockPaymentService`, `PaymentEvent`,
  `MockPaymentEventRequest`, `RetryCheckoutRequest`, the `payment_events` migration and its routes.
- New schema: `checkouts` (reference, unique `idempotency_key`, `request_hash`, total, status,
  expiry, paid_at), `checkout_items` (price snapshots), `tenant_orders` (public code, stored
  share/fee, unique `(checkout_id, tenant_id)`), `payment_attempts` (unique `gateway_order_id`),
  `payment_notifications` (unique gateway event).
- Added `MidtransGateway` (Core API charge/status/expire/signature/QR/expiry, optional CA bundle),
  `PaymentService` (attempt state machine, notification handler, reconciliation, expiry),
  `CheckoutService` (split at checkout + idempotency), `CartService`, `SettlementService`,
  `TenantOrderPolicy`, `scopeVisibleTo`, API resources, `checkouts:expire`,
  `payments:reconcile`, `payments:demo-charge`, `payments:simulate`.
- Renamed money columns from cents to integer rupiah.
- Seeder now creates 13 tenants, 13 managers and 1 operator with deterministic demo tokens.
- Rebuilt the SPA: cross-tenant kiosk with QRIS display and polling, tenant dashboard, operator
  settlement dashboard, typed API client.
- Added tests: `CheckoutFlowTest`, `PaymentNotificationTest`, `TenantIsolationTest`,
  `SettlementTest`, `CatalogAndSeederTest`, `tests/Concerns/CreatesKiosk.php`.
- Added `backend/scripts/break-it.php` concurrency/replay harness.
- Rewrote the documentation set: `docs/PRD.md`, `docs/BSD.md`, `docs/API.md`, `docs/EVIDENCE.md`,
  `docs/DIAGRAMS.md`, `README.md`, `Handover Self-Order Kiosk Thesis Project.md`, root `AGENTS.md`.

**Checks.** `php artisan test` → 38 tests / 219 assertions passing. `vendor/bin/pint` clean.
`npm run build` clean. `break-it.php` at N = 8 on SQLite → 14/14 checks pass. Manual CLI demo:
checkout 105,000 split into 70,000 + 35,000; settlement 84,000 / 21,000.

**Hardening found while reviewing the payment path.**

- The notification replay key now includes `signature_key`, so a forged delivery cannot occupy the
  unique slot and make the genuine Midtrans retry look like a duplicate (T-PAY-10).
- `markAttempt()` now locks the attempt and refuses to rewrite a terminal state; a delayed `expire`
  for a stale attempt can no longer close a checkout that was already paid (T-PAY-11, T-PAY-12).
- `expireStaleCheckouts()` re-checks the checkout under the row lock and only counts rows it
  actually expired.

**Remaining risk.**

- No sandbox key or tunnel in this environment, so the live charge and live webhook are unverified.
  A charge attempt failed with `cURL error 60`; `MIDTRANS_CA_BUNDLE` support was added for that.
- Concurrency evidence must be re-run on PostgreSQL; SQLite serialises writers.
- Lecturer approval for D1 is still outstanding.
- UAT has not been run.

## 2026-10-31 — Mutation evidence, money edge cases, handover reconciliation

**Why.** The handover was revised against the approved BAB 1 and had drifted back to a
pre-implementation plan. The thesis also needs evidence that the safeguards, not luck, are what make
the tests pass.

**Changed.**

- Added `backend/scripts/mutation-check.php`: patches one safeguard at a time, runs the test that
  should catch it, prints the failure line, and restores the file in a `finally` block. A stale
  patch pattern aborts instead of silently passing.
- Added `T-CHK-6`: a three-tenant cart creates three child orders whose subtotals sum to the
  checkout.
- Added `T-PAY-13` and `T-PAY-14`: the split is whole rupiah at 33,333 and at Rp 1, 7,777 and
  50,001.
- Reconciled the handover with the code: status, explicit `visibleTo()` scope instead of a global
  scope, stored split instead of a settlement table, `403` instead of `404`, no Midtrans
  `Idempotency-Key`, signature inside the replay key, state names, phase status, and open items.
- Recorded the mutation results in `docs/EVIDENCE.md`.

**Checks.** `php artisan test` → 38 tests / 219 assertions passing. `php scripts/mutation-check.php`
→ 8 of 9 mutations killed; M2 survives by design because two other layers catch the duplicate first.

**Finding.** M8 initially survived: `SettlementTest` computed the split in its own fixture, so it
never exercised `PaymentService`. The new `T-PAY-13` drives a real notification and kills M8. This is
recorded in `docs/EVIDENCE.md` as an example of mutation testing finding a test that passed for the
wrong reason.

**Remaining risk.** The rounding direction is still a student decision: the code floors the 20% fee
(33,333 → 26,667 / 6,666); the earlier handover draft floored the 80% tenant share (26,666 / 6,667).
Both sum exactly, but the thesis must state one. UAT is still outstanding.

## 2026-10-31 — PostgreSQL concurrency evidence

**Why.** `G2, exactly-once payment effect` was supported only by a serialised SQLite run. The thesis
evidence table had empty PostgreSQL rows, and the machine had no PostgreSQL at all.

**Changed.**

- `scripts/break-it.php` now accepts a comma-separated list of base URLs and spreads requests
  round-robin over them, because one `php artisan serve` process handles one request at a time on
  Windows and would serialise the run.
- Standalone PostgreSQL 17.6 (portable binaries, port 5433, `trust` auth on `127.0.0.1` only) and
  `pdo_pgsql` enabled in `php.ini`; no system service, no admin rights, nothing installed into the
  repository.
- The whole suite was re-run on PostgreSQL: **38 tests / 219 assertions pass**, 17 tables created
  from 12 migrations.
- Harness runs recorded with four server processes on one database: N = 50 and N = 100, plus a
  single-process N = 100 control run.
- `docs/EVIDENCE.md` sections 1, 2.1, 4 and 8, `README.md` section 8, `docs/PRD.md` database
  decision, `AGENTS.md` commands and the handover phase/status tables updated to match.

**Result.**

| Processes | N | Distinct checkouts | Paid | Duplicate | Notification rows | Wall time |
| --- | --- | --- | --- | --- | --- | --- |
| 4 | 50 | 1 | 1 | 49 | 1 | 1,199 ms |
| 4 | 100 | 1 | 1 | 99 | 1 | 2,173 ms |
| 1 | 100 | 1 | 1 | 99 | 1 | 6,285 ms |

**Finding.** The duplicate rate is 99 of 100, yet the money moved once. The wall-time gap (6.3 s on
one process, 2.2 s on four) is the evidence that the four-process run is genuinely parallel, so the
result is a race that the unique indexes resolved, not a serialised queue that never raced.

**Remaining risk.** UAT has not run. The live Midtrans charge and webhook are still unverified
because there is no sandbox key or public HTTPS tunnel.
