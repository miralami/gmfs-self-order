# API Reference

Base URL: `http://localhost:8000/api/v1`
All requests and responses are JSON. Money is always an integer number of rupiah.

Errors follow Laravel's shape:

```json
{ "message": "…", "errors": { "field": ["…"] } }
```

| Status | Meaning in this API |
| --- | --- |
| 200 | OK, including replayed notifications and replayed checkouts |
| 201 | Checkout created |
| 401 | Missing or unknown bearer token |
| 403 | Wrong tenant, or a non-operator asking for settlements / an invalid notification signature |
| 404 | Unknown checkout reference or tenant order |
| 409 | `Idempotency-Key` reused with a different cart |
| 422 | Validation failed, or an order is not in a queueable state |
| 502 | Midtrans rejected or was unreachable |

---

## 1. Catalog

### `GET /catalog/tenants`

Public. Returns every active tenant with its outlets and active products.

```json
{
  "data": [
    {
      "id": 1,
      "name": "Noodle House",
      "slug": "noodle-house",
      "outlets": [{ "id": 1, "tenant_id": 1, "name": "Noodle House - Outlet 1", "is_active": true }],
      "products": [
        { "id": 1, "name": "Beef Noodle", "price": 50000, "outlet_id": 1 }
      ]
    }
  ]
}
```

### `GET /catalog/tenants/{tenant}/products`

Public. `{tenant}` is the numeric tenant id. Returns product rows with their outlet.

```json
{ "data": [{ "id": 1, "tenant_id": 1, "outlet_id": 1, "name": "Beef Noodle", "price": 50000, "is_active": true }] }
```

---

## 2. Cart quote

### `POST /cart/quote`

Public. Prices, tenant ids and totals are computed by the server from `product_id` and `quantity`.

Request:

```json
{ "items": [{ "product_id": 1, "quantity": 2 }, { "product_id": 4, "quantity": 1 }] }
```

Response:

```json
{
  "data": {
    "items": [
      {
        "product_id": 1,
        "product_name": "Beef Noodle",
        "tenant_id": 1,
        "tenant_name": "Noodle House",
        "outlet_id": 1,
        "outlet_name": "Noodle House - Outlet 1",
        "unit_price": 50000,
        "quantity": 2,
        "line_total": 100000
      }
    ],
    "tenant_totals": { "1": 100000 },
    "grand_total": 100000
  }
}
```

`422` if any product is missing or inactive.

---

## 3. Checkout

### `POST /checkouts`

Public. **Requires the `Idempotency-Key` header** (1–100 characters, a UUID is recommended).

Request:

```http
POST /api/v1/checkouts
Idempotency-Key: 9d0d2a4e-6f1c-4b7a-9a6e-2f0c1c8a5b11
Content-Type: application/json

{ "items": [{ "product_id": 1, "quantity": 2 }, { "product_id": 4, "quantity": 1 }] }
```

Response `201` on first creation, `200` on a replay of the same key with the same cart:

```json
{
  "data": {
    "reference": "560a38f6-1221-4735-a401-4356c59d72f2",
    "status": "unpaid",
    "total_amount": 105000,
    "expires_at": "2026-10-30T03:36:54+00:00",
    "paid_at": null,
    "items": [
      { "product_id": 6, "product_name": "Ayam Geprek", "tenant_id": 6, "quantity": 2,
        "unit_price": 35000, "line_total": 70000 }
    ],
    "tenant_orders": [
      {
        "public_code": "GMFS-JV12X0",
        "tenant_id": 6,
        "tenant_name": "Ayam Geprek Juara",
        "total_amount": 70000,
        "status": "unpaid",
        "items": [{ "product_name": "Ayam Geprek", "quantity": 2, "line_total": 70000 }]
      },
      {
        "public_code": "GMFS-JV12X1",
        "tenant_id": 8,
        "tenant_name": "Bakso Pak Kumis",
        "total_amount": 35000,
        "status": "unpaid",
        "items": [{ "product_name": "Bakso Telur", "quantity": 1, "line_total": 35000 }]
      }
    ],
    "payment": {
      "attempt_no": 1,
      "status": "pending",
      "amount": 105000,
      "qr_string": "00020101021226670016COM.GOJEK.WWW0118…",
      "qr_image_url": "http://localhost:8000/api/v1/checkouts/560a38f6…/qris",
      "expires_at": "2026-10-30T03:36:54+00:00",
      "failure_reason": null
    }
  },
  "meta": { "replayed": false, "payment_error": null }
}
```

Behaviour:

- One tenant order per distinct tenant in the cart, created immediately as `unpaid`.
- `meta.replayed = true` when the key was already used with the same cart; no new charge is made.
- `409` when the key was already used with a **different** cart.
- `422` when the header is missing or longer than 100 characters.
- If the Midtrans charge fails, the checkout is still created and `meta.payment_error` explains why;
  the kiosk can retry with `POST /checkouts/{reference}/payment-attempts`.
  If `MIDTRANS_SERVER_KEY` is empty, the checkout is created without calling Midtrans and
  `payment_error` says the key is not configured.

### `GET /checkouts/{reference}`

Public. Same `data` shape as above, refreshed. This is what the kiosk polls every 3 seconds.
The response never exposes the raw Midtrans response or the Midtrans QR URL.

### `POST /checkouts/{reference}/payment-attempts`

Public. Cancels the previous pending attempt, creates attempt `n+1`, and charges QRIS again.
Returns the refreshed checkout. `422` if the checkout is no longer payable, `502` if Midtrans
rejects the charge (the checkout stays `unpaid`).

### `GET /checkouts/{reference}/qris`

Public. Streams the QR image from Midtrans with `Cache-Control: no-store`. `404` if no image is
available, `502` if Midtrans cannot be reached.

---

## 4. Midtrans notification

### `POST /payments/midtrans/notification`

Public endpoint, authenticity comes from `signature_key`, not from authentication.
Midtrans-shaped body:

```json
{
  "transaction_id": "b1e0f6a2-…",
  "order_id": "GMFS-560a38f612214735a4014356c59d72f2-1",
  "gross_amount": "105000.00",
  "payment_type": "qris",
  "transaction_status": "settlement",
  "transaction_time": "2026-10-30 10:21:54",
  "status_code": "200",
  "fraud_status": "accept",
  "signature_key": "…"
}
```

The handler verifies `SHA512(order_id + status_code + gross_amount + server_key)`, stores the
delivery in `payment_notifications`, and applies the state machine. Response:

```json
{ "message": "OK", "outcome": "paid" }
```

`403 { "message": "Invalid signature." }` when the signature does not match; the delivery is still
stored with `signature_valid = false` for the audit trail.

Possible `outcome` values:

| Outcome | Meaning |
| --- | --- |
| `paid` | Checkout and all child orders marked paid, 80/20 split stored |
| `pending` | Nothing to do yet |
| `duplicate` | Byte-identical replay, rejected by the unique index |
| `already_paid` | Checkout was already paid before this delivery |
| `late_payment` | Payment arrived after expiry; attempt cancelled, no money applied |
| `amount_mismatch` | `gross_amount` differs from the attempt amount; flagged on the attempt |
| `unknown_order` | No `payment_attempts.gateway_order_id` matches |
| `invalid_signature` | Signature check failed, nothing changed |
| `denied` / `cancelled` / `expired` / `failed` | Attempt-level terminal state |
| `already_settled` | Attempt was already settled |
| `malformed` | Missing `order_id` or `transaction_status` |

---

## 5. Tenant orders (bearer token)

Header: `Authorization: Bearer <token>`. See the README for seeded tokens.

### `GET /tenant/orders?status=paid`

Returns only the caller's tenant orders (all tenants for the operator). Newest first, max 100.

```json
{
  "data": [
    {
      "id": 12,
      "public_code": "GMFS-JV12X0",
      "tenant_id": 6,
      "tenant_name": "Ayam Geprek Juara",
      "checkout_reference": "560a38f6-1221-4735-a401-4356c59d72f2",
      "total_amount": 70000,
      "tenant_share_amount": 56000,
      "platform_fee_amount": 14000,
      "status": "paid",
      "paid_at": "2026-10-30T03:21:54+00:00",
      "queued_at": null,
      "items": [
        { "product_name": "Ayam Geprek", "quantity": 2, "unit_price": 35000, "line_total": 70000 }
      ]
    }
  ]
}
```

### `GET /tenant/orders/{id}`

Returns one order. `403` if it belongs to another tenant.

### `POST /tenant/orders/{id}/queue-preparation`

Moves `paid` → `queued` and sets `queued_at`. `422` if the order is not `paid`
(including `unpaid`, `queued` twice, and `cancelled`). `403` for another tenant, and for the
operator: only the owning tenant operates its own stall.

---

## 6. Settlement (bearer token, operator only)

### `GET /operator/settlements?from=2026-10-01&to=2026-10-31`

`403` for tenant managers. Dates are inclusive; `from`/`to` are optional.

```json
{
  "data": {
    "rows": [
      {
        "tenant_id": 6,
        "tenant_name": "Ayam Geprek Juara",
        "order_count": 1,
        "gross_amount": 70000,
        "tenant_share": 56000,
        "platform_fee": 14000
      },
      {
        "tenant_id": 8,
        "tenant_name": "Bakso Pak Kumis",
        "order_count": 1,
        "gross_amount": 35000,
        "tenant_share": 28000,
        "platform_fee": 7000
      }
    ],
    "totals": {
      "order_count": 2,
      "gross_amount": 105000,
      "tenant_share": 84000,
      "platform_fee": 21000
    },
    "platform_fee_percent": 20
  }
}
```

Only `paid` and `queued` orders with a non-null `paid_at` are included. The split values are read
from the `tenant_orders` row, fixed at payment confirmation, so `tenant_share + platform_fee`
equals `gross_amount` by construction.

---

## 7. CLI endpoints (not HTTP)

| Command | Purpose |
| --- | --- |
| `php artisan checkouts:expire` | Close unpaid checkouts past `expires_at`; cancel child orders and pending attempts |
| `php artisan payments:reconcile` | Call Get Status for pending attempts older than 2 minutes and apply the result |
| `php artisan payments:demo-charge <reference>` | Offline demo attempt, no Midtrans call, no HTTP surface |
| `php artisan payments:simulate <reference> [--outcome=…]` | Build and sign a Midtrans-shaped notification and pass it through `PaymentService::applyNotification` |
