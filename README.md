# Perancangan dan Implementasi Sistem Self-Order Kiosk dengan Arsitektur Multi-Tenant serta Penanganan Pembayaran Idempoten

MVP kiosk self-order terpusat untuk food court multi-tenant: pelanggan bisa memesan dari beberapa tenant dalam satu keranjang dan membayar sekali, lalu checkout dipecah menjadi pesanan per tenant dengan pembayaran yang idempoten.

## Repository Structure
- `/docs/PRD.md` — product requirements and business rules
- `/backend` — Laravel API (modular monolith)
- `/frontend` — React + TypeScript + Vite SPA

## Backend Setup (Laravel)
```bash
cd /home/runner/work/gmfs-self-order/gmfs-self-order/backend
cp .env.example .env
php artisan key:generate
```

### Environment Variables
Set these in `backend/.env` for local PostgreSQL:

```dotenv
APP_NAME=GMFSSelfOrder
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=gmfs_self_order
DB_USERNAME=postgres
DB_PASSWORD=postgres
```

### Migrate and Seed
```bash
php artisan migrate
php artisan db:seed
```

Seeder creates:
- 3 tenants with outlets and products
- 1 operator user (`operator@example.com`, bearer token: `operator-token`)
- 1 tenant manager per tenant (token format: `{tenant-slug}-token`)

## Frontend Setup (React + Vite)
```bash
cd /home/runner/work/gmfs-self-order/gmfs-self-order/frontend
npm install
npm run dev
```

## Key API Endpoints
- `GET /api/v1/catalog/tenants`
- `GET /api/v1/catalog/tenants/{tenant}/products`
- `POST /api/v1/cart/quote`
- `POST /api/v1/checkouts`
- `POST /api/v1/checkouts/{checkout}/payments/mock/simulate`
- `POST /api/v1/checkouts/{checkout}/payments/mock/event`
- `POST /api/v1/checkouts/{checkout}/retry`
- `GET /api/v1/tenant/orders` (auth)
- `POST /api/v1/tenant/orders/{tenantOrder}/queue-preparation` (auth)
- `GET /api/v1/operator/settlements` (auth, operator only)

Use an `Authorization` header with a bearer token for authenticated endpoints.

## Tests
```bash
cd /home/runner/work/gmfs-self-order/gmfs-self-order/backend
php artisan test --filter=CheckoutFlowTest
php artisan test
```

Covered critical flows:
- order splitting
- payment failures
- duplicate payment events
- checkout retries
- cross-tenant authorization
