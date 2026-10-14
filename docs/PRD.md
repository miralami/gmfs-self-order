# Central Self-Order Kiosk PRD

## Scope
Build an academic MVP for a central self-order kiosk serving multiple food-court tenants. Customers can add items from multiple tenants in one cart and pay once through a centrally managed payment account.

## Stack & Architecture
- Backend: Laravel REST API (versioned under `/api/v1`)
- Frontend: React + TypeScript + Vite SPA
- Database: PostgreSQL (tests use SQLite in-memory)
- Architecture: modular monolith (single deployment unit)
- Payments: mock adapter now, real gateway-ready contract later
- Deployment target: affordable single VPS

## Business Rules
1. Cart can include products from multiple tenants.
2. One parent checkout splits into child tenant orders.
3. Tenant order lifecycle cannot move to preparation before backend-confirmed payment success.
4. Payment event handling must be idempotent (duplicate events safe).
5. Tenant users can only access their own tenant orders.
6. Operator users can view cross-tenant settlement summaries.

## Data Model
- `tenants`
- `outlets`
- `products`
- `checkouts` (parent order)
- `checkout_items`
- `tenant_orders` (child orders per tenant)
- `payment_events` (idempotent payment event log)
- `users` with `role`, `tenant_id`, `api_token`

## API Modules
1. Tenant/catalog browsing
2. Multi-tenant cart quote validation
3. Parent checkout creation and tenant split orders
4. Mock payment lifecycle + webhook-style event ingestion
5. Tenant order dashboard and preparation queue action
6. Operator settlement summary

## Acceptance Criteria
- Multi-tenant cart and single checkout workflow works end-to-end.
- Checkout creates split tenant orders.
- Failed payment blocks preparation queue.
- Duplicate payment events do not duplicate effects.
- Failed checkout can be retried cleanly.
- Backend authorization enforces tenant isolation.
- Seed data includes at least three tenants.
- API behavior covered by tests for critical payment/order splitting/security paths.

## Non-Goals
- Live payment gateway integration
- Automated tenant payouts
- Microservices/Kubernetes or complex infrastructure
