# Central Self-Order Kiosk — Product Requirements Document

## 1. Ringkasan Produk

GMFS Self-Order adalah kiosk pemesanan mandiri untuk food court multi-tenant. Pelanggan memilih makanan dari berbagai tenant dalam satu keranjang, membayar sekali, dan setiap tenant menerima child order masing-masing. Sistem ini dirancang sebagai MVP akademik yang berjalan di satu VPS murah.

### Tujuan

- Pelanggan memesan dari **banyak tenant sekaligus** dalam satu transaksi.
- Checkout otomatis memecah pesanan ke masing-masing tenant (split order).
- Pembayaran terpusat: satu rekening kiosk menerima dana, lalu settlement ke tenant.
- Tenant hanya melihat pesanan miliknya; operator melihat ringkasan lintas tenant.

---

## 2. Stack & Arsitektur

| Komponen | Teknologi |
|---|---|
| Backend | Laravel 13 REST API (modular monolith) |
| Frontend | React 19 + TypeScript 6 + Vite 8 SPA |
| Styling | Tailwind CSS v4 |
| Database | MySQL 8.4 (lokal); SQLite in-memory (test) |
| Routing frontend | react-router-dom v7 |
| Pembayaran | Mock adapter (kontrak siap gateway nyata) |
| Deployment target | Single VPS |

Arsitektur: **modular monolith** — satu unit deploy, tanpa microservice. Backend menyajikan REST API di bawah `/api/v1`, frontend mengonsumsi lewat Vite dev proxy.

---

## 3. Peran Pengguna

| Peran | Deskripsi | Autentikasi |
|---|---|---|
| **Pelanggan** | Pengguna kiosk. Browsing katalog, isi keranjang, checkout, bayar. | Tanpa auth (endpoint publik) |
| **Tenant Manager** | Pemilik/manajer satu tenant. Melihat pesanan tenant-nya, antrekan persiapan. | Bearer token (`{slug}-token`), `role = tenant_manager` |
| **Operator** | Pengelola food court. Melihat ringkasan settlement lintas tenant. | Bearer token (`operator-token`), `role = operator` |

### Mekanisme Auth

- Tidak ada endpoint login. Token di-seed ke database, disimpan sebagai SHA-256 hash di kolom `api_token`.
- Client mengirim `Authorization: Bearer <plain-token>` di header.
- Middleware `AuthenticateApiToken` mencari user by hash, set ke `Auth::setUser()`.

### Token Seed

| User | Email | Token | Peran |
|---|---|---|---|
| Operator | `operator@example.com` | `operator-token` | `operator` |
| Noodle House Manager | `noodle-house@example.com` | `noodle-house-token` | `tenant_manager` |
| Burger Point Manager | `burger-point@example.com` | `burger-point-token` | `tenant_manager` |
| Fresh Bowl Manager | `fresh-bowl@example.com` | `fresh-bowl-token` | `tenant_manager` |

---

## 4. Model Data

### 4.1 Entity Relationship

```
tenants 1──N outlets 1──N products
   │                        │
   │                        │
   └──N tenant_orders ──1 checkouts 1──N checkout_items
            │                  │
            │                  └──N payment_events
            │
   users (tenant_id FK nullable)
```

### 4.2 Tabel

#### `tenants`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | Auto increment |
| `name` | string | Nama tenant |
| `slug` | string unique | URL-safe identifier |
| `is_active` | boolean | Tenant aktif/tidak |
| `created_at`, `updated_at` | timestamp | |

#### `outlets`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | |
| `tenant_id` | FK → tenants | Cascade delete |
| `name` | string | Nama outlet |
| `is_active` | boolean | |
| `created_at`, `updated_at` | timestamp | |

#### `products`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | |
| `tenant_id` | FK → tenants | Cascade delete |
| `outlet_id` | FK → outlets | Cascade delete |
| `name` | string | Nama produk |
| `price_cents` | unsigned int | **Harga dalam rupiah utuh** (penamaan field menyesatkan; 50000 = Rp 50.000) |
| `is_active` | boolean | |
| `created_at`, `updated_at` | timestamp | |

> **Catatan:** Field `price_cents` berisi nilai rupiah utuh, bukan sen. Nilai 50000 ditampilkan sebagai Rp 50.000. Penamaan ini warisan scaffolding dan belum di-rename.

#### `checkouts` (parent order)

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | |
| `reference` | uuid unique | Nomor referensi checkout |
| `total_cents` | unsigned int | Total dalam rupiah utuh |
| `status` | string | `awaiting_payment` · `paid` · `payment_failed` |
| `payment_status` | string | `pending` · `paid` · `failed` |
| `payment_reference` | string nullable | Referensi dari payment gateway |
| `attempt_count` | unsigned int default 1 | Jumlah percobaan bayar |
| `last_error` | string nullable | Pesan error terakhir |
| `created_at`, `updated_at` | timestamp | |

#### `checkout_items`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | |
| `checkout_id` | FK → checkouts | Cascade delete |
| `tenant_id` | FK → tenants | |
| `outlet_id` | FK → outlets | |
| `product_id` | FK → products | |
| `product_name` | string | Snapshot nama saat checkout |
| `unit_price_cents` | unsigned int | Snapshot harga saat checkout |
| `quantity` | unsigned int | |
| `line_total_cents` | unsigned int | `unit_price_cents × quantity` |
| `created_at`, `updated_at` | timestamp | |

#### `tenant_orders` (child order per tenant)

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | |
| `checkout_id` | FK → checkouts | Cascade delete |
| `tenant_id` | FK → tenants | |
| `outlet_id` | FK → outlets | |
| `total_cents` | unsigned int | Subtotal untuk tenant ini |
| `status` | string | `awaiting_payment` · `paid` · `in_preparation` |
| `payment_confirmed_at` | timestamp nullable | Kapan pembayaran dikonfirmasi |
| `preparation_queued_at` | timestamp nullable | Kapan diantrekan persiapan |
| `created_at`, `updated_at` | timestamp | |
| **Index** | `(tenant_id, status)` | Optimasi query dashboard |

#### `payment_events` (log event pembayaran idempotent)

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | |
| `checkout_id` | FK → checkouts | Cascade delete |
| `event_id` | string unique | ID event dari gateway (kunci idempotent) |
| `status` | string | `paid` · `failed` |
| `payload` | json | Data lengkap event |
| `processed_at` | timestamp nullable | |
| `created_at`, `updated_at` | timestamp | |

#### `users`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | |
| `tenant_id` | FK → tenants nullable | Null untuk operator |
| `name` | string | |
| `email` | string unique | |
| `password` | string (hashed) | |
| `role` | string default `operator` | `operator` · `tenant_manager` |
| `api_token` | string(80) nullable unique | SHA-256 hash dari plain token |
| `remember_token`, `email_verified_at` | Laravel default | |
| `created_at`, `updated_at` | timestamp | |

---

## 5. Status & State Machine

### 5.1 Checkout

```
                    ┌─── payment event (paid) ──→ paid
                    │
awaiting_payment ───┤
                    │
                    └─── payment event (failed) ──→ payment_failed
                                                        │
                                                        └── retry ──→ awaiting_payment
                                                                      (attempt_count++)
```

### 5.2 Tenant Order

```
                    ┌─── parent checkout paid ──→ paid ──→ queue-preparation ──→ in_preparation
                    │
awaiting_payment ───┤
                    │
                    └─── parent checkout failed ──→ (tetap awaiting_payment)
                                                    │
                                                    └── retry ──→ awaiting_payment
                                                                  (payment_confirmed_at = null)
```

**Constraint kritis:** `queue-preparation` hanya boleh dilakukan saat `status === 'paid'` DAN `payment_confirmed_at !== null`. Jika tidak → HTTP 422.

---

## 6. Kontrak API

Base path: `/api/v1`

### 6.1 Katalog (publik)

#### `GET /catalog/tenants`

Response tenant aktif beserta outlet dan produk.

```json
{
  "data": [
    {
      "id": 1,
      "name": "Noodle House",
      "slug": "noodle-house",
      "is_active": true,
      "outlets": [{ "id": 1, "tenant_id": 1, "name": "Noodle House Main Outlet", "is_active": true }],
      "products": [
        { "id": 1, "tenant_id": 1, "outlet_id": 1, "name": "Beef Noodle", "price_cents": 50000, "is_active": true },
        { "id": 2, "tenant_id": 1, "outlet_id": 1, "name": "Chicken Noodle", "price_cents": 45000, "is_active": true }
      ]
    }
  ]
}
```

#### `GET /catalog/tenants/{tenant}/products`

Produk aktif untuk satu tenant, dengan relasi outlet.

### 6.2 Keranjang (publik)

#### `POST /cart/quote`

Validasi keranjang multi-tenant dan hitung total.

**Request:**
```json
{
  "items": [
    { "product_id": 1, "quantity": 2 },
    { "product_id": 3, "quantity": 1 }
  ]
}
```

**Validasi:**
- `items`: required, array, min 1
- `items.*.product_id`: required, integer, distinct
- `items.*.quantity`: required, integer, min 1, max 50

**Response 200:**
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
        "outlet_name": "Noodle House Main Outlet",
        "unit_price_cents": 50000,
        "quantity": 2,
        "line_total_cents": 100000
      }
    ],
    "tenant_totals": { "1": 100000, "2": 55000 },
    "grand_total_cents": 155000
  }
}
```

**Response 422:** Produk tidak tersedia → `{ "message": "...", "errors": { "items": "Product X is unavailable." } }`

### 6.3 Checkout (publik)

#### `POST /checkouts`

Buat checkout baru. Otomatis split ke tenant order.

**Request:** Sama seperti `/cart/quote` + tambah `payment_method`.
```json
{
  "items": [
    { "product_id": 1, "quantity": 2 },
    { "product_id": 3, "quantity": 1 }
  ],
  "payment_method": "mock_card"
}
```

**Response 201:**
```json
{
  "data": {
    "id": 1,
    "reference": "uuid",
    "total_cents": 155000,
    "status": "awaiting_payment",
    "payment_status": "pending",
    "attempt_count": 1,
    "items": [ ... ],
    "tenant_orders": [
      { "id": 1, "tenant_id": 1, "total_cents": 100000, "status": "awaiting_payment" },
      { "id": 2, "tenant_id": 2, "total_cents": 55000, "status": "awaiting_payment" }
    ]
  }
}
```

#### `POST /checkouts/{checkout}/retry`

Retry checkout yang gagal.

**Request:** `{ "payment_method": "mock_card" }`

**Syarat:** `payment_status === 'failed'`. Jika tidak → 422 `"Only failed checkouts can be retried."`

**Efek:**
- `status` → `awaiting_payment`, `payment_status` → `pending`, `last_error` → null
- `attempt_count` + 1
- Semua tenant order: `status` → `awaiting_payment`, `payment_confirmed_at` → null, `preparation_queued_at` → null

### 6.4 Mock Payment (publik)

#### `POST /checkouts/{checkout}/payments/mock/simulate`

Simulasi pembayaran untuk testing/demo. Otomatis generate UUID event_id.

**Request (opsional body):** `{ "status": "paid" }` atau `{ "status": "failed" }`

Default: `paid`. Selain `paid`/`failed` → 422.

**Response 200:**
```json
{
  "data": { "id": 1, "event_id": "uuid", "status": "paid", ... },
  "checkout": {
    "id": 1,
    "status": "paid",
    "payment_status": "paid",
    "tenant_orders": [
      { "id": 1, "status": "paid", "payment_confirmed_at": "2026-10-15T..." }
    ]
  }
}
```

#### `POST /checkouts/{checkout}/payments/mock/event`

Terima event pembayaran (webhook-style). **Idempotent by `event_id`** — event duplikat tidak mengubah state.

**Request:**
```json
{
  "event_id": "evt-abc-123",
  "status": "paid",
  "payment_reference": "mock-ABCD1234",
  "reason": null
}
```

**Validasi:**
- `event_id`: required, string
- `status`: required, `paid` | `failed`
- `reason`: nullable, string
- `payment_reference`: nullable, string

**Logika:**
1. `firstOrCreate` by `event_id`. Jika sudah ada → return event lama tanpa mengubah apa pun.
2. Jika `status === 'paid'`:
   - Checkout: `status → paid`, `payment_status → paid`, set `payment_reference`, clear `last_error`
   - Semua tenant order: `status → paid`, set `payment_confirmed_at`
3. Jika `status === 'failed'`:
   - Checkout: `status → payment_failed`, `payment_status → failed`, set `last_error`

### 6.5 Tenant Orders (auth: `api.token`)

#### `GET /tenant/orders`

Daftar tenant order. **Tenant manager** hanya melihat order tenant-nya. **Operator** melihat semua.

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "checkout_id": 1,
      "tenant_id": 1,
      "outlet_id": 1,
      "total_cents": 100000,
      "status": "paid",
      "payment_confirmed_at": "2026-10-15T...",
      "preparation_queued_at": null,
      "checkout": { "id": 1, "reference": "uuid" },
      "tenant": { "id": 1, "name": "Noodle House" }
    }
  ]
}
```

#### `POST /tenant/orders/{tenantOrder}/queue-preparation`

Antrekan order ke persiapan.

**Authorization policy (`TenantOrderPolicy`):**
- `queuePreparation`: hanya tenant manager dari tenant yang sama. Tenant lain → 403.
- Operator **tidak boleh** queue preparation (hanya tenant manager).

**Business rule:** `payment_confirmed_at` harus terisi DAN `status === 'paid'`. Jika tidak → 422 `"Payment must be confirmed before preparation queueing."`

**Efek:** `status → in_preparation`, set `preparation_queued_at`.

### 6.6 Settlement (auth: `api.token`, operator only)

#### `GET /operator/settlements`

Ringkasan settlement per tenant. Hanya operator, selain itu → 403.

**Query params opsional:** `?from=2026-10-01&to=2026-10-31`

**Response 200:**
```json
{
  "data": [
    {
      "tenant_id": 1,
      "tenant_name": "Noodle House",
      "order_count": 5,
      "total_cents": 250000
    }
  ]
}
```

Hanya menghitung tenant order dengan `status === 'paid'`.

---

## 7. Aturan Bisnis

| # | Aturan | Implementasi |
|---|---|---|
| BR-1 | Keranjang boleh berisi produk dari **banyak tenant** | `CartService::quote()` — grupkan item per tenant, hitung subtotal masing-masing |
| BR-2 | Satu checkout → **N child tenant order** (split otomatis) | `CheckoutService::createCheckout()` — satu checkout + N `tenant_orders` dalam DB transaction |
| BR-3 | Persiapan **diblokir** sebelum pembayaran dikonfirmasi | `TenantOrderController::queuePreparation()` — cek `status === 'paid'` && `payment_confirmed_at !== null` |
| BR-4 | Event pembayaran **idempotent** (duplikat aman) | `MockPaymentService::applyEvent()` — `firstOrCreate` by `event_id`, skip jika sudah ada |
| BR-5 | Tenant manager **hanya akses order tenant-nya** | `TenantOrderPolicy` — cek `user.tenant_id === tenantOrder.tenant_id` |
| BR-6 | Operator melihat **settlement lintas tenant** | `SettlementService::summary()` — `GROUP BY tenant_id` di tenant_orders yang `paid` |
| BR-7 | Checkout gagal bisa **di-retry** | `CheckoutService::retry()` — reset status + increment `attempt_count`, reset semua tenant order |
| BR-8 | Harga di-snapshot saat checkout | `checkout_items.product_name` dan `unit_price_cents` — perubahan harga produk tidak mengubah order lama |

---

## 8. Spesifikasi Frontend

### 8.1 Area & Routing

| Path | Area | Auth |
|---|---|---|
| `/` | Kiosk pelanggan — katalog, keranjang, checkout | Tidak |
| `/checkout/:id` | Detail checkout — pembayaran, konfirmasi, retry | Tidak |
| `/tenant` | Dashboard tenant — daftar order, queue preparation | Bearer token tenant manager |
| `/operator` | Settlement operator — ringkasan per tenant | Bearer token operator |

### 8.2 Kiosk Pelanggan (`/`)

**Katalog:**
- Ambil `GET /catalog/tenants` satu kali.
- Tampilkan kartu per tenant, produk di dalamnya dengan nama + harga.
- Tombol tambah ke keranjang + stepper kuantitas (+/−).
- Target sentuh minimum 44×44px (kiosk touch-friendly).

**Panel Keranjang:**
- Tampil persisten di samping/bawah (responsive).
- Item dikelompokkan per tenant, masing-masing dengan subtotal.
- Grand total.
- **Angka dihitung oleh server** via `POST /cart/quote` — bukan hitung di klien. Ini sekaligus validasi produk masih aktif.
- Tombol "Checkout" → `POST /checkouts`, redirect ke `/checkout/:id`.

**Keranjang disimpan di `localStorage`** — tidak hilang saat refresh/navigasi.

### 8.3 Checkout & Pembayaran (`/checkout/:id`)

- Tampilkan detail checkout: referensi, total, daftar item, daftar tenant order (split).
- **Status badge berwarna** per tenant order.
- Saat `awaiting_payment`:
  - Tombol "Simulasi Berhasil" → `POST /checkouts/:id/payments/mock/simulate` (status: paid)
  - Tombol "Simulasi Gagal" → `POST /checkouts/:id/payments/mock/simulate` (status: failed)
- Saat `paid`: tampilkan konfirmasi + badge hijau.
- Saat `payment_failed`:
  - Tampilkan pesan error (`last_error`), badge merah.
  - Tombol **"Coba Lagi"** → `POST /checkouts/:id/retry`, lalu kembali ke state awaiting_payment.
- Tampilkan `attempt_count`.

### 8.4 Dashboard Tenant (`/tenant`)

- **Input token** + tombol quick-pick: `noodle-house-token`, `burger-point-token`, `fresh-bowl-token`.
- Token disimpan di `localStorage`.
- `GET /tenant/orders` → tabel order: referensi checkout, nama tenant, total (Rp), status, waktu konfirmasi, waktu queue.
- Tombol **"Queue Preparation"** per baris:
  - **Aktif** (klik) hanya saat `status === 'paid'`.
  - **Disabled + alasan tertulis** saat status lain (misal: "Menunggu pembayaran" atau "Sudah dalam persiapan").
  - `POST /tenant/orders/:id/queue-preparation` → refresh daftar.
- **Error 403** (akses tenant lain) ditampilkan sebagai alert inline.
- **Empty state** saat belum ada order.

### 8.5 Settlement Operator (`/operator`)

- Input token + quick-pick `operator-token`.
- Dua input tanggal (`from`, `to`) — opsional, tipe `<input type="date">`.
- `GET /operator/settlements?from=&to=` → tabel: nama tenant, jumlah order, total rupiah.
- **Baris grand total** di bawah tabel.
- Empty state saat belum ada order `paid`.
- Error 403 (bukan operator) ditampilkan sebagai alert.

### 8.6 UX & Polish

| Aspek | Detail |
|---|---|
| Loading state | Spinner atau skeleton saat fetch |
| Empty state | Ilustrasi + teks kontekstual |
| Error handling | Alert inline untuk 4xx, toast/banner untuk network error |
| Status badge | Warna: `awaiting_payment` kuning, `paid` hijau, `payment_failed`/`failed` merah, `in_preparation` biru |
| Format uang | `Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })` → `Rp 50.000` |
| Responsif | Layout utama tablet/kiosk (landscape), usable di mobile portrait |
| Touch target | Minimum 44×44px per tombol/link |
| Persistence | Keranjang + token tersimpan di `localStorage` |
| Tombol reset | Bersihkan keranjang / logout token |

---

## 9. Data Seed

Seeder `TenantCatalogSeeder` membuat:

| Tenant | Slug | Produk (harga) |
|---|---|---|
| Noodle House | `noodle-house` | Beef Noodle (Rp 50.000), Chicken Noodle (Rp 45.000) |
| Burger Point | `burger-point` | Classic Burger (Rp 55.000), Cheese Burger (Rp 60.000) |
| Fresh Bowl | `fresh-bowl` | Salad Bowl (Rp 48.000), Fruit Bowl (Rp 35.000) |

Setiap tenant punya 1 outlet. Setiap tenant punya 1 manager user + token.

> **Catatan:** Seeder tidak idempotent (pakai `create()`, bukan `firstOrCreate`). Menjalankan `db:seed` kedua kali gagal karena constraint unique slug. Gunakan `npm run db:fresh` untuk reset.

---

## 10. Test Coverage

Backend test (`tests/Feature/CheckoutFlowTest.php`) — 6 test, 19 assertion:

| Test | Yang Diuji |
|---|---|
| `test_order_is_split_into_child_orders_per_tenant` | BR-2: checkout 2 produk dari 2 tenant → 2 tenant order, total benar |
| `test_failed_payment_does_not_allow_preparation_queue` | BR-3: kirim event failed → queue-preparation → 422 |
| `test_duplicate_payment_events_are_idempotent` | BR-4: event_id sama dikirim 2x → tetap 1 record, status benar |
| `test_checkout_retry_resets_failed_checkout_for_new_attempt` | BR-7: retry → attempt_count naik, status reset ke pending |
| `test_tenant_manager_cannot_access_other_tenant_orders` | BR-5: manager T1 lihat order → hanya T1; queue-prep order T2 → 403 |
| `ExampleTest::test_that_true_is_true` | Sanity check |

---

## 11. Acceptance Criteria

### Fungsional

| # | Kriteria | Endpoint / Komponen | BR |
|---|---|---|---|
| AC-1 | Pelanggan bisa menambah produk dari **≥2 tenant** dalam satu keranjang | UI Kiosk + `POST /cart/quote` | BR-1 |
| AC-2 | Checkout menghasilkan **split tenant order** yang benar | `POST /checkouts` + UI Checkout | BR-2 |
| AC-3 | Subtotal per tenant dan grand total **dihitung server** dan ditampilkan di UI | `POST /cart/quote` response + UI Panel Keranjang | BR-1 |
| AC-4 | Pembayaran berhasil → semua tenant order berubah ke `paid` | `POST /.../simulate` (paid) + UI badge | BR-3 |
| AC-5 | Pembayaran gagal → **queue preparation diblokir** (422 dari API, disabled di UI) | `POST /.../simulate` (failed) → queue-prep → 422 | BR-3 |
| AC-6 | Event pembayaran duplikat **tidak menduplikasi efek** | `POST /.../event` 2x same event_id → 1 record | BR-4 |
| AC-7 | Checkout gagal bisa **di-retry** → status kembali `awaiting_payment`, `attempt_count` naik | `POST /.../retry` + UI tombol "Coba Lagi" | BR-7 |
| AC-8 | Tenant manager **hanya melihat order tenant sendiri** | `GET /tenant/orders` + TenantOrderPolicy | BR-5 |
| AC-9 | Tenant manager **tidak bisa** queue-prep order tenant lain (403) | `POST /.../queue-preparation` + TenantOrderPolicy | BR-5 |
| AC-10 | Operator melihat **settlement lintas tenant** dengan filter tanggal | `GET /operator/settlements?from=&to=` + UI tabel | BR-6 |
| AC-11 | Harga di-snapshot saat checkout — perubahan produk tidak ubah order lama | `checkout_items.product_name` + `unit_price_cents` | BR-8 |
| AC-12 | Seed data mencakup **minimal 3 tenant** dengan produk dan user | `TenantCatalogSeeder` | — |

### Non-fungsional

| # | Kriteria |
|---|---|
| AC-13 | `npm run build` (frontend TypeScript + Vite) → sukses tanpa error |
| AC-14 | `npm run lint` (oxlint) → bersih |
| AC-15 | `npm test` (backend PHPUnit) → semua test hijau |
| AC-16 | `npm run dev` → backend API dan frontend SPA berjalan bersamaan dengan satu perintah |

---

## 12. Alur Verifikasi Manual

### Alur A — Multi-tenant checkout + payment success

1. Buka kiosk (`/`), tambah Beef Noodle (Noodle House) × 2 + Classic Burger (Burger Point) × 1.
2. Lihat panel keranjang: 2 subtotal tenant, grand total = Rp 155.000.
3. Checkout → halaman `/checkout/:id` menampilkan 2 tenant order (split).
4. Klik "Simulasi Berhasil" → semua status berubah ke `paid`, badge hijau.

### Alur B — Payment failure + retry

1. Buat checkout baru.
2. Klik "Simulasi Gagal" → status `payment_failed`, tombol "Coba Lagi" muncul.
3. Klik "Coba Lagi" → `attempt_count` naik, status kembali `awaiting_payment`.
4. Klik "Simulasi Berhasil" → status `paid`.

### Alur C — Tenant isolation + queue preparation

1. Buka `/tenant`, masukkan token `noodle-house-token`.
2. Lihat hanya order Noodle House.
3. Order yang belum `paid` → tombol queue disabled + alasan.
4. Order yang `paid` → klik "Queue Preparation" → status `in_preparation`.
5. Coba akses order Burger Point → 403.

### Alur D — Operator settlement

1. Buka `/operator`, masukkan token `operator-token`.
2. Lihat tabel settlement semua tenant yang punya order `paid`.
3. Set filter tanggal → hasil berubah.
4. Pakai token tenant → 403.

Reset data antar alur: `npm run db:fresh`.

---

## 13. Non-Goals

| Item | Alasan |
|---|---|
| Integrasi payment gateway nyata | MVP menggunakan mock adapter |
| Automated tenant payout | Di luar scope kiosk |
| Login / register / session auth | Token statis di-seed, cukup untuk demo |
| Microservices / Kubernetes | Target single VPS |
| Real-time update (WebSocket) | Polling / manual refresh cukup untuk MVP |
| Gambar produk | Tidak ada kolom/storage gambar di data model |
| Inventori / stok | Di luar scope MVP |
| Multi-bahasa (i18n) | UI dalam Bahasa Indonesia, label minimal |

---

## 14. Perintah Pengembangan

```bash
# Setup awal (sekali)
npm run setup

# Jalankan backend + frontend
npm run dev
# API  → http://127.0.0.1:8000
# SPA  → http://localhost:5173

# Test, lint, build
npm test
npm run lint
npm run build

# Database
npm run db:migrate        # Jalankan migrasi
npm run db:fresh          # Reset + seed ulang
```

---

## 15. Struktur Repository

```
gmfs-self-order/
├── docs/
│   └── PRD.md                          ← dokumen ini
├── backend/                            ← Laravel 13 API
│   ├── app/
│   │   ├── Http/Controllers/Api/V1/    ← 6 controller
│   │   ├── Http/Middleware/            ← AuthenticateApiToken
│   │   ├── Http/Requests/             ← 4 form request
│   │   ├── Models/                    ← 8 model
│   │   ├── Policies/                  ← TenantOrderPolicy
│   │   └── Services/                  ← 4 service (Cart, Checkout, MockPayment, Settlement)
│   ├── database/
│   │   ├── migrations/                ← 11 migrasi
│   │   └── seeders/                   ← TenantCatalogSeeder
│   ├── routes/api.php                 ← definisi route API
│   ├── tests/Feature/                 ← CheckoutFlowTest (6 test)
│   ├── .env                           ← konfigurasi lokal (gitignored)
│   └── composer.json
├── frontend/                           ← React 19 + Vite 8 SPA
│   ├── src/
│   │   ├── lib/                       ← api, types, format, storage
│   │   ├── pages/                     ← Kiosk, Checkout, TenantDashboard, Settlement
│   │   ├── components/                ← NavBar, StatusBadge, Money, dll
│   │   ├── App.tsx                    ← router shell
│   │   ├── main.tsx                   ← entry point
│   │   └── index.css                  ← Tailwind import
│   ├── vite.config.ts                 ← plugin Tailwind + proxy /api
│   └── package.json
├── package.json                        ← root orchestrator (concurrently, scripts)
├── .gitignore
└── README.md
```

---

## 16. Fitur Rekomendasi Cerdas ("AI Tipis")

Kebaruan tambahan: seksi **"Rekomendasi untukmu"** di kiosk yang menampilkan menu/tenant relevan di posisi teratas, plus **slot promosi berbayar (ads)** tenant. Terhubung ke BR-6 (settlement) → potensi bagi hasil iklan.

### 16.1 Tujuan

- Membantu pelanggan memilih dari puluhan menu lintas tenant (*mengurangi choice overload*).
- Memberi peluang promosi bagi tenant (monetisasi) secara **adil**.
- Menjadi kebaruan akademik yang **ringan, transparan, dan dapat dievaluasi**.

### 16.2 Mekanisme Inti (Hybrid)

Skor setiap kandidat item (atau tenant) dihitung dari kombinasi sinyal berikut:

```
score(item) = w1·populer             # jumlah pesanan, dinormalisasi
            + w2·konteks_waktu       # pagi/siang/malam cocok dengan kategori menu
            + w3·komplemen_keranjang # association rules (Apriori): sering dibeli bersama isi keranjang
            + w4·boost_promosi        # slot berbayar, dinormalisasi & dibatasi (fairness cap)
            + w5·riwayat_pelanggan    # opsional, dari localStorage (perangkat), bukan akun
```

- Bobot `w1..w5` **dapat dikonfigurasi** dan ditampilkan (tema transparansi).
- **Association rules** dihitung berkala (Artisan command/queued job) dari tabel pesanan dengan algoritma Apriori (metrik support/confidence/lift), bukan real-time.
- **Cold-start:** bila data pesanan belum cukup → andalkan popularitas + konteks; LLM (opsional) menyusun alasan.

### 16.3 Slot Promosi (Ads) & Keadilan

- `promotions` menentukan boost tenant/menu; boost dinormalisasi agar tidak mendominasi.
- **Fairness cap:** batasi jumlah kemunculan slot berbayar per tenant per sesi; lakukan rotasi/kuota agar tenant kecil tetap tampil.
- Item berbayar **wajib** diberi label **"Sponsored"** di UI.
- Relevansi tetap dijaga: item berbayar yang tidak relevan tidak boleh menutupi item relevan.

### 16.4 LLM (Opsional)

- Digunakan hanya untuk (a) menyusun **kalimat alasan** rekomendasi dan (b) membantu *cold-start*.
- **Bukan** penentu logika inti; sistem tetap berfungsi penuh tanpa LLM (*graceful degradation*).
- Bila diaktifkan, gunakan penyedia/mode gratis (mis. free tier) dan batasi pemanggilan (cache).

### 16.5 Model Data (tambahan)

#### `promotions`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | |
| `tenant_id` | FK → tenants | Tenant pemilik promosi |
| `product_id` | FK → products nullable | Null = promosi tingkat tenant |
| `boost_weight` | unsigned int | Besar boost (dinormalisasi) |
| `starts_at`, `ends_at` | timestamp | Periode aktif |
| `is_active` | boolean | |
| `created_at`, `updated_at` | timestamp | |

#### `recommendation_events`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | |
| `session_ref` | string | Identitas sesi kiosk |
| `product_id` | FK → products | Item yang direkomendasikan |
| `reason` | string | Alasan yang ditampilkan |
| `is_sponsored` | boolean | Penanda slot berbayar |
| `event_type` | string | `impression` · `click` |
| `created_at`, `updated_at` | timestamp | |

#### `association_rules`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | |
| `antecedent` | json | Daftar product_id pemicu |
| `consequent` | json | Daftar product_id yang disarankan |
| `support` | decimal | Nilai support |
| `confidence` | decimal | Nilai confidence |
| `lift` | decimal | Nilai lift |
| `computed_at` | timestamp | Waktu perhitungan terakhir |

### 16.6 Kontrak API (tambahan)

#### `GET /recommendations`

Query: `?context=<pagi|siang|malam|event>&cart=<productId,productId>&limit=<n>`

**Response 200:**
```json
{
  "data": [
    {
      "product_id": 7,
      "product_name": "Es Teh Manis",
      "tenant_id": 1,
      "tenant_name": "Noodle House",
      "score": 0.87,
      "reason": "Sering dibeli bersama pesananmu",
      "is_sponsored": false
    },
    {
      "product_id": 12,
      "product_name": "Paket Hemat Burger",
      "tenant_id": 2,
      "tenant_name": "Burger Point",
      "score": 0.81,
      "reason": "Promo tenant",
      "is_sponsored": true
    }
  ]
}
```

### 16.7 Aturan Bisnis (tambahan)

| # | Aturan |
|---|---|
| BR-9 | Rekomendasi tidak boleh mengubah invarian BR-1…BR-8 (hanya menyisipkan saran visual). |
| BR-10 | Item berbayar **wajib** berlabel `is_sponsored`; jumlah kemunculannya dibatasi (fairness cap) per tenant per sesi. |
| BR-11 | Bila layanan LLM tidak tersedia, rekomendasi tetap tampil memakai skor + alasan berbasis aturan. |
| BR-12 | Bobot skor dapat dikonfigurasi; nilai yang berlaku aktif ditampilkan/dapat diinspeksi. |

### 16.8 Frontend (tambahan)

- Seksi **"Rekomendasi untukmu"** di atas katalog kiosk (`/`).
- Kartu rekomendasi menampilkan nama, tenant, harga, `reason`, dan badge **"Sponsored"** bila berbayar.
- Klik rekomendasi mencatat `recommendation_events` (`click`) dan menambah item ke keranjang.
- Impresi dicatat saat kartu tampil.

### 16.9 Evaluasi (tambahan)

- **Offline:** `precision@k` / hit-rate dari data pesanan (split train/test), plus metrik *association rules* (support/confidence/lift).
- **Online/UAT:** skor kegunaan & kepercayaan; bandingkan sesi dengan vs tanpa rekomendasi (A/B kecil) bila memungkinkan.
- **Fairness:** ukur sebaran kemunculan slot berbayar antartenant.

### 16.10 Acceptance Criteria (tambahan)

| # | Kriteria |
|---|---|
| AC-17 | `GET /recommendations` mengembalikan daftar ter-ranking beserta `reason` dan `is_sponsored`. |
| AC-18 | Rekomendasi berubah sesuai konteks waktu dan isi keranjang. |
| AC-19 | Slot berbayar tampil dengan label "Sponsored" dan dibatasi per tenant per sesi (fairness). |
| AC-20 | Sistem tetap menampilkan rekomendasi saat LLM dinonaktifkan/tidak tersedia. |
| AC-21 | Nilai `precision@k` terhitung dari data uji dan dilaporkan di laporan TA. |

