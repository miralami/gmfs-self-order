# OpenLib Telkom Scraper — Bab 1 & Bab 2 (Lokal)

Script ini jalan **di komputer kamu**. Kamu yang login SSO, dan kredensial tidak pernah keluar dari mesin ini.

## Alur otomatis

1. Kalau belum ada sesi: browser Chromium terbuka (terlihat) → kamu login SSO sekali (MFA/OTP boleh).
2. Begitu login terdeteksi, script **menyimpan sesi, menutup browser, lalu lanjut headless** (tanpa jendela) dan jalan sendiri:
   - buka `Catalog`
   - klik **Advanced**
   - isi **Penerbit = "Kampus Jakarta"**
   - telusuri **semua halaman hasil** (filter tetap terbawa via session)
   - buka tiap detail dokumen, ambil **BAB 1** dan **BAB 2**
   - download PDF-nya ke folder tujuan
3. Run berikutnya langsung headless dari awal (sesi tersimpan di `.profile/`) sampai sesi kedaluwarsa.

Mau lihat browsernya terus (buat debug)? Pakai `--headed`.

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

## Pause & lanjut di urutan tertentu

Urutan dokumen **disimpan permanen** di `_order.json` (dibuat dari hasil crawl pertama), jadi nomor urut tidak berubah antar-run.

```powershell
# Mulai dari urutan 51 sampai 100
npm start -- --from 51 --to 100

# Lanjut dari urutan 101 sampai habis
npm start -- --from 101

# Proses 20 item mulai urutan 51
npm start -- --from 51 --items 20
```

**Pause:** tekan `Ctrl+C` sekali. Script menyelesaikan item yang sedang berjalan, menyimpan manifest, lalu menampilkan nomor lanjutannya:

```
[PAUSE] Berhenti di urutan 73 dari 297.
[PAUSE] Lanjut nanti dengan perintah:
        npm start -- --from 74
```

**Cek posisi tanpa mengunduh:** angka urutan ada di kolom `No` pada `_index.md`, dan total ada di `_order.json`.

**Kalau daftar di situs berubah** (ada koleksi baru), crawl ulang urutannya:

```powershell
npm start -- --refresh --from 1
```

## Opsi CLI

| Argumen | Default | Fungsi |
|---|---|---|
| `--from N` | `1` | mulai dari urutan ke-N (1-based) |
| `--to N` | akhir | berhenti di urutan ke-N |
| `--items N` | semua | batasi jumlah item yang diproses |
| `--pages N` | semua | batasi halaman listing saat crawl |
| `--publisher "..."` | `Kampus Jakarta` | nilai filter Penerbit |
| `--out "D:\path"` | folder OneDrive | folder output |
| `--delay 400` | `400` | jeda antar request (ms) |
| `--refresh` | – | crawl ulang daftar, abaikan `_order.json` |
| `--headed` | – | jangan pindah headless; browser tetap terlihat |

Semua opsi juga bisa lewat environment variable (`OPENLIB_FROM`, `OPENLIB_TO`, `OPENLIB_MAX_ITEMS`, `OPENLIB_MAX_PAGES`, `OPENLIB_PUBLISHER`, `OPENLIB_OUT`, `OPENLIB_DELAY_MS`, `OPENLIB_REFRESH`).

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
