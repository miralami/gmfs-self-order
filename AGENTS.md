# AGENTS.md — GMFS Self-Order Kiosk

Project rules for any coding agent working in this repository. Read this file, then
`docs/PRD.md`, `docs/BSD.md`, `docs/API.md` and `Handover Self-Order Kiosk Thesis Project.md`
before changing code. `backend/AGENTS.md` contains Laravel Boost's framework guidelines; it is
subordinate to this file on project decisions.

## What this project is

An academic MVP of a central self-order kiosk for the 13-tenant GMFS food court. One cross-tenant
cart, one Midtrans QRIS payment, one order per tenant, isolated tenant back office, operator
settlement. Thesis claims: (G1) tenant isolation, (G2) exactly-once payment effect.

## Layout

- `backend/` — Laravel 13 API, PHP 8.5, `/api/v1`.
- `frontend/` — React 19 + TypeScript + Vite 8 SPA, hash routing.
- `docs/` — PRD, BSD (design), API, EVIDENCE. Keep them true when behavior changes.
- `backend/scripts/break-it.php` — concurrency/replay evidence harness.
- `openlib-scraper/` — unrelated user work. **Never modify or commit it.**

## Commands

```bash
# backend
cd backend
composer install
php artisan migrate:fresh --seed
php artisan serve --port=8000
php artisan test                     # 32 tests / 167 assertions must stay green
php artisan test --filter=PaymentNotificationTest
vendor/bin/pint app config database routes tests
php scripts/break-it.php http://127.0.0.1:8000 50

# frontend
cd frontend
npm install
npm run dev
npm run build                        # tsc -b && vite build must stay clean
npm run lint
```

## Non-negotiable invariants

1. **Money is integer rupiah.** Never floats, never cents. Column names: `price`, `unit_price`,
   `line_total`, `total_amount`, `tenant_share_amount`, `platform_fee_amount`, `amount`.
2. **The server owns prices, totals and tenant identity.** The client sends `product_id` and
   `quantity` only.
3. **Split at checkout.** One `tenant_orders` row per `(checkout_id, tenant_id)` with a unique
   index. Never create child orders inside the notification handler.
4. **Payment state is monotonic.** `paid` is terminal. `markPaid()` must keep its row locks and its
   `already_paid` / `late_payment` guards.
5. **Every payment notification is signature-verified** with
   `SHA512(order_id + status_code + gross_amount + server_key)` and `hash_equals()`.
6. **Duplicate deliveries must be harmless.** Keep the unique indexes on
   `(gateway_order_id, transaction_status, status_code, signature_key)` and `gateway_order_id`.
   The signature is part of the event identity so a forged delivery cannot occupy the slot and
   make the genuine retry look like a duplicate.
7. **Tenant order queries start with `visibleTo($user)`.** No `withoutGlobalScopes()`, no raw
   `DB::table` on tenant tables. Policies repeat the check.
8. **Settlement is 80/20, stored at confirmation**, fee = `intdiv(total * 20, 100)`, share =
   remainder. Never recompute on read.
9. **Exactly one payment adapter: Midtrans.** Do not add or resurrect a mock HTTP adapter. Offline
   demos use `payments:demo-charge` + `payments:simulate`, which go through the real handler.
10. **Secrets stay in `backend/.env`.** Commit `.env.example`. The server key never reaches the SPA.

## Working agreement

- Small vertical slices: one task, tests, `vendor/bin/pint`, one commit.
- Core zones are test-first: tenancy, payment attempts, webhook handling, state transitions, split,
  settlement, authorization. Never delete, skip or weaken a test without the student's explicit
  approval.
- Read the official Midtrans docs before touching payment code; cite the URL in a comment where the
  behavior is not obvious.
- Explain core-zone designs in plain language so the student can defend them to examiners.
- Do not expand scope. New ideas go to `docs/BACKLOG.md`; unresolved facts are questions for the
  student, not guesses.
- If behavior changes, update `docs/API.md` and the relevant table in `docs/PRD.md` in the same
  commit.
- Keep `docs/EVIDENCE.md` honest: a number there must be reproducible by a command in that file.
- Never commit `openlib-scraper/`, `.env`, `vendor/`, `node_modules/`, or build output.

## Open items (ask, do not assume)

Lecturer approval for replacing the mock gateway with Midtrans; the real 13-tenant list; the
Midtrans sandbox keys; the HTTPS tunnel; the UAT schedule; the university AI-use policy; the
BAB II–VI outline.
