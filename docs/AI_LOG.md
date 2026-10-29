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
  `README.md`, `Handover Self-Order Kiosk Thesis Project.md`, root `AGENTS.md`.

**Checks.** `php artisan test` → 32 tests / 167 assertions passing. `vendor/bin/pint` clean.
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
