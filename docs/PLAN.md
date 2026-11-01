# GMFS Self-Order — Master Development Plan

> **Metodologi:** R&D + Prototyping (Pressman & Maxim, 2020)
> **Siklus:** Plan → Build → Evaluate → Iterate
> **Revisi:** v3 — 28 Oktober 2026
> **Perubahan v2→v3:** Fase 0 (perancangan untuk Bab III), Gerbang Umpan Balik per iterasi, dan **§12 Rencana Pelaporan** — karena laporan skripsi adalah produk utama, kode adalah alatnya
> **Perubahan v1→v2:** 6 gap backend ditemukan (3 blocker), 80:20 naik jadi scope inti, Postgres switch dideduplikasi, deploy + artefak evaluasi ditambahkan

---

## 0. Baseline Audit

### 0.1 Sudah Ada & Terverifikasi

| Komponen | Status | Bukti |
|---|---|---|
| Laravel 13 API | ✅ | 10 route di `/api/v1` |
| Split order (BR-2) | ✅ | `CheckoutService::createCheckout()` — 1 checkout + N tenant_order dalam 1 transaksi |
| Idempotensi event (BR-4) | ✅ | `MockPaymentService` — `firstOrCreate` by `event_id` |
| Isolasi tenant (BR-5) | ✅ | `TenantOrderPolicy` + 403 test |
| Snapshot harga (BR-8) | ✅ | `checkout_items.unit_price_cents` |
| Retry (BR-7) | ✅ | `CheckoutService::retry()` |
| Queue prep gating (BR-3) | ✅ | cek `status==='paid'` && `payment_confirmed_at !== null` |
| Test suite | ✅ | 6 test / 19 assertion, hijau |
| Seed | 🟡 | 3 tenant / 6 produk / 4 user — akan diperluas ke 13 (G-5) |

### 0.2 Gap Backend — HARUS DIPERBAIKI SEBELUM FRONTEND

Ditemukan saat review kode. Semua ini memblokir requirement TA.

| ID | Gap | Dampak | Prioritas |
|---|---|---|---|
| **G-1** | `SettlementService` filter `where('status','paid')` → order `in_preparation` hilang dari laporan | Bagi hasil salah hitung. **Mematahkan rumusan masalah #1** | 🔴 Blocker |
| **G-2** | Tidak ada perhitungan 80:20 (`tenant_share` / `operator_share`) | Requirement inti TA tidak terpenuhi | 🔴 Blocker |
| **G-3** | Tidak ada `GET /checkouts/{checkout}` | Halaman `/checkout/:id` mati setelah refresh | 🔴 Blocker |
| **G-4** | `payment_method` diterima tapi diabaikan (tidak disimpan, tidak divalidasi) | Kontrak PRD §6.3 tidak akurat | 🟡 Minor |
| **G-5** | `TenantCatalogSeeder` non-idempotent (`create()`) | `db:seed` kedua kali gagal | 🟡 Minor |
| **G-6** | Test hanya di SQLite → switch Postgres tidak terverifikasi | Kualitas bukti Bab V lemah | 🟡 Minor |

### 0.3 Scaffold / Belum Ada

| Komponen | Status |
|---|---|
| Frontend | Default Vite "Get started" — 0 halaman |
| Routing | Belum ada `react-router-dom` |
| Styling | Belum ada Tailwind |
| Vite proxy `/api` | Belum dikonfigurasi |
| Database | `.env` masih MySQL; Metopen minta PostgreSQL |

### 0.4 Out of Scope (Final)

| Item | Alasan |
|---|---|
| Rekomendasi cerdas + slot promosi (PRD §16) | Metopen batasan §I.4.7 — pengembangan lanjutan |
| Payment gateway nyata / QRIS live | Metopen batasan §I.4.4 |
| Automated payout, refund, pajak, fee gateway | Metopen batasan §I.4.5 |
| Microservices, Kubernetes, multi-server | Metopen batasan §I.4.6 |
| Load test, security test tingkat lanjut | Metopen batasan §I.4.8 |
| Gambar produk, inventori, WebSocket, i18n | PRD §13 |

### 0.5 Kontradiksi & Keputusan

| Item | Metopen | PRD | Keputusan |
|---|---|---|---|
| Database | PostgreSQL (§I.4.3) | MySQL 8.4 | **PostgreSQL** |
| Rekomendasi | Out of scope (§I.4.7) | Spek penuh §16 | **Out of scope**, masuk saran Bab VI |
| Jumlah tenant | 13 | 3 seed | **13 tenant** — seed dilengkapi agar konsisten dengan Metopen §I.1 (Lampiran A) |
| `price_cents` | — | rupiah utuh, bukan sen | **Pertahankan**, dokumentasikan |
| Bagi hasil | 80:20 | tidak dispesifikasi di §6.6 | **80:20 ditambahkan** ke BR & API |

---

## 1. Business Rules — Revisi

Aturan lama tetap. Tambahan:

| # | Aturan | Implementasi |
|---|---|---|
| **BR-13** | Settlement menghitung **semua order yang pembayarannya terkonfirmasi**, bukan hanya yang masih `paid` | Filter `payment_confirmed_at IS NOT NULL` (mencakup `paid` + `in_preparation`) |
| **BR-14** | Bagi hasil 80:20 dengan pembulatan **tanpa kehilangan rupiah** | `tenant_share = intdiv(gross × 80, 100)`, `operator_share = gross − tenant_share`. Sisa pembulatan masuk operator. |
| **BR-15** | Detail checkout dapat diambil ulang kapan saja (refresh-safe) | `GET /checkouts/{checkout}` publik, return items + tenant_orders |

> **Catatan BR-14:** `intdiv` dipilih agar `tenant_share + operator_share == gross` selalu benar. Contoh: gross Rp 50.005 → tenant 40.004, operator 10.001. Aturan ini wajib diuji dan disebut di Bab III/IV.

### Kontrak API Baru

```
GET /api/v1/checkouts/{checkout}
→ 200 { data: { id, reference, total_cents, status, payment_status, attempt_count, last_error, items[], tenant_orders[] } }
→ 404 bila tidak ada

GET /api/v1/operator/settlements?from&to
→ 200 {
    data: [
      { tenant_id, tenant_name, order_count, gross_cents, tenant_share_cents, operator_share_cents }
    ],
    meta: { gross_total_cents, tenant_share_total_cents, operator_share_total_cents }
  }
```

---

## 2. Arsitektur Target

```
┌──────────────────────────────────────────────────────┐
│              Browser (Kiosk / Tablet)                │
│  React 19 + react-router-dom v7 + Tailwind v4        │
│  /            /checkout/:id      /tenant  /operator  │
│  └ localStorage: keranjang        └ localStorage: token│
└───────────────────────┬──────────────────────────────┘
                        │ HTTP via Vite proxy /api → :8000
┌───────────────────────┴──────────────────────────────┐
│                  Laravel 13 REST API                 │
│  CartService · CheckoutService · MockPaymentService  │
│  SettlementService (80:20)                           │
└───────────────────────┬──────────────────────────────┘
                        │
                 ┌──────┴──────┐
                 │ PostgreSQL  │
                 └─────────────┘
```

**Prinsip:**
- Semua angka uang dihitung server (`POST /cart/quote`)
- Keranjang di `localStorage` (pelanggan tanpa auth)
- Tanpa state management library, tanpa UI kit, tanpa data-fetching library
- Layar utama tablet landscape; mobile portrait tetap usable

---

## 3. Iterasi Prototyping

Setiap iterasi wajib menghasilkan **4 artefak**:
1. Kode yang jalan
2. Review note (`docs/prototype-N-review.md`) — apa yang lolos, apa yang gagal
3. **Log umpan balik pengguna** (`docs/feedback-N.md`) — siapa, kapan, masukan apa, diubah jadi apa
4. Update `PLAN.md` bila scope bergeser

> ⚠️ **Kenapa artefak #3 wajib:** Metopen §I.1 menyatakan model prototyping "menekankan pengembangan secara bertahap serta **pelibatan umpan balik pengguna pada setiap iterasi**". Tanpa log umpan balik, klaim metodologi di Bab III tidak terbukti di Bab IV. Ini bukan formalitas — ini yang membedakan "prototyping" dari "waterfall yang dipecah".

---

### Fase 0 — Analisis Kebutuhan & Perancangan (bahan Bab III)
**Target:** 1–2 hari
**Tujuan:** Menghasilkan artefak perancangan yang bisa **langsung ditempel ke Bab III**. Tanpa fase ini, Bab III tidak punya isi.

Sebagian besar bahan sudah ada di PRD — tugasnya menggambar dan merapikan, bukan riset baru.

- [ ] Daftar **kebutuhan fungsional** (FR-01..FR-14) + sumbernya (§12.2)
- [ ] Daftar **kebutuhan non-fungsional** (NFR-01..NFR-08) (§12.3)
- [ ] **Use case diagram** + deskripsi 3 aktor (Pelanggan, Manajer Tenant, Operator)
- [ ] **ERD** — dari PRD §4.1, digambar rapi (draw.io / dbdiagram)
- [ ] **State machine diagram** checkout & tenant order — dari PRD §5
- [ ] **Sequence diagram**: (a) checkout multi-tenant, (b) callback pembayaran idempoten, (c) queue preparation, (d) settlement
- [ ] **Diagram arsitektur** modular monolith + deployment satu VPS
- [ ] **Wireframe low-fidelity** 4 halaman (kiosk, checkout, tenant, operator) — cukup draw.io, tidak perlu pixel-perfect
- [ ] **Rancangan pengujian**: tabel BB (§4a) + instrumen UAT (§12.6)
- [ ] Konfirmasi hasil wawancara awal ke pengelola (Metopen masih menandai `[tanggal]`)
- [ ] Buat `docs/logbook.md` + folder `docs/evidence/{P1,P2,P3,test}/` (§12.9)

> **Catatan:** wireframe low-fi sebaiknya dibuat **sebelum** menulis komponen React — lebih murah revisi di kertas daripada di kode, dan bisa dilampirkan sebagai Gambar 3.x.

---

### Prototype 1 — Backend Gap Closure + Kiosk Pelanggan
**Target:** 4–5 hari
**Tujuan:** Sistem bisa dipakai end-to-end oleh pelanggan, dan semua gap backend tertutup

#### 1a. Backend Gap Closure (hari 1)
- [ ] **G-1** Ubah filter settlement: `whereNotNull('payment_confirmed_at')`
- [ ] **G-2** Tambah `tenant_share_cents` + `operator_share_cents` (BR-14) + `meta` total
- [ ] **G-3** `GET /checkouts/{checkout}` + route + test
- [ ] **G-4** `payment_method`: tambah kolom `checkouts.payment_method` (migration), validasi `in:mock_card,mock_qris`, simpan saat checkout & retry (keputusan D-3)
- [ ] **G-5** Seeder → `firstOrCreate` agar idempoten, **dan lengkapi jadi 13 tenant** (spesifikasi di Lampiran A)
- [ ] Test baru: settlement mencakup `in_preparation`; split 80:20 tanpa sisa; `GET /checkouts` 200 & 404
- [ ] `php artisan test` → semua hijau

#### 1b. Infrastructure (hari 1–2)
- [ ] Switch `.env` ke `pgsql`, buat database `gmfs_self_order`
- [ ] `npm run db:fresh` di Postgres
- [ ] **Smoke test Postgres** (karena test suite pakai SQLite): jalankan Alur A manual via `curl`/browser, pastikan tidak ada error driver
- [ ] Install `react-router-dom@^7`, `tailwindcss@^4`, `@tailwindcss/vite@^4`
- [ ] `vite.config.ts`: plugin Tailwind + proxy `/api` → `http://127.0.0.1:8000`
- [ ] `lib/api.ts` — fetch wrapper (base path, bearer token, error normalization)
- [ ] `lib/types.ts` — interface dari kontrak §1 + PRD §6
- [ ] `lib/format.ts` — `formatRupiah()` via `Intl.NumberFormat('id-ID')`
- [ ] `lib/storage.ts` — cart & token di localStorage
- [ ] Routing shell + 4 route kosong

#### 1c. Kiosk `/` (hari 2–3)
- [ ] `GET /catalog/tenants` → katalog per tenant
- [ ] **Navigasi 13 tenant**: daftar tenant sebagai chip/tab di atas katalog (sticky), bukan semua tenant di satu scroll panjang
- [ ] Kartu produk: nama + harga + tombol tambah
- [ ] Stepper kuantitas di keranjang
- [ ] Panel keranjang: dikelompokkan per tenant, subtotal, grand total
- [ ] **Grand total dari `POST /cart/quote`** (bukan hitung klien)
- [ ] Checkout → `POST /checkouts` → redirect `/checkout/:id`
- [ ] Keranjang persist di localStorage; tombol reset
- [ ] Touch target ≥44px, loading state, empty state, error inline

#### 1d. Checkout `/checkout/:id` (hari 3–4)
- [ ] Load via `GET /checkouts/{id}` (refresh-safe — G-3)
- [ ] Tampilkan referensi, total, item, tenant order + status badge
- [ ] `awaiting_payment`: tombol simulasi berhasil / gagal
- [ ] `paid`: konfirmasi hijau + tombol "Pesanan Baru" (reset keranjang)
- [ ] `payment_failed`: `last_error` + tombol "Coba Lagi" → `POST /retry`
- [ ] Tampilkan `attempt_count`
- [ ] Handle 404 (id tidak ada) → halaman "checkout tidak ditemukan"

#### Exit Gate Prototype 1
| # | Kriteria | Maps |
|---|---|---|
| P1-1 | Settlement menghitung order `in_preparation` | BR-13 |
| P1-2 | Split 80:20 benar & total tidak bocor 1 rupiah pun | BR-14 |
| P1-3 | `/checkout/:id` tetap tampil setelah F5 | BR-15 |
| P1-4 | Checkout 2 tenant → 2 tenant order | AC-2 |
| P1-5 | Simulasi gagal → retry → `attempt_count` naik | AC-5, AC-7 |
| P1-6 | `npm test`, `npm run build`, `npm run lint` bersih | AC-13..15 |
| P1-7 | `npm run db:fresh` sukses di PostgreSQL | AC-16 |

**Evaluasi:** jalankan Alur A + B (PRD §12) + tabel BB-01..BB-02, BB-09..BB-10 di §4.

**Gerbang Umpan Balik #1** (akhir P1, ±1 jam)
- [ ] Demo ke **pembimbing** (validasi ahli) — fokus: kesesuaian alur dengan rumusan masalah
- [ ] Opsional: 1–2 calon pengguna untuk kesan pertama UI
- [ ] Catat di `docs/feedback-1.md`: masukan → keputusan (diterima/ditunda/ditolak + alasan)
- [ ] Masukan yang diterima masuk ke P2; yang ditolak dicatat sebagai batasan

> Realistis: mengumpulkan pengelola/tenant GMFS dua kali itu sulit. Pembimbing bisa diakses cepat dan secara akademik sah sebagai **validasi ahli** pada iterasi awal. Umpan balik pengguna sesungguhnya dikumpulkan di Gerbang #2.

---

### Prototype 2 — Dashboard Tenant & Operator
**Target:** 3 hari
**Prasyarat:** exit gate P1 lolos

#### 2a. Dashboard Tenant `/tenant`
- [ ] Pilih tenant dari **dropdown 13 tenant** (memetakan ke token seed) + opsi ketik token manual
- [ ] Token persist di localStorage + tombol logout
- [ ] Dropdown hanya untuk demo — token tetap dikirim via header `Authorization`
- [ ] `GET /tenant/orders` → tabel (referensi, total, status, waktu konfirmasi, waktu queue)
- [ ] Tombol "Queue Preparation": aktif hanya saat `status === 'paid'`; disabled + alasan tertulis selain itu
- [ ] `POST /tenant/orders/:id/queue-preparation` → refresh
- [ ] 403 / 422 → alert inline
- [ ] Empty state

#### 2b. Settlement Operator `/operator`
- [ ] Input token + quick-pick `operator-token`
- [ ] Filter tanggal `from` / `to`
- [ ] Tabel: tenant, jumlah order, **bruto, bagian tenant 80%, bagian pengelola 20%**
- [ ] Baris grand total + `meta` total
- [ ] 403 → alert
- [ ] Empty state

#### 2c. Navigasi
- [ ] NavBar: `/`, `/tenant`, `/operator` + indikator halaman aktif
- [ ] Operator yang masuk `/tenant` → tombol queue **disembunyikan** (policy: operator tidak boleh queue)
- [ ] Responsif tablet landscape + mobile portrait

#### Exit Gate Prototype 2
| # | Kriteria | Maps |
|---|---|---|
| P2-1 | Tenant manager hanya lihat order tenant sendiri | AC-8 |
| P2-2 | Queue pada order `paid` → `in_preparation` | AC-4 |
| P2-3 | Queue pada order belum `paid` → disabled / 422 | AC-5 |
| P2-4 | Akses order tenant lain → 403 | AC-9 |
| P2-5 | Operator lihat settlement semua tenant + 80:20 | AC-10, BR-14 |
| P2-6 | Filter tanggal mengubah hasil | AC-10 |
| P2-7 | Token tenant di `/operator` → 403 | AC-10 |

**Evaluasi:** Alur C + D (PRD §12) + BB-06..BB-08.

**Gerbang Umpan Balik #2** (akhir P2, ±2 jam)
- [ ] Demo ke **1 pengelola + 2–3 perwakilan tenant GMFS**
- [ ] Metode: walkthrough tugas (pesan lintas tenant → lihat split → queue → cek settlement) + wawancara singkat
- [ ] Catat di `docs/feedback-2.md`: masukan → perubahan konkret untuk P3
- [ ] Ini umpan balik pengguna sesungguhnya yang diklaim di Bab III

> **Kalau GMFS tidak bisa dijadwalkan:** lakukan dengan 2–3 orang yang pernah memesan di food court (proxy user), dan **tulis apa adanya** di Bab V bahwa responden bukan pengelola/tenant asli. Jangan tulis "pengelola GMFS" kalau bukan.

---

### Prototype 3 — Polish, Edge Cases, Evaluasi Formal
**Target:** 2–3 hari

#### 3a. UX Polish
- [ ] Badge warna konsisten (kuning/hijau/merah/biru)
- [ ] Format rupiah konsisten di semua layar
- [ ] Toast/banner untuk sukses & gagal
- [ ] Konfirmasi sebelum checkout
- [ ] Accessibility: label, focus ring, kontras
- [ ] `APP_LOCALE=id` atau format tanggal Indonesia di frontend

#### 3b. Edge Cases
- [ ] Produk dinonaktifkan setelah masuk keranjang → 422 ditangani rapi
- [ ] Keranjang kosong → tombol checkout disabled
- [ ] Double-click prevention (checkout, bayar, queue)
- [ ] Checkout `paid` → tombol simulasi hilang
- [ ] Network error → banner + retry
- [ ] Settlement saat belum ada order → empty state

#### 3c. Production Build (bukan deploy VPS)
**Keputusan:** tidak menyewa VPS. Cukup production build + panduan.
- [ ] `npm run build` SPA → serve dari `backend/public` (satu origin, tanpa CORS)
- [ ] Verifikasi `php artisan serve` + build SPA jalan di satu origin
- [ ] `docs/DEPLOY.md` — panduan nginx + php-fpm + Postgres + env untuk VPS (tidak dieksekusi)
- [ ] Screenshot production mode untuk Bab IV

#### 3d. Evaluasi Formal
- [ ] Tabel black-box final (§4) + screenshot bukti
- [ ] Skenario UAT + instrumen kuesioner
- [ ] Dry-run UAT
- [ ] Catat bug + perbaikan untuk Bab V

#### Exit Gate Prototype 3
| # | Kriteria |
|---|---|
| P3-1 | Snapshot harga terbukti (AC-11) |
| P3-2 | Seed 3 tenant lengkap (AC-12) |
| P3-3 | `build` / `lint` / `test` bersih (AC-13..15) |
| P3-4 | `npm run dev` menjalankan API + SPA sekaligus (AC-16) |
| P3-5 | Full flow A→B→C→D tanpa error |
| P3-6 | Production build jalan satu origin |

---

## 4. Evaluasi Formal (Bab V)

### 4a. Black-Box

| ID | Skenario | Expected | BR |
|---|---|---|---|
| BB-01 | Tambah produk 2 tenant | Keranjang 2 tenant, subtotal benar | BR-1 |
| BB-02 | Checkout multi-tenant | 1 checkout + 2 tenant order | BR-2 |
| BB-03 | Queue sebelum bayar | Disabled / 422 | BR-3 |
| BB-04 | Queue setelah bayar | `in_preparation` | BR-3 |
| BB-05 | Event duplikat | 1 record, status tetap | BR-4 |
| BB-06 | Tenant lihat order | Hanya tenant sendiri | BR-5 |
| BB-07 | Tenant queue order lain | 403 | BR-5 |
| BB-08 | Settlement operator | Semua tenant + 80:20 benar | BR-6, 14 |
| BB-09 | Retry checkout gagal | `attempt_count` +1, status reset | BR-7 |
| BB-10 | Snapshot harga | Order lama tidak berubah | BR-8 |
| **BB-11** | Order sudah `in_preparation` | **Tetap muncul di settlement** | **BR-13** |
| **BB-12** | Gross ganjil (mis. Rp 50.005) | tenant + operator = gross, tanpa sisa | **BR-14** |
| **BB-13** | Refresh `/checkout/:id` | Data tetap tampil | **BR-15** |

> BB-11..BB-13 adalah bukti perbaikan gap G-1..G-3 — penting untuk Bab V karena menunjukkan temuan & perbaikan selama prototyping.

### 4b. UAT

| Peran | Responden | Metode |
|---|---|---|
| Pengelola GMFS | 1 | Walkthrough + kuesioner |
| Perwakilan tenant | ≥5 | Walkthrough + kuesioner |

Aspek: kemudahan pesan lintas tenant, kejelasan split order, kejelasan pembayaran, kemudahan queue, kejelasan settlement 80:20, penerimaan umum. Butir lengkap & rumus skor: §12.6.

> **Risiko UAT:** auth berbasis token statis tidak natural untuk pengelola/tenant sungguhan. Mitigasi: quick-pick token diberi label "Masuk sebagai: Noodle House", dan jelaskan di awal sesi bahwa ini mekanisme demo.

---

## 5. Struktur Frontend Target

```
frontend/src/
├── lib/          api.ts · types.ts · format.ts · storage.ts
├── components/   NavBar · StatusBadge · Money · CartPanel ·
│                 ProductCard · TenantSection · TokenInput · EmptyState
├── pages/        KioskPage · CheckoutPage · TenantDashboard · SettlementPage
├── App.tsx       router shell
├── main.tsx
└── index.css     Tailwind import
```

Backend: hanya penambahan G-1..G-5, struktur tidak berubah.

---

## 6. Dependency Budget (Frontend)

| Package | Versi | Alasan |
|---|---|---|
| react / react-dom | ^19.2.8 | sudah ada |
| react-router-dom | ^7 | routing (PRD §2) |
| tailwindcss + @tailwindcss/vite | ^4 | styling (PRD §2) |

**Tidak ditambah:** state management, UI kit, form library, data-fetching library, test runner frontend.
> Keputusan sadar: verifikasi frontend lewat black-box manual + UAT, bukan unit test FE. Alasan: skala 4 halaman, dan metode evaluasi TA adalah black-box + UAT.

---

## 7. Risiko & Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Migrasi MySQL→Postgres ada syntax beda | Gagal migrate | Test suite pakai SQLite, jadi wajib smoke test manual di Postgres (1b) |
| Bug settlement G-1 lolos ke UAT | Data bagi hasil salah | Test BB-11 wajib sebelum P2 |
| Pembulatan 80:20 dipersoalkan penguji | Revisi Bab III | Aturan `intdiv` + sisa ke operator didokumentasikan eksplisit (BR-14) |
| Tailwind v4 beda konfigurasi dari v3 | Styling gagal | Pakai `@tailwindcss/vite`, config via CSS |
| UAT sulit dijadwalkan | TA tertunda | Siapkan build deployable + video walkthrough sebagai fallback |
| Token statis membingungkan responden UAT | Data UAT bias | Dropdown "Masuk sebagai" berisi 13 nama tenant + penjelasan pembuka bahwa ini mekanisme demo |

---

## 8. Timeline

```
Minggu 0  Fase 0 — Analisis & Perancangan (bahan Bab III)
          hari 1-2   : FR/NFR, use case, ERD, state machine,
                       sequence diagram, wireframe, rancangan uji

Minggu 1  Prototype 1
          hari 1     : G-1..G-5 + test  ⟵ backend dulu, jangan ke FE sebelum ini hijau
          hari 1-2   : Postgres + deps + lib + routing
          hari 2-3   : Kiosk
          hari 3-4   : Checkout
          hari 5     : evaluasi + prototype-1-review.md
                       + Gerbang Umpan Balik #1 (pembimbing)

Minggu 2  Prototype 2
          hari 1-2   : Dashboard tenant
          hari 2-3   : Settlement operator (80:20)
          hari 3     : NavBar + responsive
          hari 4     : Gerbang Umpan Balik #2 (pengelola + tenant)
          hari 4     : prototype-2-review.md + feedback-2.md

Minggu 3  Prototype 3
          hari 1-2   : Polish + edge cases (dari masukan feedback-2)
          hari 2-3   : Production build + DEPLOY.md
          hari 3     : Black-box formal + dry-run UAT
          hari 3     : prototype-3-review.md

Minggu 4  UAT sesungguhnya + penulisan Bab IV & V
```

> Total ±3,5 minggu kerja efektif + 1 minggu penulisan. Sisakan buffer: hal yang paling sering molor adalah penjadwalan responden, bukan coding.

---

## 9. Urutan Eksekusi Berikutnya

1. **Fase 0 — Analisis & Perancangan** (FR/NFR, diagram, wireframe). Sekaligus buat `docs/logbook.md` dan folder `docs/evidence/`
2. **Backend dulu (G-1..G-5) + test** — jangan sentuh frontend sebelum `php artisan test` hijau dengan gap tertutup
3. Postgres + verifikasi `db:fresh`
4. Install deps frontend + Vite config
5. `lib/*` + routing shell
6. Halaman kiosk → checkout
7. Evaluasi P1 + Gerbang Umpan Balik #1

---

## 10. Update PRD yang Diperlukan

| Lokasi | Perubahan |
|---|---|
| §2 Stack | MySQL 8.4 → PostgreSQL |
| §6.6 Settlement | Tambah `gross_cents`, `tenant_share_cents`, `operator_share_cents`, `meta` |
| §6.3 | Tambah `GET /checkouts/{checkout}`; `payment_method` disimpan di kolom sendiri |
| §9 Data Seed | 3 tenant → **13 tenant** (Lampiran A), `firstOrCreate` idempoten |
| §7 | Tambah BR-13, BR-14, BR-15 |
| §10 Test coverage | 6 → 10+ test |
| §16 | Tandai "Future Work / di luar batasan penelitian" |
| §11 AC | Tambah AC untuk settlement 80:20 & refresh-safe checkout |

---

## 11. Keputusan yang Sudah Diambil

| # | Keputusan | Alasan |
|---|---|---|
| D-1 | **Seed 13 tenant** — sesuai Metopen §I.1 | Konsisten dengan narasi Bab I; UAT & screenshot lebih meyakinkan |
| D-2 | **Production build + panduan**, tanpa sewa VPS | Hemat waktu; Metopen hanya menyebut target deployment, tidak mewajibkan |
| D-3 | **`payment_method` disimpan** di `checkouts.payment_method`, validasi `in:mock_card,mock_qris` | PRD §6.3 sudah mencantumkannya; menyimpan lebih jujur daripada mengabaikan, dan siap untuk gateway nyata |

---

## 12. Rencana Pelaporan — Ini Inti Proyek

> Sistem adalah **alat**; laporan adalah **produk**. Kode yang bagus tapi tidak terdokumentasi = nilai rendah. Bagian ini memastikan setiap jam kerja menghasilkan sesuatu yang bisa dikutip di Bab III–V.

### 12.1 Tiga Aturan Dasar

1. **Tidak ada bukti = tidak bisa ditulis.** Screenshot, log test, catatan feedback harus diambil *saat itu*, bukan direkonstruksi setelahnya.
2. **Setiap klaim harus punya sumber di dokumen.** Kalau masuk Bab V, harus ada artefak yang mendukungnya.
3. **Jangan melebih-lebihkan.** Lebih baik laporan jujur dengan 13 skenario uji daripada klaim besar tanpa bukti. Penguji biasanya menyerang klaim, bukan kekurangan yang diakui.

---

### 12.2 Kebutuhan Fungsional (bahan Bab III)

| ID | Kebutuhan | Sumber | Diuji oleh |
|---|---|---|---|
| FR-01 | Pelanggan dapat melihat katalog produk lintas tenant | BR-1 | BB-01 |
| FR-02 | Pelanggan dapat menambahkan produk dari >1 tenant ke satu keranjang | BR-1 | BB-01 |
| FR-03 | Sistem menghitung subtotal per tenant dan grand total di sisi server | BR-1 | BB-01 |
| FR-04 | Satu checkout dipecah menjadi N pesanan tenant secara otomatis | BR-2 | BB-02 |
| FR-05 | Sistem menyimpan snapshot nama & harga produk saat checkout | BR-8 | BB-10 |
| FR-06 | Sistem menampilkan status pembayaran per pesanan tenant | BR-3 | BB-04 |
| FR-07 | Sistem memproses notifikasi pembayaran secara idempoten | BR-4 | BB-05 |
| FR-08 | Sistem menolak antrean persiapan sebelum pembayaran terkonfirmasi | BR-3 | BB-03 |
| FR-09 | Checkout yang gagal dapat diulang tanpa membuat checkout baru | BR-7 | BB-09 |
| FR-10 | Manajer tenant hanya dapat mengakses pesanan tenantnya | BR-5 | BB-06, BB-07 |
| FR-11 | Operator dapat melihat ringkasan lintas tenant | BR-6 | BB-08 |
| FR-12 | Sistem menghitung bagi hasil 80:20 per tenant | BR-6, 14 | BB-08, BB-12 |
| FR-13 | Detail checkout dapat diakses ulang kapan saja | BR-15 | BB-13 |
| FR-14 | Sistem mengautentikasi pengguna berdasarkan peran | — | BB-06, BB-07, BB-08 |

### 12.3 Kebutuhan Non-Fungsional

| ID | Kebutuhan | Cara verifikasi |
|---|---|---|
| NFR-01 | Antarmuka responsif pada tablet landscape dan mobile portrait | Inspeksi + UAT |
| NFR-02 | Target sentuh minimal 44×44 px | Inspeksi |
| NFR-03 | Nilai uang ditampilkan dalam format Rupiah | Inspeksi |
| NFR-04 | Split order bersifat atomik (satu transaksi basis data) | Kode + test |
| NFR-05 | Notifikasi pembayaran duplikat tidak mengubah state | BB-05 |
| NFR-06 | Build, lint, dan test bersih | AC-13..15 |
| NFR-07 | Sistem berjalan sebagai satu unit deploy | Production build |
| NFR-08 | Antarmuka berbahasa Indonesia | Inspeksi |

> NFR sengaja dibatasi pada yang **bisa diverifikasi** sesuai batasan penelitian (tanpa load test / security test). Menulis NFR performa yang tidak diuji justru menciptakan celah saat sidang.

---

### 12.4 Matriks Bukti per Bab

**Bab III — Metodologi**

| Artefak | Dihasilkan di | Bentuk |
|---|---|---|
| Model prototyping (diagram siklus) | Fase 0 | Gambar |
| Kebutuhan fungsional & non-fungsional | Fase 0 | Tabel 12.2 / 12.3 |
| Use case diagram + skenario | Fase 0 | Gambar + tabel |
| ERD | Fase 0 | Gambar |
| State machine checkout & tenant order | Fase 0 | Gambar |
| Sequence diagram (4 alur) | Fase 0 | Gambar |
| Arsitektur & deployment | Fase 0 | Gambar |
| Wireframe antarmuka | Fase 0 | Gambar |
| Rancangan pengujian black-box | Fase 0 | Tabel BB |
| Instrumen UAT | Fase 0 / sebelum UAT | Kuesioner |

**Bab IV — Hasil**

| Artefak | Dihasilkan di | Bentuk |
|---|---|---|
| Log iterasi 1–3 | tiap prototype | `prototype-N-review.md` |
| Log umpan balik 1–2 | Gerbang #1, #2 | `feedback-N.md` |
| Screenshot tiap halaman | P1, P2 | `docs/evidence/P*/` |
| Statistik kode (endpoint, test, LOC) | akhir P3 | Tabel |
| Lingkungan implementasi (versi PHP, Laravel, Node, Postgres, browser) | akhir P3 | Tabel |
| Data uji (13 tenant) | P1 | Tabel Lampiran A |
| Arsitektur deployment produksi | P3 | Gambar |

**Bab V — Validasi & Diskusi**

| Artefak | Dihasilkan di | Bentuk |
|---|---|---|
| Hasil black-box (13 skenario, aktual vs harapan) | P3 | Tabel |
| Hasil UAT (skor per aspek, interpretasi) | Minggu 4 | Tabel + grafik |
| Matriks keterlacakan RM → Tujuan → FR → BB → Hasil | P3 | Tabel §12.5 |
| Perbandingan dengan penelitian terdahulu | Minggu 4 | Tabel §12.7 |
| Daftar bug ditemukan & diperbaiki per iterasi | tiap prototype | Tabel |
| Keterbatasan | Minggu 4 | Narasi §12.10 |

---

### 12.5 Matriks Keterlacakan (paling sering ditanya penguji)

Tabel ini menjawab satu pertanyaan: **"mana bukti tiap tujuan penelitian tercapai?"**

| Rumusan Masalah | Tujuan | Kebutuhan | Skenario Uji | Bukti |
|---|---|---|---|---|
| RM-1: rancang bangun sistem multi-tenant (keranjang lintas tenant, split order, pembayaran terpusat, bagi hasil 80:20) | T-1 | FR-01..FR-14 | BB-01..BB-13 | Bab IV (implementasi) + Tabel V.x (black-box) |
| RM-2: evaluasi fungsionalitas & penerimaan pengguna | T-2 | NFR-01..NFR-08 | BB + UAT | Tabel V.y (black-box) + Tabel V.z (UAT) |

> Buat tabel ini **sekarang**, isi kolom bukti sambil jalan. Kalau diisi di akhir, pasti ada sel kosong yang bikin panik H-3.

---

### 12.6 Instrumen UAT

**Responden (purposive sampling):**

| Kelompok | Jumlah | Kriteria |
|---|---|---|
| Pengelola food court | 1 | Terlibat langsung pada operasional GMFS |
| Perwakilan tenant | ≥5 | Pengelola/karyawan tenant yang menerima pesanan |
| **Total** | **≥6** | — |

**Skala:** Likert 1–5 (1 = sangat tidak setuju, 5 = sangat setuju).

**Butir pernyataan (draf awal — finalkan dengan pembimbing):**

| Aspek | Butir | Menguji |
|---|---|---|
| A. Pemesanan lintas tenant | Saya dapat memesan dari beberapa tenant dalam satu kali transaksi | FR-01, FR-02 |
| | Total per tenant dan total keseluruhan mudah dipahami | FR-03 |
| B. Kejelasan split order | Saya dapat melihat pesanan mana milik tenant mana | FR-04, FR-06 |
| C. Pembayaran | Status pembayaran jelas dan mudah dimengerti | FR-06, FR-07 |
| | Proses mencoba ulang saat pembayaran gagal mudah dilakukan | FR-09 |
| D. Dasbor tenant | Saya dapat menemukan pesanan tenant saya dengan mudah | FR-10 |
| | Tombol antrean persiapan hanya aktif saat pesanan sudah dibayar | FR-08 |
| E. Settlement operator | Ringkasan penjualan per tenant mudah dibaca | FR-11 |
| | Perhitungan bagi hasil 80:20 jelas dan dapat dipercaya | FR-12 |
| F. Penerimaan umum | Sistem ini bermanfaat dan layak digunakan di food court | keseluruhan |

**Rumus skor:**
```
Persentase = (Σ skor / (5 × jumlah butir × jumlah responden)) × 100
```
Interpretasi: 81–100 Sangat Baik · 61–80 Baik · 41–60 Cukup · 21–40 Kurang · 0–20 Sangat Kurang.

**Tambahan:** 2–3 pertanyaan terbuka ("bagian mana yang paling membingungkan?", "apa yang ingin ditambahkan?"). Jawaban terbuka inilah yang menjadi bahan revisi P3 dan pembahasan di Bab V — bukan angkanya.

> **Wajib:** validasi instrumen ke pembimbing **sebelum** UAT. Mengubah kuesioner setelah data terkumpul = data tidak sah.

---

### 12.7 Perbandingan dengan Penelitian Terdahulu (bahan Bab V)

Tabel ini yang membuktikan klaim kebaruan di Metopen: "belum ditemukan pembahasan yang mencakup multi-tenant + split order + pembayaran terpusat + bagi hasil secara terpadu".

| Studi | Multi-tenant | Split order | Pembayaran terpusat | Bagi hasil | Catatan |
|---|---|---|---|---|---|
| Anggreainy et al. (2021) | ? | ? | ? | ? | food court |
| Ibrahim et al. (2024) | ? | ? | ? | ? | food court |
| Falah (2025) | ? | ? | ? | ? | food court |
| Benny et al. (2022) | ? | ? | ? | ? | + payment gateway |
| Fikri et al. (2024) | ? | ? | ? | ? | + payment gateway |
| Lokeshnath et al. (2025) | ? | ? | ? | ? | + payment gateway |
| **Penelitian ini** | ✅ | ✅ | ✅ | ✅ (80:20) | — |

> 🔴 **Jangan isi kolom "?" dari ingatan.** Setiap sel harus dibaca dari naskah aslinya. AGENTS.md melarang mengarang sitasi, dan tabel ini adalah tempat paling mudah ketahuan kalau dikarang. Tandai sebagai task riset ±2 jam.

---

### 12.8 Metrik yang Bisa Dilaporkan (angka konkret untuk Bab IV)

| Metrik | Sekarang | Target akhir |
|---|---|---|
| Endpoint API | 10 | ~15 (setelah G-1..G-4) |
| Test otomatis / assertion | 6 / 19 | ~14 / ~45 |
| Kebutuhan fungsional | — | 14 |
| Kebutuhan non-fungsional | — | 8 |
| Skenario black-box | — | 13 |
| Halaman antarmuka | 0 | 4 |
| Tenant pada data uji | 3 | 13 |
| Responden UAT | — | ≥6 |
| Bug ditemukan & diperbaiki | — | dicatat per iterasi |

Metrik ini murah didapat dan membuat Bab IV terasa terukur, bukan hanya narasi.

---

### 12.9 Disiplin Pencatatan (lakukan dari hari pertama)

**1. Logbook harian — `docs/logbook.md`**
Format satu baris per entri:
```
2026-10-28 | P1a | Perbaiki filter settlement (G-1) | Kendala: test lama asumsi status=paid | Bukti: docs/evidence/test/20261028-settlement.txt
```
Ini penyelamat saat menulis Bab IV. Merekonstruksi 3 minggu kerja dari ingatan itu tidak mungkin.

**2. Penamaan screenshot** `docs/evidence/P{1,2,3}/YYYYMMDD-hhmm-{halaman}.png`
Contoh: `20261102-1430-kiosk-keranjang-2tenant.png`
Nomor Gambar 4.x diberikan nanti saat menulis — jangan sekarang.

**3. Simpan output mentah**
`npm test`, `npm run build`, `npm run lint` disimpan ke `docs/evidence/test/`. Di Bab V cukup dikutip, tidak perlu dijalankan ulang saat sidang.

**4. Commit per task**, pesan menyebut iterasi (`P1a: fix settlement BR-13`). Riwayat git jadi bukti tambahan bahwa prototyping benar-benar iteratif.

**5. Screenshot diambil saat fitur selesai**, bukan setelah semua selesai.

---

### 12.10 Klaim yang TIDAK Boleh Dibuat

| Jangan klaim | Alasan |
|---|---|
| "Mengurangi waktu tunggu X%" | Tidak ada pengukuran sebelum-sesudah |
| "Akurat untuk rekomendasi / precision@k" | Fitur rekomendasi di luar scope (Metopen §I.4.7) |
| "Aman dari serangan / lolos pentest" | Security test tidak dilakukan (§I.4.8) |
| "Mampu menangani N pengguna bersamaan" | Load test tidak dilakukan (§I.4.8) |
| "Terintegrasi QRIS/Midtrans" | Hanya mock adapter (§I.4.4) |
| "Data 13 tenant adalah data GMFS sebenarnya" | Seed bersifat fiktif/representatif — tulis "data uji representatif" |
| "Sudah diuji pada perangkat kiosk fisik" | Pengujian via browser desktop/tablet (§I.4.8) |

> Akui semuanya di bagian Keterbatasan. Keterbatasan yang ditulis sendiri jauh lebih aman daripada ditemukan penguji.

---

### 12.11 Yang Perlu Diubah di Dokumen Lain

| Dokumen | Perubahan |
|---|---|
| `AGENTS.md` | Hapus klaim `precision@k` (rekomendasi out of scope) — jangan sampai masuk laporan |
| `docs/PRD.md` §16 | Tandai "Future Work — di luar batasan penelitian" |
| `Metopen.md` §I.4.7 | Pastikan tetap konsisten: rekomendasi = pengembangan lanjutan |
| `Metopen.md` §I.1 | Isi placeholder `[tanggal]` wawancara dan `[pengelola dan perwakilan tenant]` |

---

## Lampiran A — Spesifikasi Seed 13 Tenant

Semua dibuat dengan `firstOrCreate` agar **idempoten** (G-5). Setiap tenant: 1 outlet, 2 produk, 1 user `tenant_manager` dengan email `{slug}@example.com` dan token `{slug}-token`. Plus 1 operator (`operator-token`). Total 14 user.

| # | Tenant | Slug | Produk (harga) |
|---|---|---|---|
| 1 | Noodle House | `noodle-house` | Beef Noodle (50.000), Chicken Noodle (45.000) |
| 2 | Burger Point | `burger-point` | Classic Burger (55.000), Cheese Burger (60.000) |
| 3 | Fresh Bowl | `fresh-bowl` | Salad Bowl (48.000), Fruit Bowl (35.000) |
| 4 | Bakso Malang Pak Slamet | `bakso-malang` | Bakso Urat (30.000), Mie Ayam Bakso (32.000) |
| 5 | Ayam Geprek Bu Rina | `ayam-geprek` | Geprek Original (25.000), Geprek Keju (32.000) |
| 6 | Sate Padang Minang | `sate-padang` | Sate Padang (35.000), Lontong Sate (38.000) |
| 7 | Nasi Goreng Kampung | `nasi-goreng` | Nasi Goreng Spesial (30.000), Nasi Goreng Seafood (38.000) |
| 8 | Soto Betawi H. Mamat | `soto-betawi` | Soto Betawi (35.000), Nasi + Soto (40.000) |
| 9 | Martabak Bangka 99 | `martabak` | Martabak Manis Cokelat (45.000), Martabak Telur (42.000) |
| 10 | Siomay Batagor Bandung | `siomay-bandung` | Siomay (25.000), Batagor (27.000) |
| 11 | Es Teler Segar | `es-teler` | Es Teler (22.000), Es Campur (20.000) |
| 12 | Kopi Kita | `kopi-kita` | Kopi Susu Gula Aren (18.000), Americano (20.000) |
| 13 | Nasi Padang Sari Rasa | `nasi-padang` | Nasi Padang Ayam (35.000), Nasi Padang Rendang (42.000) |

**Catatan:**
- Nama tenant dan produk fiktif/representatif — hindari merek nyata.
- Nama tenant wajib sama dengan yang dirujuk di Metopen bila ada (metode wawancara), jika tidak gunakan daftar ini.
- Test suite membuat datanya sendiri (`createTwoTenantProducts`), jadi penambahan seed **tidak** mengubah 6 test yang ada.
- Katalog dengan 13 tenant × 2 produk = 26 kartu → kiosk butuh pengelompokan/filter per tenant agar tidak overwhelming (task 1c).
