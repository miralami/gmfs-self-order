# AGENTS.md — GMFS Self-Order

Kiosk pemesanan mandiri berbasis web untuk **food court multi-tenant**. Studi kasus: **Gajah Mada Food Street (GMFS)**, Jakarta (13 tenant, sering untuk event).
Detail lengkap ada di **`docs/PRD.md`**; **rencana kerja & pelaporan ada di `docs/PLAN.md`** (baca ini dulu sebelum eksekusi); naskah Bab 1 TA di **`docs/latbel.md`**.

## Masalah & Solusi
- **Masalah:** pemesanan & pembayaran masih terpisah tiap tenant → antrean menumpuk, pelanggan transaksi berulang, rekapitulasi & settlement manual.
- **Solusi:** satu keranjang lintas tenant, checkout & bayar sekali, split order otomatis, settlement per tenant + bagi hasil 80:20.

## Konteks Akademik
- TA **S1 Sistem Informasi, Universitas Telkom**. Pendekatan **R&D + Prototyping**; evaluasi **black-box + UAT**.
- **Kebaruan:** (1) multi-tenant + split order, (2) pembayaran terpusat + settlement bagi hasil 80:20, (3) digitalisasi UMKM food court.
- **Di luar batasan TA:** fitur rekomendasi cerdas (desain lama PRD §16 = *future work*, jangan diklaim di laporan).
- **Sitasi:** draf APA 7; template kampus minta **IEEE** → konfirmasi pembimbing.
- **Judul:** "Perancangan Sistem Self-Order Terpusat Berbasis Web untuk Food Court Multi-Tenant (Studi Kasus: Gajah Mada Food Street)".
- **Format:** TNR 12 pt, justify, spasi 1,5; judul dua bahasa; min 5 ref ber-ISSN/ISBN.

## Aturan Bisnis (detail: PRD §7)
BR-1 keranjang lintas tenant · BR-2 satu checkout → N `tenant_order` · BR-3 queue-prep hanya saat `paid` & `payment_confirmed_at` terisi · BR-4 event pembayaran idempotent by `event_id` · BR-5 isolasi tenant · BR-6 settlement operator · BR-7 retry checkout gagal · BR-8 snapshot harga · **BR-13** settlement menghitung semua order yang `payment_confirmed_at` terisi · **BR-14** bagi hasil 80:20 tanpa kehilangan rupiah · **BR-15** detail checkout refresh-safe. · BR-9…12 = rekomendasi, **di luar scope**.

## Stack
Laravel 13 (PHP 8.5) API + React 19/TypeScript/Vite SPA · *modular monolith* · **PostgreSQL** · pembayaran **mock adapter** (tanpa gateway nyata) · target satu VPS.

## Perintah
`npm run dev` · `npm test` · `npm run lint` · `npm run build` · `npm run db:fresh`

## Konvensi
Logic di `app/Services`, validasi di Form Request, otorisasi di Policy · uang = rupiah utuh di kolom `*_cents` · UI Bahasa Indonesia · perubahan split order/pembayaran/otorisasi wajib update `CheckoutFlowTest`.

## Git
Commit setiap unit kerja selesai (satu task dari `docs/PLAN.md`), jangan menumpuk sampai akhir. Commit kecil itu murah, dan jadi bukti iterasi untuk Bab IV.

- **Kapan:** setelah test hijau · sebelum refactor berisiko, install dependency, ganti DB, atau hapus file.
- **Format:** `P1a: perbaiki filter settlement (BR-13)` — sebut iterasi + BR bila relevan. Prefix: `F0:` `P1:` `P2:` `P3:` `docs:` `fix:` `test:`.
- **Sertakan dokumen bareng kode** (`docs/logbook.md`, review note) supaya bukti ikut tersimpan, bukan cuma kode.
- **Jangan commit:** `.env`, `vendor/`, `node_modules/`, `dist/`, cache/storage.
- **Ragu? commit.** Selama bukan secret, commit kecil lebih baik daripada kehilangan pekerjaan.

## Status
Backend MVP ✅ · Frontend masih scaffold Vite 🟡 · gateway nyata ⬜ · rekomendasi cerdas ⬜ (di luar batasan TA) · gap backend G-1..G-6 ⬜ (PLAN §0.2)

## Jangan
Jangan rusak invarian BR-2/3/4 tanpa test · jangan hitung total di klien (server via `POST /cart/quote`) · jangan tambah microservice/broker/WebSocket · jangan karang sitasi.

## TODO
Ikuti `docs/PLAN.md`: Fase 0 (perancangan) → Prototype 1 (gap G-1..G-6 + kiosk) → Prototype 2 (dashboard) → Prototype 3 (polish + evaluasi). Di luar itu masih terbuka: verifikasi lokasi GMFS dan finalisasi gaya sitasi.
