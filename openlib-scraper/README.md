# OpenLib Telkom Scraper — Bab 1 & Bab 2 (Lokal)

Script ini jalan **di komputer kamu**. Kamu yang login SSO, dan kredensial tidak pernah keluar dari mesin ini.

## Alur otomatis

1. Browser Chromium terbuka → kamu login SSO sekali (MFA/OTP boleh).
2. Script **mendeteksi login otomatis**, lalu lanjut sendiri:
   - buka `Catalog`
   - klik **Advanced**
   - isi **Penerbit = "Kampus Jakarta"**
   - telusuri **semua halaman hasil** (filter tetap terbawa via session)
   - buka tiap detail dokumen, ambil **BAB 1** dan **BAB 2**
   - download PDF-nya ke folder tujuan
3. Login disimpan di profil lokal `.profile/`, jadi run berikutnya tidak perlu login lagi (sampai sesi kedaluwarsa).

## Cara pakai

```bash
cd openlib-scraper
npm install
npm run install-browsers
npm run start
```

Login di browser yang muncul. Setelah itu semua berjalan otomatis.

## Output

Default: `C:\Users\Afif\OneDrive - Telkom University\TUGAS AKHIR\openlib-download\`

```
openlib-download/
├── _index.md              # daftar semua dokumen + link PDF
├── _manifest.json         # status per dokumen (untuk resume)
├── 253999_pengembangan-aplikasi-manajemen-distribusi-tiket/
│   ├── 26.04.5045_bab1.pdf
│   ├── 26.04.5045_bab2.pdf
│   └── metadata.md
└── ...
```

Kalau script dihentikan di tengah jalan, jalankan lagi — dokumen yang sudah lengkap otomatis dilewati (resume).

## Konfigurasi (opsional, lewat environment variable)

| Variabel | Default | Fungsi |
|---|---|---|
| `OPENLIB_PUBLISHER` | `Kampus Jakarta` | nilai filter Penerbit |
| `OPENLIB_OUT` | `...\TUGAS AKHIR\openlib-download` | folder output |
| `OPENLIB_MAX_PAGES` | `0` (semua) | batasi jumlah halaman listing |
| `OPENLIB_MAX_ITEMS` | `0` (semua) | batasi jumlah dokumen |
| `OPENLIB_DELAY_MS` | `400` | jeda antar request (biar sopan) |

Contoh uji coba 1 halaman:

```powershell
$env:OPENLIB_MAX_PAGES=1; npm run start
```

## Catatan teknis

- Link Bab 1/2 di halaman detail berbentuk `/pustaka/files/{id}/bab1/...pdf`, yang di server di-redirect ke halaman flipbook.
- Halaman flipbook menyimpan URL PDF asli di JS (`pdfUrl: "/open/index.php/download/flippingbook_url_download/..."`).
- Script mengambil URL itu lalu fetch dari dalam halaman (membawa cookie sesi), jadi hasilnya PDF asli, bukan screenshot viewer.
- Nama file mengikuti nama asli dari server (mis. `26.04.5045_bab1.pdf`).

## Keamanan

- Tidak ada username/password/token yang dikirim ke mana pun.
- `.profile/` hanya berisi cookie sesi lokal, sudah masuk `.gitignore`.
- Hapus sesi kapan saja: `npm run reset-profile`.
- Gunakan hanya untuk keperluan yang diizinkan oleh kebijakan kampus.
