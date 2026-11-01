# PROPOSAL TUGAS AKHIR

**Judul (Bahasa Indonesia)**
Perancangan Sistem *Self-Order* Terpusat Berbasis Web untuk *Food Court* Multi-Tenant (Studi Kasus: Gajah Mada Food Street)
*(Font: Times New Roman, 16 pt, Center, Bold, Kapital)*

**Judul (Bahasa Inggris)**
Design of a Web-Based Centralized Self-Order System for Multi-Tenant Food Courts (Case Study: Gajah Mada Food Street)
*(Font: Times New Roman, 16 pt, Center, Bold, Italic, Kapital)*

> Catatan format mengikuti `Metopen Afif.docx` (Universitas Telkom): Times New Roman 12 pt, justify, spasi 1,5; kertas A4; margin kiri 4 cm, kanan/atas/bawah 3 cm.

---

# BAB 1 PENDAHULUAN

*(Font: Times New Roman, 14 pt, Center, Bold, Kapital)*

## 1.1 Latar Belakang

*(Font: Times New Roman, 12 pt, Center, Bold, Kapital)*

Transformasi digital telah mengubah cara pelaku usaha menjalankan bisnis, mulai dari proses pemasaran, transaksi, hingga pengelolaan data operasional. Di Indonesia, Usaha Mikro, Kecil, dan Menengah (UMKM) menjadi tulang punggung perekonomian nasional dengan kontribusi sekitar 61% terhadap Produk Domestik Bruto (PDB) serta kemampuan menyerap sekitar 97% dari total tenaga kerja, sementara jumlah unit usahanya mencapai lebih dari 64 juta atau sekitar 99% dari keseluruhan unit usaha (Kementerian Koordinator Bidang Perekonomian Republik Indonesia, 2023). Meskipun perannya sangat besar, tingkat pemanfaatan teknologi digital di kalangan UMKM masih belum merata. Banyak pelaku usaha, terutama pada skala mikro dan kecil, masih mengandalkan pencatatan dan transaksi manual sehingga kurang efisien, rentan terhadap kesalahan, dan sulit dikembangkan. Kondisi ini menjadikan digitalisasi UMKM bukan hanya agenda kebijakan, tetapi juga ruang terbuka bagi perancangan solusi teknologi yang terjangkau, mudah digunakan, dan sesuai dengan kebutuhan nyata di lapangan.

Sektor makanan dan minuman (mamin) merupakan salah satu penggerak utama UMKM dan industri pengolahan. Industri makanan dan minuman menyumbang sekitar 37,77% terhadap PDB industri pengolahan nonmigas sehingga menjadikannya subsektor strategis (Kementerian Perindustrian Republik Indonesia, 2023). Pada ranah ritel dan jasa kuliner, salah satu model usaha yang dijumpai adalah *food court* atau pusat jajanan, yaitu suatu lokasi yang menghimpun banyak pelaku usaha kuliner (*tenant*) dalam satu ruang bersama. Model ini menguntungkan karena pelanggan memperoleh banyak pilihan dalam satu kali kunjungan, sedangkan para *tenant* dapat berbagi fasilitas dan lalu lintas pengunjung. Namun, di balik kemudahan tersebut muncul kompleksitas operasional: setiap *tenant* umumnya mengelola pemesanan dan pembayarannya secara mandiri, sehingga pengalaman pelanggan menjadi terpecah dan pengelola *food court* mengalami kesulitan untuk melakukan rekapitulasi penjualan maupun perhitungan bagi hasil.

Perkembangan ini sejalan dengan makin luasnya penerapan teknologi layanan mandiri. *Self-service technology* (SST) didefinisikan sebagai teknologi yang memungkinkan pelanggan menghasilkan suatu layanan secara mandiri tanpa interaksi langsung dengan pegawai (Meuter et al., 2000). Penelitian menunjukkan bahwa adopsi SST dipengaruhi oleh kemudahan penggunaan, keandalan, dan manfaat yang dirasakan pengguna, serta dapat meningkatkan kepuasan dan efisiensi layanan apabila dirancang dengan baik (Bitner et al., 2002). Salah satu bentuk SST yang tumbuh pesat di industri makanan dan minuman adalah *self-order kiosk*, yaitu terminal pemesanan mandiri yang memungkinkan pelanggan memilih menu, memesan, dan membayar sendiri (Rastegar et al., 2021).

Pada studi yang ditinjau, penerapan *self-order kiosk* pada layanan makanan dilaporkan mempercepat proses pemesanan, mengurangi panjang antrean, menurunkan potensi kesalahan pencatatan pesanan, dan meningkatkan kepuasan pelanggan (Rastegar et al., 2021; Akshaya et al., 2025). Meskipun demikian, solusi kiosk dan sistem pemesanan mandiri yang tersedia umumnya dirancang untuk satu gerai (*single-tenant*), dengan asumsi satu katalog menu, satu kasir, dan satu rekening penerimaan. Padahal, *food court* pada dasarnya merupakan lingkungan *multi-tenant* yang menuntut kemampuan melayani banyak pelaku usaha sekaligus. Kesenjangan antara karakteristik *food court* dan rancangan sistem pemesanan yang berorientasi satu gerai tersebut menjadi titik persoalan utama penelitian ini.

Konsep *multi-tenant* merujuk pada arsitektur perangkat lunak di mana satu instans aplikasi melayani banyak penyewa (*tenant*) sekaligus, dengan tetap menjaga isolasi data di antara penyewa (Bezemer & Zaidman, 2010; Kabbedijk et al., 2015). Tantangan utama dalam arsitektur semacam ini meliputi isolasi data, kustomisasi, dan kontrol akses antartenant (Bezemer & Zaidman, 2010). Ketika konsep tersebut diterapkan pada pemesanan di *food court*, muncul tuntutan yang lebih kompleks: satu keranjang belanja dapat berisi produk dari beberapa *tenant*, pesanan kemudian harus dipecah menjadi pesanan terpisah untuk masing-masing *tenant* (*split order*), pembayaran dilakukan satu kali secara terpusat, dan dana yang terkumpul perlu dibagi (*settlement*) kepada tiap *tenant* berdasarkan pola bagi hasil yang berlaku. Kebutuhan ini menuntut konsistensi transaksi serta ketertelusuran antara nominal pesanan, pembayaran, dan hak setiap *tenant*. Dalam konteks GMFS, isolasi *multi-tenant* diwujudkan melalui pemisahan data menu, pesanan, dan ringkasan pendapatan berdasarkan identitas *tenant*, sementara operator memiliki akses lintas *tenant* untuk keperluan rekapitulasi.

Gajah Mada Food Street (GMFS) merupakan *food court* di kawasan Gajah Mada, Jakarta Pusat, yang menampung 13 *tenant* kuliner. Berdasarkan wawancara awal dengan pengelola GMFS pada [tanggal], setiap *tenant* menerima pesanan dan pembayaran secara mandiri, sehingga pelanggan yang membeli dari dua *tenant* atau lebih harus mengantre dan membayar dua kali. Pengelola belum memiliki catatan transaksi terpusat; rekap penjualan dan perhitungan bagi hasil 80:20 (80% untuk *tenant* dan 20% untuk pengelola) masih dihitung manual dari laporan tiap *tenant*, sementara biaya listrik dan operasional *tenant* ditanggung *tenant*. Kondisi tersebut menunjukkan kebutuhan akan sistem pemesanan mandiri yang memusatkan pemesanan dan pembayaran lintas *tenant* dalam satu alur, tanpa menghilangkan pengelolaan pesanan oleh masing-masing *tenant*.

Pada sisi pembayaran, lanskap transaksi digital di Indonesia telah berkembang pesat. *Quick Response Code Indonesian Standard* (QRIS) merupakan standar kode QR pembayaran nasional yang ditetapkan oleh Bank Indonesia untuk memfasilitasi transaksi pembayaran secara terpadu (Bank Indonesia, 2021). Adopsi QRIS tumbuh sangat cepat hingga menjangkau puluhan juta pengguna dan merchant, yang sebagian besar merupakan UMKM (Bank Indonesia, 2025). Namun, pada konteks *food court* multi-tenant, pembayaran yang masih terpisah di tiap *tenant* membuat pelanggan harus melakukan beberapa transaksi untuk satu kali kunjungan, sekaligus menyulitkan pengelola dalam menggabungkan dan merekonsiliasi penerimaan. Karena QRIS menyalurkan dana ke satu *merchant*, pembayaran pelanggan pada sistem ini diterima pengelola dan bagian setiap *tenant* dihitung melalui *settlement* internal. Pada tahap pengembangan, integrasi pembayaran menggunakan *mock adapter* yang mensimulasikan notifikasi pembayaran, sehingga alur pembayaran terpusat dapat diuji tanpa dana riil; verifikasi *signature*, rekonsiliasi, dan perilaku *gateway* produksi tidak termasuk dalam cakupan penelitian ini.

Sejumlah penelitian telah mengembangkan sistem pemesanan makanan berbasis web untuk restoran dan kafe, termasuk yang telah mengintegrasikan *payment gateway* (Prasetya et al., 2024), serta sistem pemesanan *food court* yang bertujuan mempercepat proses pemesanan dan mengurangi antrean (Akshaya et al., 2025). Berdasarkan penelusuran terhadap studi yang ditinjau, belum ditemukan pembahasan yang secara terpadu mencakup kebutuhan *food court* multi-tenant, khususnya kombinasi antara pemecahan pesanan lintas *tenant* (*split order*), pembayaran terpusat dalam satu transaksi, dan perhitungan bagi hasil antara pengelola dan *tenant*. Dengan demikian, masih terbuka ruang untuk merancang sebuah sistem *self-order* terpusat yang secara eksplisit menjawab ketiga kebutuhan tersebut secara terpadu.

Berdasarkan uraian di atas, penelitian ini merancang dan membangun **GMFS Self-Order**, yaitu sistem *self-order* berbasis web untuk *food court* multi-tenant. Sistem memungkinkan pelanggan memilih produk dari banyak *tenant* dalam satu keranjang, melakukan satu kali *checkout*, dan membayar secara terpusat melalui satu alur pembayaran. Di sisi backend, satu transaksi *checkout* secara otomatis dipecah menjadi pesanan per *tenant* (*split order*). Sistem juga menyediakan mekanisme pembayaran yang bersifat *idempoten* agar pengiriman ulang notifikasi pembayaran tidak menghasilkan pesanan atau catatan *settlement* ganda, dasbor *tenant* untuk mengantrekan pesanan ke tahap persiapan, serta dasbor operator untuk melihat ringkasan *settlement* lintas *tenant*. Ringkasan tersebut menghitung bagian *tenant* (80%) dan bagian pengelola (20%) dari setiap pesanan *tenant* yang telah terbayar. Sistem dikembangkan sebagai *modular monolith* berbasis antarmuka pemrograman aplikasi (API) REST untuk menjaga kesederhanaan operasional namun tetap rapi secara arsitektur.

Penelitian ini menggunakan pendekatan *Research and Development* (R&D) dengan model *prototyping*, yang menekankan pengembangan secara bertahap serta pelibatan umpan balik pengguna pada setiap iterasi (Pressman & Maxim, 2020). Pengujian fungsional *black-box* difokuskan pada skenario kritis, yaitu ketepatan *split order*, isolasi data *tenant*, penolakan persiapan sebelum pembayaran terkonfirmasi, dan penanganan notifikasi pembayaran berulang. *User Acceptance Testing* (UAT) melibatkan [pengelola dan perwakilan *tenant*] untuk menilai kesesuaian fungsi dan penerimaan pengguna (Zulkarnaini et al., 2023). Dengan Gajah Mada Food Street sebagai studi kasus, diharapkan penelitian ini menghasilkan artefak perangkat lunak sekaligus gambaran penerapan arsitektur multi-tenant pada layanan pemesanan mandiri yang relevan bagi digitalisasi UMKM di sektor kuliner.

---

## 1.2 Rumusan Masalah

*(Font: Times New Roman, 12 pt, Center, Bold, Kapital)*

Berdasarkan latar belakang yang telah diuraikan, rumusan masalah dalam penelitian ini adalah sebagai berikut:

1. Bagaimana merancang dan membangun sistem *self-order* terpusat berbasis web untuk *food court* multi-tenant yang mendukung pemecahan pesanan lintas *tenant* (*split order*), pembayaran terpusat dalam satu transaksi, dan perhitungan bagi hasil 80:20 antara *tenant* dan pengelola?
2. Bagaimana mengevaluasi fungsionalitas dan penerimaan pengguna terhadap sistem yang dibangun melalui pengujian *black-box* dan *User Acceptance Testing* (UAT)?

---

## 1.3 Tujuan Penelitian

*(Font: Times New Roman, 12 pt, Center, Bold, Kapital)*

Sejalan dengan rumusan masalah, tujuan penelitian ini adalah sebagai berikut:

1. Merancang dan membangun sistem *self-order* terpusat berbasis web untuk *food court* multi-tenant, termasuk pemecahan pesanan lintas *tenant*, pembayaran terpusat dalam satu transaksi, dan perhitungan bagi hasil 80:20 antara *tenant* dan pengelola.
2. Mengevaluasi fungsionalitas dan penerimaan pengguna terhadap sistem melalui pengujian *black-box* dan UAT.

---

## 1.4 Batasan Masalah

*(Font: Times New Roman, 12 pt, Center, Bold, Kapital)*

Agar penelitian tetap terarah, ditetapkan batasan masalah sebagai berikut:

1. Penelitian berfokus pada perancangan dan pembangunan sistem *self-order* terpusat untuk *food court* multi-tenant, dengan Gajah Mada Food Street sebagai studi kasus.
2. Cakupan sistem meliputi antarmuka pelanggan (katalog, keranjang, *checkout*), pemecahan pesanan per *tenant*, pembayaran terpusat, dasbor *tenant* untuk antrean persiapan, dan dasbor operator untuk ringkasan *settlement*.
3. Integrasi pembayaran menggunakan *mock adapter*; transaksi tidak menggunakan dana riil. Verifikasi *signature*, rekonsiliasi, integrasi ke penyedia pembayaran produksi, dan aspek kepatuhan perizinan penyelenggara jasa pembayaran tidak dibahas.
4. Perhitungan *settlement* terbatas pada rekapitulasi transaksi terbayar dan pembagian 80:20; penyaluran dana otomatis (*payout*), pajak, *refund*, biaya *payment gateway*, serta biaya listrik dan operasional *tenant* tidak dimodelkan.
5. Sistem dirancang untuk dijalankan pada satu VPS; arsitektur *microservices*, Kubernetes, dan strategi *scaling* multi-server berada di luar cakupan.
6. Sistem tidak membahas fitur di luar lingkup pemesanan, seperti manajemen inventori/stok, manajemen gambar produk, program loyalitas, analitik lanjutan, dan multi-bahasa, termasuk fitur rekomendasi menu, personalisasi, dan iklan berbayar yang disarankan sebagai pengembangan lanjutan.
7. Pengujian difokuskan pada fungsionalitas dan penerimaan pengguna; pengujian beban dan pengujian keamanan tingkat lanjut tidak dilakukan.
8. Evaluasi dilakukan pada studi kasus GMFS yang memiliki 13 *tenant*; data uji menggunakan *tenant* dan menu yang tersedia pada saat pengujian, serta pengujian dilakukan melalui *browser* desktop/tablet, bukan perangkat kiosk fisik.

---

## 1.5 Kontribusi dan Manfaat

*(Font: Times New Roman, 12 pt, Center, Bold, Kapital)*

### 1.5.1 Kontribusi Penelitian

1. **Kontribusi terhadap perancangan sistem.** Penelitian ini menghasilkan rancangan dan implementasi sistem *self-order* terpusat yang menerapkan arsitektur multi-tenant pada konteks *food court*, mencakup mekanisme *split order*, pembayaran terpusat yang idempoten, dan perhitungan bagi hasil 80:20 antara *tenant* dan pengelola.
2. **Kontribusi terhadap praktik digitalisasi UMKM.** Penelitian ini mendemonstrasikan bagaimana kebutuhan nyata pelaku usaha kuliner di *food court* dapat dijawab melalui solusi perangkat lunak yang memusatkan pemesanan, pembayaran, dan rekapitulasi pendapatan tanpa menghilangkan kemandirian tiap *tenant*.
3. **Kontribusi terhadap pengembangan perangkat lunak.** Penelitian ini menjadi contoh penerapan pendekatan R&D dengan model *prototyping* yang dievaluasi menggunakan pengujian *black-box* dan UAT pada studi kasus nyata.

### 1.5.2 Manfaat Penelitian

1. **Bagi pelanggan.** Sistem memberikan kemudahan untuk memesan dari beberapa *tenant* dalam satu keranjang dan melakukan pembayaran satu kali, sehingga mengurangi pengulangan antrean dan transaksi.
2. **Bagi *tenant*.** Sistem membantu *tenant* menerima pesanan secara terstruktur, mengelola antrean persiapan, dan memperoleh data pesanan yang lebih rapi.
3. **Bagi pengelola *food court*.** Sistem menyediakan ringkasan penjualan dan *settlement* lintas *tenant* yang mempermudah rekapitulasi dan perhitungan bagi hasil 80:20.
4. **Bagi akademisi dan peneliti selanjutnya.** Penelitian ini dapat menjadi referensi penerapan arsitektur multi-tenant dan alur pembayaran terpusat pada sistem pemesanan makanan.

---

# DAFTAR PUSTAKA

*(Format APA 7th edition)*

Akshaya, R., Himalini, K., & Kamalini. (2025). Smart food court ordering system. *International Journal of Research Publication and Reviews, 6*(11), 8692–8694.

Bank Indonesia. (2021). *Peraturan Bank Indonesia Nomor 23/6/PBI/2021 tentang Penyedia Jasa Pembayaran*. Bank Indonesia. https://www.bi.go.id

Bank Indonesia. (2025). *QRIS jelajah Indonesia 2025 dorong digitalisasi* [Siaran pers]. https://www.bi.go.id/id/publikasi/ruang-media/news-release/Pages/sp_2717025.aspx

Bezemer, C.-P., & Zaidman, A. (2010). Multi-tenant SaaS applications: Maintenance dream or nightmare? Dalam *Proceedings of the Joint ERCIM Workshop on Software Evolution (EVOL) and International Workshop on Principles of Software Evolution (IWPSE)* (hlm. 88–92). Association for Computing Machinery. https://doi.org/10.1145/1862372.1862393

Bitner, M. J., Ostrom, A. L., & Meuter, M. L. (2002). Implementing successful self-service technologies. *Academy of Management Executive, 16*(4), 96–109. https://doi.org/10.5465/ame.2002.8951333

Kabbedijk, J., Bezemer, C.-P., Jansen, S., & Zaidman, A. (2015). Defining multi-tenancy: A systematic mapping study on the academic and the industrial perspective. *Journal of Systems and Software, 100*, 139–148. https://doi.org/10.1016/j.jss.2014.10.019

Kementerian Koordinator Bidang Perekonomian Republik Indonesia. (2023). *Pemerintah dorong UMKM naik kelas, tingkatkan kontribusi terhadap ekspor Indonesia*. https://www.ekon.go.id/publikasi/detail/6152

Kementerian Perindustrian Republik Indonesia. (2023). *Kontribusi industri makanan dan minuman tembus 37,77 persen*. https://kemenperin.go.id/artikel/23393

Meuter, M. L., Ostrom, A. L., Roundtree, R. I., & Bitner, M. J. (2000). Self-service technologies: Understanding customer satisfaction with technology-based service encounters. *Journal of Marketing, 64*(3), 50–64. https://doi.org/10.1509/jmkg.64.3.50.18024

Prasetya, M. G., Heksaputra, D., Wicaksono, Y., & Harahap, A. (2024). Perancangan aplikasi pemesanan menu pada Kafe Ra Kopiran berbasis website menggunakan metode waterfall. *Jurnal Teknologi Sistem Informasi, 5*(2), 173–187. https://doi.org/10.35957/jtsi.v5i2.9028

Pressman, R. S., & Maxim, B. R. (2020). *Software engineering: A practitioner's approach* (9th ed.). McGraw-Hill Education.

Rastegar, N., Flaherty, J., Liang, L., & Choi, H.-s. (2021). The adoption of self-service kiosks in quick-service restaurants. *European Journal of Tourism Research, 27*, 2709. https://doi.org/10.54055/ejtr.v27i.2139

Zulkarnaini, Firdhayanti, A., Taufik, T., & Bachry, B. (2023). User acceptance testing through blackbox evaluation for corn distribution information system. *bit-Tech, 6*(2), 208–215.

---

# CATATAN PENYUSUNAN (bukan bagian dokumen TA)

> Bagian ini hanya untuk membantu penyusunan. Hapus sebelum dimasukkan ke dokumen resmi.

1. **Gaya sitasi.** Draf ini memakai **APA 7**, konsisten dengan `Metopen Afif.docx`. Jika pembimbing meminta **IEEE** sesuai template kampus, seluruh sitasi perlu dikonversi.
2. **Lokasi GMFS.** Teks sudah diarahkan ke **Jl. Gajah Mada, Jakarta Pusat**; mohon dikonfirmasi sekali lagi.
3. **Jumlah tenant.** Angka **13 tenant** mengikuti informasi pengelola. Pastikan konsisten di seluruh dokumen dan saat UAT.
4. **Referensi yang perlu diverifikasi.** Nomor halaman/ISSN Akshaya et al. (2025) dan judul lengkap siaran pers Bank Indonesia (2025) perlu dicek. Detail Prasetya et al. (2024) sudah dilengkapi.
5. **Minimal referensi.** Template mensyaratkan minimal 5 sumber ber-ISSN/ISBN; draf ini memuat lebih dari itu.
6. **Fitur rekomendasi.** Tidak lagi termasuk ruang lingkup Bab I; desainnya tetap tersedia di `docs/PRD.md` §16 sebagai pengembangan lanjutan.
7. **Bagian belum ditulis.** Sistematika penulisan, abstrak, kata kunci, dan bab-bab selanjutnya (Kajian Pustaka, Metodologi, Simpulan) belum termasuk di sini.
8. **Arah bagi hasil.** Teks memakai asumsi **80% untuk tenant dan 20% untuk pengelola**; konfirmasi arah pembagian sebelum dikunci.
9. **Section 1.2 Alternatif Solusi.** TOC `Metopen Afif.docx` menyebutkannya, tetapi body dokumen tidak memuatnya. Belum ditambahkan agar struktur yang sudah disetujui tidak berubah; tambahkan bila ditagih pembimbing.
