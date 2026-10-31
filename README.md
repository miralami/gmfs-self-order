# GMFS Self-Order Kiosk

Perancangan dan Implementasi Sistem Self-Order Kiosk dengan Arsitektur Multi-Tenant serta
Penanganan Pembayaran Idempoten.

An academic MVP of a central self-order kiosk for a 13-tenant food court. A customer puts items
from several stalls into one cart and pays once with QRIS through **Midtrans Core API**. The system
splits the payment into one order per tenant, tenants prepare only their own orders, and the
operator sees an 80/20 settlement report.

- Backend: Laravel 13 REST API (`/backend`), PHP 8.5
- Frontend: React 19 + TypeScript + Vite 8 (`/frontend`)
- Database: PostgreSQL 16+ (SQLite works for a quick local run; concurrency evidence needs
  PostgreSQL)
- Payments: Midtrans Core API, QRIS only, sandbox
- Tests: 35 tests / 201 assertions (`cd backend && php artisan test`)

Docs: [`docs/PRD.md`](docs/PRD.md) · [`docs/BSD.md`](docs/BSD.md) · [`docs/API.md`](docs/API.md) ·
[`docs/DIAGRAMS.md`](docs/DIAGRAMS.md) · [`docs/EVIDENCE.md`](docs/EVIDENCE.md) ·
[`Handover Self-Order Kiosk Thesis Project.md`](Handover%20Self-Order%20Kiosk%20Thesis%20Project.md)

## 1. Prerequisites

- PHP 8.3+ with `pdo_pgsql` (PHP 8.5 was used here) and Composer 2
- Node.js 20+ and npm (Node 24 was used here)
- PostgreSQL 16+, running locally or reachable
- Optional: Midtrans sandbox account, and a public HTTPS tunnel (ngrok or cloudflared) for live
  webhooks
- Optional: a `cacert.pem` bundle if your local PHP has no `curl.cainfo` configured (Windows
  installs from php.net usually do not). See section 5.

## 2. Backend setup

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
```

Edit `backend/.env`:

```dotenv
APP_NAME=GMFSSelfOrder
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=gmfs_self_order
DB_USERNAME=gmfs
DB_PASSWORD=secret

MIDTRANS_SERVER_KEY=SB-Mid-server-xxxxxxxxxxxxxxxx
MIDTRANS_CLIENT_KEY=SB-Mid-client-xxxxxxxxxxxxxxxx
MIDTRANS_IS_PRODUCTION=false
MIDTRANS_BASE_URL=https://api.sandbox.midtrans.com
MIDTRANS_TIMEOUT=15
# MIDTRANS_CA_BUNDLE=C:\php\extras\ssl\cacert.pem

CHECKOUT_TTL_MINUTES=15
PLATFORM_FEE_PERCENT=20
ORDER_CODE_PREFIX=GMFS
FRONTEND_URLS=http://localhost:5173,http://127.0.0.1:5173
```

Create the database once, then migrate and seed:

```bash
createdb gmfs_self_order          # or: psql -c "CREATE DATABASE gmfs_self_order"
php artisan migrate
php artisan db:seed
```

For a throwaway local run you may skip PostgreSQL and keep `DB_CONNECTION=sqlite`; Laravel creates
`backend/database/database.sqlite` on the first migration.

Serve the API:

```bash
php artisan serve --port=8000
```

### Seeded accounts

The seeder creates 13 tenants, one outlet and 2–3 products per tenant, one operator and 13 tenant
managers. Tokens are deterministic so the demo needs no login flow:

| Account | Bearer token |
| --- | --- |
| Operator | `operator-token` |
| Noodle House manager | `noodle-house-token` |
| Burger Point manager | `burger-point-token` |
| Fresh Bowl manager | `fresh-bowl-token` |
| …any other tenant | `<tenant-slug>-token` |

`GET /api/v1/catalog/tenants` returns the slugs, so the token for any tenant is derivable.
These tokens are demo credentials and must never be used outside the prototype.

## 3. Frontend setup

```bash
cd frontend
npm install
npm run dev
```

Vite serves the SPA on `http://localhost:5173` and calls `http://localhost:8000/api/v1` by default.
Override with a `.env.local` file:

```dotenv
VITE_API_BASE_URL=http://localhost:8000/api/v1
```

Routes are hash-based, so no server rewrite is needed:

- `/#/` — customer kiosk: catalog, cross-tenant cart, QRIS payment, receipt
- `/#/tenant` — tenant dashboard: paste a manager token, see own orders, queue them
- `/#/operator` — operator dashboard: paste `operator-token`, see the settlement report

`npm run build` type-checks with `tsc -b` and writes `dist/`. Serve `dist/` behind the same origin
as the API in production, or set `FRONTEND_URLS` on the backend.

## 4. Midtrans sandbox

1. Create/log in to a Midtrans sandbox account and copy the **Server Key** and **Client Key** from
   *Settings → Access Keys* into `backend/.env`.
2. In *Settings → Configuration*, set the **Payment Notification URL** to
   `https://<your-tunnel-host>/api/v1/payments/midtrans/notification`.
3. Start the tunnel and keep it running while demoing:

   ```bash
   ngrok http 8000
   # or
   cloudflared tunnel --url http://localhost:8000
   ```

4. Pay a generated QRIS code with any sandbox QRIS simulator. Midtrans delivers the notification to
   the tunnel; the app verifies `signature_key`, marks the checkout paid, and splits it.

If no tunnel is available, the notification cannot reach a local machine. Use the offline demo
harness in section 6 instead — it goes through the same signature check and state machine, and it
does not create a second payment adapter.

Security notes:

- The server key lives only in `backend/.env`. It never reaches the SPA and is never committed.
- Every notification is checked with `SHA512(order_id + status_code + gross_amount + server_key)`.
- The public `GET /checkouts/{reference}` response never contains the raw gateway response or the
  Midtrans QR URL; the QR image is proxied through `/checkouts/{reference}/qris`.

## 5. Windows / local PHP TLS fix

If a charge fails with `cURL error 60: SSL certificate ... unable to get local issuer certificate`,
your PHP has no CA bundle configured. Fix it in one of two ways:

1. Download `https://curl.se/ca/cacert.pem`, then either
   - set `curl.cainfo=C:\path\to\cacert.pem` and `openssl.cafile=C:\path\to\cacert.pem` in
     `php.ini`, or
   - set `MIDTRANS_CA_BUNDLE=C:\path\to\cacert.pem` in `backend/.env`. The gateway passes it to
     Guzzle as the `verify` option, and ignores it when the file does not exist.

The same fix applies to `payments:reconcile`, which calls the Get Status API.

## 6. Demo script (10 minutes)

1. Start the API and the SPA, open `/#/`.
2. Add two products from two different tenants, for example *Ayam Geprek Juara* and
   *Bakso Pak Kumis*. The cart shows one total.
3. Press **Bayar dengan QRIS**. The kiosk creates one checkout (`201`) and shows:
   - one parent checkout with `total_amount`,
   - two child orders, both `unpaid`, each with its own `GMFS-XXXXXX` code,
   - a QR code and a 15-minute countdown.
4. Pay it. Two paths:
   - **With a tunnel and sandbox keys:** scan the QR with a sandbox QRIS app; the page flips to the
     receipt within a few seconds of the notification.
   - **Without a tunnel:** copy the reference from the receipt/URL and run:

     ```bash
     cd backend
     php artisan payments:demo-charge <reference>   # creates a pending attempt, no network call
     php artisan payments:simulate <reference>      # signed Midtrans-shaped notification
     ```

     The kiosk polls every 3 seconds and flips to the receipt on its own. Running
     `payments:simulate` a second time prints `duplicate` and changes nothing — that is the
     idempotency demo.
5. Open `/#/tenant`, paste `ayam-geprek-juara-token`, load orders. The paid order is visible with
   its 80/20 split; the other tenant's order is not. Press **Masukkan antrean dapur** → `queued`.
6. Open `/#/operator`, load settlements with `operator-token`. The table shows gross, tenant share
   and platform fee per tenant, and the totals reconcile.

### CLI commands

| Command | Purpose |
| --- | --- |
| `php artisan checkouts:expire` | Expire unpaid checkouts past `expires_at`, cancel their child orders |
| `php artisan payments:reconcile` | Call Get Status for pending attempts older than 2 minutes |
| `php artisan payments:demo-charge <reference>` | Offline demo: create a pending attempt without calling Midtrans |
| `php artisan payments:simulate <reference> [--outcome=settlement\|expire\|deny\|cancel]` | Send a signed, Midtrans-shaped notification through the real handler |

Both scheduled commands are registered in `routes/console.php` (`everyMinute` and
`everyTwoMinutes`). Run `php artisan schedule:work` if you want them ticking locally.

## 7. API summary

Base URL: `/api/v1`. Full examples: [`docs/API.md`](docs/API.md).

| Method | Path | Auth | Notes |
| --- | --- | --- | --- |
| GET | `/catalog/tenants` | — | 13 tenants with outlets and active products |
| GET | `/catalog/tenants/{tenant}/products` | — | Products of one tenant |
| POST | `/cart/quote` | — | Server-side price quote |
| POST | `/checkouts` | — | Requires `Idempotency-Key`; returns the split child orders |
| GET | `/checkouts/{reference}` | — | Status, child orders, QR payload |
| POST | `/checkouts/{reference}/payment-attempts` | — | New QRIS attempt (retry) |
| GET | `/checkouts/{reference}/qris` | — | QR image proxy |
| POST | `/payments/midtrans/notification` | signature | Midtrans webhook |
| GET | `/tenant/orders` | bearer | Scoped to the caller's tenant |
| GET | `/tenant/orders/{id}` | bearer | `403` for another tenant |
| POST | `/tenant/orders/{id}/queue-preparation` | bearer | `paid` → `queued` only |
| GET | `/operator/settlements` | bearer, operator | `from`/`to` date filters |

## 8. Tests

```bash
cd backend
php artisan test                # whole suite
php artisan test --filter=PaymentNotificationTest
vendor/bin/pint app config database routes tests   # formatting
```

| Suite | Covers |
| --- | --- |
| `CheckoutFlowTest` | Split at checkout for two and three tenants, idempotency key replay, 409 on a different cart, gateway failure retry, queue blocked before payment |
| `PaymentNotificationTest` | Paid split (including Rp 1 and odd amounts), replayed notifications, invalid signature, amount mismatch, late payment, already-paid, unknown order, expiry, reconciliation, forged-then-genuine delivery |
| `TenantIsolationTest` | Manager scoping, guessed IDs (`403`), operator read-only access, guest `401`, QR proxy |
| `SettlementTest` | 80/20 stored split, unpaid excluded, date filter, totals reconcile |
| `CatalogAndSeederTest` | 13 tenants, manager per tenant, integer rupiah prices, unknown product rejected |

Mutation sensitivity (does removing a safeguard actually break a test?):

```bash
cd backend
php scripts/mutation-check.php          # 8 of 9 mutations killed, M2 explained
php scripts/mutation-check.php --only=M4
```

The harness patches one safeguard at a time, runs the test that should catch it, prints the failure
line, and restores the file. The results are recorded in [`docs/EVIDENCE.md`](docs/EVIDENCE.md).

Frontend checks:

```bash
cd frontend
npm run build
npm run lint
```

Concurrency evidence (same `Idempotency-Key` in parallel, replayed notifications) needs a running
PostgreSQL-backed server:

```bash
cd backend
php artisan serve --port=8000
# in another shell
php scripts/break-it.php http://127.0.0.1:8000 50
```

The harness picks two products from different tenants if no ids are passed, fires N parallel
requests at both the checkout and the notification endpoint, then asserts the invariants against
the database. SQLite only proves correctness, not concurrency: it serialises writers. On Linux/macOS
you can raise the worker count with `PHP_CLI_SERVER_WORKERS=8 php artisan serve --port=8000`;
Windows does not support that flag, so use WSL, Laragon/Apache, or a Linux host for the recorded
run. The PHP build also needs `pdo_pgsql` enabled.

See [`docs/EVIDENCE.md`](docs/EVIDENCE.md) for the expected output and the thesis mapping.

## 9. Repository structure

```
backend/
  app/Http/Controllers/Api/V1/   catalog, cart, checkout, payment notification, tenant, settlement
  app/Services/                  CheckoutService, PaymentService, MidtransGateway,
                                 CartService, SettlementService
  app/Console/Commands/          checkouts:expire, payments:reconcile,
                                 payments:demo-charge, payments:simulate
  app/Models/                    Checkout, CheckoutItem, TenantOrder, PaymentAttempt,
                                 PaymentNotification, Tenant, Outlet, Product, User
  database/migrations/           schema, with the unique idempotency indexes
  database/seeders/              13 tenants, managers, operator
  scripts/break-it.php           concurrency and replay harness
  scripts/mutation-check.php     mutation sensitivity harness for the test suite
  tests/                         35 tests / 201 assertions
frontend/
  src/pages/                     Kiosk, TenantDashboard, OperatorDashboard
  src/api.ts                     typed API client
docs/                            PRD, BSD, DIAGRAMS, API, EVIDENCE, BACKLOG, AI log
```

## 10. Known limitations

- **Sandbox only.** No production keys, no settlement to real bank accounts.
- **Webhooks need public HTTPS.** Without a tunnel the live notification path cannot be exercised
  from a local machine; the CLI harness is the documented fallback and still uses the signature
  check and state machine.
- **No refund handling.** A payment that arrives after expiry is recorded as `late_payment` and
  left for manual handling in the Midtrans dashboard.
- **Concurrency evidence requires PostgreSQL.** SQLite serialises writers, so the parallel-request
  harness proves nothing there.
- **Demo bearer tokens.** There is no password login UI; tokens come from the seeder. Acceptable
  for an academic prototype, not for production.
- **No kitchen lifecycle beyond `queued`.** `preparing`/`ready`/`collected` were dropped from the
  scope on purpose.
- **Single-node scheduler.** `schedule:work` or a cron entry is assumed; no distributed lock.
