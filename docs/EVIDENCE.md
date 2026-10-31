# Test and Evaluation Evidence

This file is the working evidence log for **BAB IV**. It contains the test matrix, the commands,
the results recorded so far, the mutation-check results, and the UAT plan.

Rule: every number in the thesis must be traceable to a command in this file. Do not copy a number
from a previous run without re-running it.

## 1. Environment

| Item | Value |
| --- | --- |
| Date of recorded runs | October 30–31, 2026 |
| OS | Windows 11 (development) |
| PHP | 8.5 |
| Laravel | 13.36 |
| Node | 24.18 |
| Database (test suite) | SQLite in-memory (default) and PostgreSQL 17.6 (second run, section 2.1) |
| Database (demo run) | SQLite file, `backend/database/database.sqlite` |
| Database (concurrency evidence) | PostgreSQL 17.6 on port 5433, four `php artisan serve` processes (section 4) |
| Payment gateway | Midtrans Core API, sandbox, QRIS |
| Programme | `php artisan test`, `php scripts/break-it.php`, `php scripts/mutation-check.php` |

## 2. Automated suite

Command:

```bash
cd backend
php artisan test
```

Recorded result: **38 tests, 219 assertions, all passed, ~1.7 s**.

| ID | Test | What it proves |
| --- | --- | --- |
| T-CHK-1 | `CheckoutFlowTest::test_checkout_splits_into_one_unpaid_order_per_tenant` | One child order per tenant, all `unpaid`, total correct |
| T-CHK-2 | `::test_same_idempotency_key_returns_the_same_checkout` | Replay returns the original checkout, one row, one attempt |
| T-CHK-3 | `::test_same_key_with_a_different_cart_is_rejected` | `409` on a different cart |
| T-CHK-4 | `::test_checkout_requires_an_idempotency_key` | `422`, no checkout created |
| T-CHK-5 | `::test_a_gateway_failure_leaves_an_unpaid_checkout_that_can_be_retried` | Checkout survives a charge failure; retry makes attempt 2 |
| T-CHK-6 | `::test_a_three_tenant_cart_creates_three_orders_whose_totals_sum_to_the_checkout` | Three tenants → three orders, subtotals sum to the checkout |
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
| T-PAY-13 | `::test_the_split_floors_the_fee_so_share_and_fee_sum_to_the_total` | 33,333 → fee 6,666 / share 26,667, no rounding drift |
| T-PAY-14 | `::test_tiny_and_odd_amounts_still_split_into_whole_rupiah` | Rp 1, 7,777 and 50,001 all split to whole rupiah |
| T-PAY-15 | `::test_a_late_pending_notification_cannot_unpay_a_settled_checkout` | Out-of-order `pending` after `settlement` changes nothing |
| T-PAY-16 | `::test_a_challenged_capture_does_not_pay_the_checkout` | `fraud_status = challenge` → `awaiting_fraud_review`, unpaid |
| T-PAY-17 | `::test_a_gateway_denial_marks_the_attempt_denied_and_leaves_the_checkout_unpaid` | `deny` is attempt-level, money untouched |
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

### 2.1 The same suite on PostgreSQL 17.6

The default suite runs on SQLite because it is fast. That alone would not prove the schema works on
the database the thesis targets, so the whole suite was re-run against a real PostgreSQL 17.6
instance with no code changes:

```bash
# portable PostgreSQL 17.6, port 5433, trust auth on 127.0.0.1 only
initdb -D <data-dir> -U postgres --auth=trust --encoding=UTF8 --locale=C
pg_ctl -D <data-dir> -o "-p 5433 -c listen_addresses=127.0.0.1" -l pg.log start
createdb -h 127.0.0.1 -p 5433 -U postgres gmfs_test

cd backend
# environment variables win over phpunit.xml, so the suite uses PostgreSQL
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=gmfs_test \
  DB_USERNAME=postgres DB_PASSWORD= php artisan test
```

Recorded result (October 31, 2026): **38 tests, 219 assertions, all passed, ~4.0 s** on PostgreSQL
17.6, after `RefreshDatabase` created 17 tables from the 12 migrations. The suite is therefore not
SQLite-specific: the unique indexes, `json` columns and timestamp handling behave the same on both.

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

## 3.5 Black-box test-case table (BAB V format)

Every row below was exercised against the running API on October 30–31, 2026. `Actual` is what the
endpoint returned, not what the code intends. The test name is the automated regression that keeps
the behaviour fixed.

| ID | Scenario | Preconditions | Input | Expected | Actual | Status |
| --- | --- | --- | --- | --- | --- | --- |
| BB-01 | Cross-tenant cart, one payment | 13 tenants seeded, empty cart | 2 items from 2 tenants, `POST /checkouts` | 1 checkout, 2 child orders, total = sum | checkout 105,000, 2 orders (70,000 + 35,000) | pass |
| BB-02 | Three-tenant cart | 3 tenants with products | 3 items, `POST /checkouts` | 3 child orders, totals sum to checkout | `T-CHK-6`, 193,000 = 100,000 + 60,000 + 33,000 | pass |
| BB-03 | Queue before payment | Unpaid child order exists | `POST /tenant/orders/{id}/queue-preparation` | `422`, no state change | `422` | pass |
| BB-04 | Queue after payment | Paid child order exists | same endpoint | `200`, status `queued` | `queued` | pass |
| BB-05 | Tenant sees only own orders | Two tenants have one paid order each | `GET /tenant/orders` with each manager token | 1 row per token | 1 row each (`GMFS-YVNE5M`, `GMFS-5TA8R8`) | pass |
| BB-06 | Guessed cross-tenant id | Manager A, order of tenant B | `GET /tenant/orders/{B}` | `403` | `403` | pass |
| BB-07 | Operator settlement | Two paid orders | `GET /operator/settlements` | totals reconcile | gross 105,000 = 84,000 + 21,000 | pass |
| BB-08 | Duplicate notification | Pending attempt | same signed body × 3 | one paid state, one row, one split | `paid`, `duplicate`, `duplicate`; 1 row | pass |
| BB-09 | Forged signature | Pending attempt | bad `signature_key` | `403`, no state change | `403`, checkout stays `unpaid` | pass |
| BB-10 | Amount mismatch | Pending attempt | `gross_amount` ≠ attempt amount | flagged, not paid | `amount_mismatch`, `unpaid` | pass |
| BB-11 | Payment after expiry | Checkout past `expires_at` | valid settlement notification | `late_payment`, never paid | `late_payment` | pass |
| BB-12 | Same key, same cart | Checkout already created | repeat `POST /checkouts` | `200` original, no new charge | same reference, 1 attempt | pass |
| BB-13 | Same key, different cart | Checkout already created | different items, same key | `409` | `409` | pass |
| BB-14 | Seeded catalog | Fresh seed | `GET /catalog/tenants` | 13 tenants, integer prices | 13 tenants | pass |

Screenshots or console excerpts go beside this table in BAB V. Rows BB-01, BB-05, BB-06 and BB-07
are also the four tasks the examiner can repeat by hand in the demo script (README section 6).

## 4. Concurrency and replay harness

The harness fires real HTTP requests, not service calls, so the evidence covers routing, middleware,
the unique indexes, the row locks and the state machine together.

### 4.1 Why several server processes

`php artisan serve` uses PHP's built-in web server. On Windows it handles one request at a time
(`PHP_CLI_SERVER_WORKERS` does not work there), so a single port serialises the requests and proves
nothing about concurrency. The harness therefore accepts a comma-separated list of base URLs and
spreads the requests round-robin over several server processes that share one database:

```bash
cd backend
# four terminals, one process each; all point at the same PostgreSQL database
php artisan serve --port=8000
php artisan serve --port=8001
php artisan serve --port=8002
php artisan serve --port=8003

# fifth terminal
php scripts/break-it.php \
  http://127.0.0.1:8000,http://127.0.0.1:8001,http://127.0.0.1:8002,http://127.0.0.1:8003 100
```

The harness:

1. fires N identical `POST /checkouts` with one `Idempotency-Key`,
2. counts distinct checkout references and rows,
3. replays the key with a different cart and expects `409`,
4. fires N signed `settlement` notifications at the real HTTP endpoint,
5. asserts one notification row, one paid state, one child order per tenant, and exact split sums.

### 4.2 Recorded runs

Recorded run (SQLite, N = 8, October 30, 2026): **PASS**, 14/14 checks. This is a correctness check,
not concurrency evidence: SQLite serialises writers.

```
statuses: {"200":7,"201":1}   distinct checkout references: 1
different cart returns 409
outcomes: {"duplicate":7,"paid":1}
one notification row, checkout paid, 2 of 2 child orders paid
split sums back to the checkout total — 84000 + 21000 vs 105000
```

Recorded run (PostgreSQL 17.6, 4 server processes, N = 50, October 31, 2026): **PASS**, 14/14 checks.

```
statuses: {"200":49,"201":1}   wall time: 1199 ms
distinct checkout references: 1
different cart returns 409
outcomes: {"duplicate":49,"paid":1}
one notification row, checkout paid, 2 of 2 child orders paid
split sums back to the checkout total — 84000 + 21000 vs 105000
```

Recorded run (PostgreSQL 17.6, 4 server processes, N = 100, October 31, 2026): **PASS**, 14/14
checks.

```
statuses: {"200":99,"201":1}   wall time: 2173 ms
distinct checkout references: 1
different cart returns 409
outcomes: {"duplicate":99,"paid":1}
one notification row, checkout paid, 2 of 2 child orders paid
split sums back to the checkout total — 84000 + 21000 vs 105000
```

Control run (PostgreSQL 17.6, **1** server process, N = 100, October 31, 2026): **PASS**, but the run
serialises. The wall time is the point:

```
statuses: {"200":99,"201":1}   wall time: 6285 ms
outcomes: {"duplicate":99,"paid":1}
```

Same work, 6.3 s on one process versus 2.2 s on four. The four-process run is therefore genuinely
parallel, and the invariant still holds: 100 simultaneous identical submissions produced exactly one
checkout row, exactly one charge attempt and exactly one paid state with one 80/20 split.

Summary table:

| Run | Database | Processes | N | Distinct checkouts | Paid | Duplicate | Notification rows | Wall time | Result | Date |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | SQLite | 1 | 8 | 1 | 1 | 7 | 1 | — | PASS | 2026-10-30 |
| 2 | PostgreSQL 17.6 | 4 | 50 | 1 | 1 | 49 | 1 | 1,199 ms | PASS | 2026-10-31 |
| 3 | PostgreSQL 17.6 | 4 | 100 | 1 | 1 | 99 | 1 | 2,173 ms | PASS | 2026-10-31 |
| 4 | PostgreSQL 17.6 | 1 | 100 | 1 | 1 | 99 | 1 | 6,285 ms | PASS (serialised control) | 2026-10-31 |

What this supports in the thesis: **G2, exactly-once payment effect**. The duplicate rate is not zero
— 99 of 100 deliveries were judged duplicates — yet the money moved once. The unique
`checkouts.idempotency_key` index and the unique notification event tuple are what decided the race;
the losers did not error, they re-read the winner's row and answered `200`.

## 5. Mutation checks (recorded)

The claim "the safeguard is what prevents the bug" is only credible if removing it breaks a test.
`backend/scripts/mutation-check.php` applies each mutation to the working tree, runs the named test,
records the failure line, and restores the file in a `finally` block. It refuses to start if a
pattern no longer matches, so a stale mutation fails loudly instead of silently doing nothing.

Command:

```bash
cd backend
php scripts/mutation-check.php
# one mutation only: php scripts/mutation-check.php --only=M4
```

Recorded result (October 31, 2026): **8 of 9 mutations killed the test that is supposed to catch
them.** M2 survives by design.

| # | Mutation | Test | Result | Observed failure |
| --- | --- | --- | --- | --- |
| M1 | Remove the idempotency pre-check and the unique `checkouts.idempotency_key` index | `test_same_idempotency_key_returns_the_same_checkout` | **killed** | replay answered `201` instead of `200` |
| M2 | Drop unique `(checkout_id, tenant_id)` | `test_replayed_notifications_produce_one_paid_state_and_one_split` | **survived** | the event index and the paid state catch the replay first |
| M2b | Drop the tenant index, the event index and the `already_paid` guard together | `test_a_second_settlement_after_payment_is_recorded_as_already_paid` | **killed** | outcome became `late_payment`, not `already_paid` |
| M3 | Drop the unique notification event tuple | `test_replayed_notifications_produce_one_paid_state_and_one_split` | **killed** | replay outcome `already_paid` instead of `duplicate` |
| M4 | Accept every signature | `test_an_invalid_signature_changes_nothing` | **killed** | forged delivery answered `200` instead of `403` |
| M5 | Remove `visibleTo()` from the tenant list | `test_a_tenant_manager_only_sees_its_own_orders` | **killed** | manager saw 2 orders instead of 1 |
| M6 | Let `queuePreparation` skip the owner check | `test_guessing_another_tenants_order_id_returns_forbidden` | **killed** | cross-tenant queue reached the status check (`422`), not `403` |
| M7 | Remove the `expires_at` check in `markPaid` | `test_a_checkout_that_has_expired_does_not_become_paid` | **killed** | expired checkout became `paid` |
| M8 | Use `round()` instead of `intdiv()` for the fee | `test_the_split_floors_the_fee_so_share_and_fee_sum_to_the_total` | **killed** | fee became 6,667 instead of 6,666 |

M2 is the honest exception and belongs in BAB V: the unique `(checkout_id, tenant_id)` index is
defence in depth, not the only guard. The notification event index and the monotonic `paid` state
prevent a second split before the tenant index is ever consulted; M2b removes all three and the
suite fails. The index stays because it is the structural guarantee if the handler is ever changed.

M8 previously survived because `SettlementTest` wrote the split in its own fixture helper, so it
tested the read path, not the write path. The fix was to add `T-PAY-13`, which drives a real
notification through `PaymentService`. This is the value of the harness: it found a test that was
passing for the wrong reason.

A mutation that survives is not automatically a missing test. Record it, explain which other layer
caught the fault, and only add a test when the survivor shows that no layer did.

## 6. Mapping to the four BAB 1 black-box scenarios

| BAB 1 scenario | Evidence |
| --- | --- |
| S1 — one cart across tenants, one payment | T-CHK-1, T-SPL-2, CLI demonstration, UAT task 1–2 |
| S2 — payment confirmed before preparation | T-SPL-1, T-PAY-1, UAT task 2–3 |
| S3 — duplicate/retried payment does not duplicate orders | T-PAY-2, T-PAY-6, section 4 PostgreSQL runs (N = 50 and 100 on four processes), UAT task 5 |
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
- The suite runs on both SQLite and PostgreSQL 17.6, but PostgreSQL was exercised on one machine with
  four app processes, not on a cluster; row locking is proven, network partitions are not.
- The concurrency harness is not a load test: four processes and two products are a correctness probe,
  not a capacity measurement.
- UAT uses a small convenience sample, so results describe these participants, not the population.
- No refund, payout, tax or kitchen-hardware path was tested because none is implemented.
- Windows cannot use `PHP_CLI_SERVER_WORKERS`; parallel serving is achieved with four separate
  `php artisan serve` processes on different ports behind the same database.
