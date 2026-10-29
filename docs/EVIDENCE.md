# Test and Evaluation Evidence

This file is the working evidence log for **BAB IV**. It contains the test matrix, the commands,
the results recorded so far, the mutation checks still to run, and the UAT plan.

Rule: every number in the thesis must be traceable to a command in this file. Do not copy a number
from a previous run without re-running it.

## 1. Environment

| Item | Value |
| --- | --- |
| Date of recorded runs | October 30, 2026 |
| OS | Windows 11 (development) |
| PHP | 8.5 |
| Laravel | 13.36 |
| Node | 24.18 |
| Database (test suite) | SQLite in-memory |
| Database (demo run) | SQLite file, `backend/database/database.sqlite` |
| Database (required for concurrency evidence) | PostgreSQL 16+ (not yet run — see section 5) |
| Payment gateway | Midtrans Core API, sandbox, QRIS |
| Programme | `php artisan test`, `php scripts/break-it.php` |

## 2. Automated suite

Command:

```bash
cd backend
php artisan test
```

Recorded result: **32 tests, 168 assertions, all passed, ~1.4 s**.

| ID | Test | What it proves |
| --- | --- | --- |
| T-CHK-1 | `CheckoutFlowTest::test_checkout_splits_into_one_unpaid_order_per_tenant` | One child order per tenant, all `unpaid`, total correct |
| T-CHK-2 | `::test_same_idempotency_key_returns_the_same_checkout` | Replay returns the original checkout, one row, one attempt |
| T-CHK-3 | `::test_same_key_with_a_different_cart_is_rejected` | `409` on a different cart |
| T-CHK-4 | `::test_checkout_requires_an_idempotency_key` | `422`, no checkout created |
| T-CHK-5 | `::test_a_gateway_failure_leaves_an_unpaid_checkout_that_can_be_retried` | Checkout survives a charge failure; retry makes attempt 2 |
| T-SPL-1 | `::test_preparation_queue_is_blocked_until_payment_is_confirmed` | `422` before payment |
| T-SPL-2 | `::test_paid_order_can_be_queued_for_preparation` | `paid → queued`, second attempt `422` |
| T-PAY-1 | `PaymentNotificationTest::test_a_valid_settlement_notification_pays_the_checkout_and_splits_each_tenant_order` | `paid` + 80/20 stored, fee + share = total |
| T-PAY-2 | `::test_replayed_notifications_produce_one_paid_state_and_one_split` | Three deliveries → one row, one state, same `paid_at` |
| T-PAY-3 | `::test_an_invalid_signature_changes_nothing` | `403`, no state change, audit row with `signature_valid=false` |
| T-PAY-4 | `::test_an_amount_mismatch_is_flagged_instead_of_paid` | `amount_mismatch`, stays `unpaid` |
| T-PAY-5 | `::test_a_checkout_that_has_expired_does_not_become_paid` | `late_payment`, attempt cancelled |
| T-PAY-6 | `::test_a_second_settlement_after_payment_is_recorded_as_already_paid` | `already_paid`, no second split |
| T-PAY-7 | `::test_an_unknown_order_is_recorded_but_not_applied` | `unknown_order`, nothing paid |
| T-PAY-8 | `::test_expiry_command_closes_unpaid_checkouts_and_cancels_their_orders` | Expiry cancels child orders and attempts |
| T-PAY-9 | `::test_reconciliation_repairs_a_lost_notification` | Get Status repairs a lost webhook |
| T-PAY-10 | `::test_a_forged_notification_cannot_block_the_genuine_one` | Bad-signature delivery is stored, genuine one still pays |
| T-PAY-11 | `::test_a_late_expire_event_cannot_close_a_paid_checkout` | Stale attempt expiry cannot flip a paid checkout |
| T-PAY-12 | `::test_a_delayed_expire_for_a_cancelled_attempt_is_ignored` | Terminal attempt states are never rewritten |
| T-TEN-1 | `TenantIsolationTest::test_a_tenant_manager_only_sees_its_own_orders` | List scoping |
| T-TEN-2 | `::test_guessing_another_tenants_order_id_returns_forbidden` | Read and queue both `403` |
| T-TEN-3 | `::test_the_operator_sees_all_tenants_but_cannot_queue_orders` | Operator read-only |
| T-TEN-4 | `::test_guests_cannot_read_tenant_orders` | `401` for guests |
| T-TEN-5 | `::test_only_the_operator_can_read_settlements` | Manager `403`, operator `200` |
| T-TEN-6 | `::test_public_checkout_status_proxies_the_qr_image_instead_of_exposing_the_gateway_url` | No gateway URL leak |
| T-SPL-3 | `SettlementTest::test_the_operator_summary_uses_the_stored_eighty_twenty_split` | 83,333 → 66,667 / 16,666; totals reconcile |
| T-SPL-4 | `::test_unpaid_orders_are_not_settled` | Unpaid excluded |
| T-SPL-5 | `::test_the_summary_can_be_filtered_by_payment_date` | Date filter |
| T-CAT-1 | `CatalogAndSeederTest::test_the_seeder_creates_the_thirteen_gmfs_tenants_and_one_manager_each` | 13 tenants, 13 managers, 1 operator, ≥26 products |
| T-CAT-2 | `::test_the_public_catalog_exposes_prices_in_rupiah_and_no_internal_fields` | Integer prices, no internal fields |
| T-CAT-3 | `::test_quote_rejects_an_unknown_product` | `422` on unknown product |

### 2.1 Manual reproducibility of a single test

```bash
cd backend
php artisan test --filter=test_replayed_notifications_produce_one_paid_state_and_one_split
```

### 2.2 Frontend build evidence

```bash
cd frontend
npm run build
npm run lint
```

Recorded: `tsc -b && vite build` succeeded, 49 modules, `dist/` written (≈262 kB JS, 10.9 kB CSS);
`oxlint` reports no findings.

## 3. CLI demonstration (offline, no tunnel)

This is the path used when the examiner's machine has no sandbox key or public HTTPS URL.
It is **not** a second payment adapter: `payments:simulate` builds a Midtrans-shaped payload, signs
it with the configured server key, and calls `PaymentService::applyNotification()`, the same code the
HTTP endpoint calls.

```bash
cd backend

# 1. create a checkout in the kiosk, copy the reference, then:
php artisan payments:demo-charge <reference>
php artisan payments:simulate <reference>
php artisan payments:simulate <reference>     # must print: duplicate
```

Recorded run (reference `73ec962f-64a3-4e0c-aeaa-0bbd69131670`, total 105,000, two tenants,
fresh database):

```
Demo attempt created (no Midtrans call).
order_id: GMFS-73ec962f64a34e0caeaa0bbd69131670-1
amount: 105000

Notification outcome: paid
| Tenant | Code       | Total | Status |
| 6      | GMFS-YVNE5M | 70000 | paid   |
| 8      | GMFS-5TA8R8 | 35000 | paid   |

Notification outcome: duplicate
```

Tenant view (`Authorization: Bearer ayam-geprek-juara-token`): 1 order, `GMFS-YVNE5M`, status
`paid`, total 70,000, share 56,000, fee 14,000. The other tenant's order is not visible.

Isolation probes recorded in the same run:

```
ayam reads bakso's order          -> 403
ayam queues bakso's order         -> 403
ayam queues its own order         -> status=queued
```

Operator settlement (`Authorization: Bearer operator-token`):

| Tenant | Orders | Gross | Tenant share | Platform fee |
| --- | --- | --- | --- | --- |
| Ayam Geprek Juara | 1 | 70,000 | 56,000 | 14,000 |
| Bakso Pak Kumis | 1 | 35,000 | 28,000 | 7,000 |
| **Total** | **2** | **105,000** | **84,000** | **21,000** |

## 4. Concurrency and replay harness

Command:

```bash
cd backend
# terminal 1 (PostgreSQL recommended; PHP_CLI_SERVER_WORKERS does not work on Windows)
php artisan serve --port=8000

# terminal 2
php scripts/break-it.php http://127.0.0.1:8000 50
```

The harness:

1. fires N identical `POST /checkouts` with one `Idempotency-Key`,
2. counts distinct checkout references and rows,
3. replays the key with a different cart and expects `409`,
4. fires N signed `settlement` notifications at the real HTTP endpoint,
5. asserts one notification row, one paid state, one child order per tenant, and exact split sums.

Recorded run (SQLite, N = 8, October 30, 2026): **PASS**, 14/14 checks.

```
statuses: {"200":7,"201":1}   distinct checkout references: 1
different cart returns 409
outcomes: {"duplicate":7,"paid":1}
one notification row, checkout paid, 2 of 2 child orders paid
split sums back to the checkout total — 84000 + 21000 vs 105000
```

This run is a correctness check, not concurrency evidence: SQLite serialises writers. The thesis
table below must be filled with the PostgreSQL runs.

| Run | Database | N | Distinct checkouts | Paid outcomes | Notification rows | Result | Date |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | SQLite | 8 | 1 | 1 | 1 | PASS | 2026-10-30 |
| 2 | PostgreSQL | 50 | | | | | |
| 3 | PostgreSQL | 100 | | | | | |
| 4 | PostgreSQL, 2 app instances | 100 | | | | | |

## 5. Mutation checks (to run, then record)

The claim "the safeguard is what prevents the bug" is only credible if removing it breaks a test.
For each row: make the change on a scratch branch, run the named test, confirm it fails, revert.

| # | Mutation | Test that must fail |
| --- | --- | --- |
| M1 | Drop the unique index on `checkouts.idempotency_key` and remove the pre-check | `test_same_idempotency_key_returns_the_same_checkout` |
| M2 | Drop unique `(checkout_id, tenant_id)` | `test_replayed_notifications_produce_one_paid_state_and_one_split` |
| M3 | Drop unique `(gateway_order_id, transaction_status, status_code, signature_key)` | same |
| M4 | Remove the signature verification in `applyNotification` | `test_an_invalid_signature_changes_nothing` |
| M5 | Remove `visibleTo()` from `TenantOrderController::index` | `test_a_tenant_manager_only_sees_its_own_orders` |
| M6 | Make `queuePreparation` skip the owner check | `test_guessing_another_tenants_order_id_returns_forbidden` |
| M7 | Remove the `expires_at` check in `markPaid` | `test_a_checkout_that_has_expired_does_not_become_paid` |
| M8 | Recompute the fee with `round()` instead of `intdiv()` | `test_the_operator_summary_uses_the_stored_eighty_twenty_split` |

Suggested command:

```bash
cd backend
php artisan test --filter=<test name>
```

## 6. Mapping to the four BAB 1 black-box scenarios

| BAB 1 scenario | Evidence |
| --- | --- |
| S1 — one cart across tenants, one payment | T-CHK-1, T-SPL-2, CLI demonstration, UAT task 1–2 |
| S2 — payment confirmed before preparation | T-SPL-1, T-PAY-1, UAT task 2–3 |
| S3 — duplicate/retried payment does not duplicate orders | T-PAY-2, T-PAY-6, concurrency harness, UAT task 5 |
| S4 — tenant only sees its own orders; operator sees settlement | T-TEN-1…6, T-SPL-3, UAT task 4–5 |

## 7. UAT plan

### 7.1 Participants

- 1 GMFS manager (operator role).
- 2–3 tenant representatives (tenant manager role), ideally from different stalls.
- 1 facilitator (the student) and 1 observer taking notes.

Do not coach beyond reading the task statement. Record consent and anonymise names in the thesis
(P1, P2, …).

### 7.2 Tasks

| # | Task statement | Success criterion | Instrument |
| --- | --- | --- | --- |
| U1 | "Order one item from two different stalls and pay once." | One receipt with two `GMFS-*` codes | Success, time, errors |
| U2 | "Find the order that belongs to your stall and move it to the kitchen queue." | Only own order visible; status `queued` | Success, time |
| U3 | "Show where the money for your stall goes after a payment." | Reads the 80/20 row correctly | Success, comprehension |
| U4 | "As the manager, find yesterday's total per stall." | Settlement totals read correctly | Success, time |
| U5 | "Pay the same order twice / refresh during payment." | No duplicate order appears | Observed behaviour |

### 7.3 Instrument

Per participant and task: success (Y/N), time on task (s), number of errors, ease score 1–5,
usefulness score 1–5, free comment. Plus one closing question: "What would stop you from using this
in the food court?"

### 7.4 Reporting

- Table of raw results per participant.
- Success rate per task (count and %).
- Mean and range of ease/usefulness; do not report only the mean.
- Qualitative themes from comments, with quotes anonymised.
- Explicitly state the sample size and that it is not statistically generalisable.

### 7.5 Result table (to fill)

| Participant | Role | U1 | U2 | U3 | U4 | U5 | Ease | Useful | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| P1 | Manager | | | | | | | | |
| P2 | Tenant | | | | | | | | |
| P3 | Tenant | | | | | | | | |

## 8. Limitations to state in BAB IV/V

- Sandbox gateway only; no production keys, no real settlement to bank accounts.
- The automated suite runs on SQLite; row-lock behaviour is only truly exercised on PostgreSQL.
- The concurrency harness on a single machine and a single database is not a load test.
- UAT uses a small convenience sample, so results describe these participants, not the population.
- No refund, payout, tax or kitchen-hardware path was tested because none is implemented.
- Windows cannot use `PHP_CLI_SERVER_WORKERS`; true parallel serving needs WSL, Laragon/Apache, or
  a Linux host.
