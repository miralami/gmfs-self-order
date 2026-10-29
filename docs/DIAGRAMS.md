# Diagrams (BAB III)

Mermaid sources for the design chapter. GitHub renders them directly; export PNG/SVG from
<https://mermaid.live> for the thesis document. Keep them in sync with `BSD.md` and the code.

## 1. Entity relationship diagram

```mermaid
erDiagram
    TENANTS ||--o{ OUTLETS : owns
    TENANTS ||--o{ PRODUCTS : sells
    OUTLETS ||--o{ PRODUCTS : stocks
    TENANTS ||--o{ USERS : employs
    TENANTS ||--o{ TENANT_ORDERS : receives
    CHECKOUTS ||--o{ CHECKOUT_ITEMS : contains
    CHECKOUTS ||--o{ TENANT_ORDERS : splits_into
    CHECKOUTS ||--o{ PAYMENT_ATTEMPTS : attempts
    PAYMENT_ATTEMPTS ||--o{ PAYMENT_NOTIFICATIONS : audited_by

    TENANTS {
        bigint id PK
        string name
        string slug UK
        boolean is_active
    }
    OUTLETS {
        bigint id PK
        bigint tenant_id FK
        string name
        boolean is_active
    }
    PRODUCTS {
        bigint id PK
        bigint tenant_id FK
        bigint outlet_id FK
        string name
        int price
        boolean is_active
    }
    USERS {
        bigint id PK
        bigint tenant_id FK
        string email UK
        string role
        string api_token
    }
    CHECKOUTS {
        bigint id PK
        uuid reference UK
        string idempotency_key UK
        string request_hash
        int total_amount
        string status
        timestamp expires_at
        timestamp paid_at
    }
    CHECKOUT_ITEMS {
        bigint id PK
        bigint checkout_id FK
        bigint tenant_id FK
        string product_name
        int unit_price
        int quantity
        int line_total
    }
    TENANT_ORDERS {
        bigint id PK
        bigint checkout_id FK
        bigint tenant_id FK
        string public_code UK
        int total_amount
        int tenant_share_amount
        int platform_fee_amount
        string status
        timestamp paid_at
        timestamp queued_at
    }
    PAYMENT_ATTEMPTS {
        bigint id PK
        bigint checkout_id FK
        int attempt_no
        string gateway_order_id UK
        string status
        int amount
        text qr_string
        timestamp expires_at
    }
    PAYMENT_NOTIFICATIONS {
        bigint id PK
        bigint payment_attempt_id FK
        string gateway_order_id
        string transaction_status
        string status_code
        string signature_key
        boolean signature_valid
        string outcome
    }
```

The isolation boundary sits at `TENANT_ORDERS.tenant_id`: every tenant-manager query is filtered by
it, and the unique `(checkout_id, tenant_id)` pair is what prevents a double split.

## 2. Checkout state

```mermaid
stateDiagram-v2
    [*] --> unpaid : POST /checkouts
    unpaid --> paid : settlement / capture + accept
    unpaid --> expired : expires_at reached
    unpaid --> cancelled : reserved, not exposed
    paid --> [*]
    expired --> [*]
    cancelled --> [*]
```

## 3. Payment attempt state

```mermaid
stateDiagram-v2
    [*] --> pending : POST /v2/charge
    pending --> settled : settlement / capture
    pending --> denied : deny
    pending --> cancelled : cancel / replaced / late / duplicate
    pending --> expired : expire
    pending --> failed : gateway rejected the charge
    settled --> [*]
    denied --> [*]
    cancelled --> [*]
    expired --> [*]
    failed --> [*]
```

## 4. Tenant order state

```mermaid
stateDiagram-v2
    [*] --> unpaid : created at checkout
    unpaid --> paid : payment confirmed
    unpaid --> cancelled : checkout expired / cancelled
    paid --> queued : owning tenant queues
    queued --> [*]
    cancelled --> [*]
```

## 5. Sequence: checkout, split, charge

```mermaid
sequenceDiagram
    actor C as Customer
    participant K as Kiosk (React)
    participant A as Laravel API
    participant D as PostgreSQL
    participant M as Midtrans

    C->>K: add items from several tenants
    K->>A: POST /cart/quote
    A-->>K: totals from product records
    C->>K: Bayar dengan QRIS
    K->>A: POST /checkouts + Idempotency-Key
    A->>D: SELECT checkout by idempotency_key
    alt key already used, same cart
        A-->>K: 200 original checkout (replayed)
    else key already used, different cart
        A-->>K: 409
    else new key
        A->>D: BEGIN; checkout + checkout_items + tenant_orders
        A->>D: COMMIT
        A->>D: insert payment_attempt (pending)
        A->>M: POST /v2/charge (qris, order_id, gross_amount)
        M-->>A: qr_string, expiry_time, actions
        A->>D: update attempt (qr, expiry)
        A-->>K: 201 checkout + child orders + QR
    end
```

## 6. Sequence: payment notification

```mermaid
sequenceDiagram
    participant M as Midtrans
    participant A as Laravel API
    participant D as PostgreSQL

    M->>A: POST /payments/midtrans/notification
    A->>A: verify SHA512(order_id + status_code + gross_amount + key)
    A->>D: BEGIN
    A->>D: insert payment_notifications
    alt duplicate event tuple
        D-->>A: unique violation
        A-->>M: 200 {outcome: duplicate}
    else invalid signature
        A->>D: store signature_valid = false
        A-->>M: 403
    else amount mismatch
        A->>D: flag attempt, keep checkout unpaid
        A-->>M: 200 {outcome: amount_mismatch}
    else settlement and amount matches
        A->>D: lock attempt and checkout
        alt checkout already paid
            A-->>M: 200 {outcome: already_paid}
        else checkout expired
            A-->>M: 200 {outcome: late_payment}
        else still unpaid
            A->>D: attempt settled, checkout paid
            A->>D: each child paid, fee = intdiv(total*20,100)
            A-->>M: 200 {outcome: paid}
        end
    end
```

## 7. Sequence: reconciliation and expiry

```mermaid
sequenceDiagram
    participant S as Scheduler
    participant A as Laravel API
    participant D as PostgreSQL
    participant M as Midtrans

    S->>A: every 2 min: payments:reconcile
    A->>D: pending attempts older than 2 min on unpaid checkouts
    loop each attempt
        A->>M: GET /v2/{order_id}/status
        M-->>A: status payload
        A->>A: applyNotification(payload, trusted)
    end

    S->>A: every 1 min: checkouts:expire
    A->>D: unpaid checkouts with expires_at <= now
    loop each checkout, under row lock
        A->>D: checkout expired, unpaid children cancelled, pending attempts expired
    end
```

## 8. Deployment

```mermaid
flowchart LR
    subgraph Client
        B[Tablet browser<br/>React SPA]
    end
    subgraph VPS
        W[Nginx / Apache]
        P[PHP-FPM<br/>Laravel API]
        DB[(PostgreSQL)]
        CRON[cron: schedule:run]
    end
    M[Midtrans sandbox]

    B -->|HTTPS| W --> P
    P --> DB
    CRON --> P
    M -->|signed notification| W
    P -->|charge / status| M
```

In development, Vite serves the SPA on port 5173 and Laravel on 8000; a tunnel (ngrok or
cloudflared) exposes only the notification endpoint to Midtrans.
