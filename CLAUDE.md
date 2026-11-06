# CLAUDE.md

Arah proyek GMFS Self-Order ada di [`AGENTS.md`](./AGENTS.md) (kanonik; dibaca OpenCode & Claude Code).

## Research & penulisan TA (khusus Claude Code)

Skill di `.claude/skills/` (dipanggil otomatis sesuai task):

| Skill | Kapan |
|---|---|
| `research-source-finder` | Cari jurnal/referensi, ambil full text, isi matriks literatur |
| `thesis-writing-coach` | Struktur bab, outline, draft, timeline, persiapan sidang |
| `thesis-chapter-review` | Review bab sebelum dikirim ke dosen |
| `academic-translation-en-id` | Terjemah EN <-> ID akademik (judul/abstrak bilingual) |

- Draft proposal/TA: `docs/latbel.md` (format mengikuti pedoman Telkom yang tercatat di file itu). Klaim teknis harus cocok dengan `docs/PRD.md`, `docs/BSD.md`, `docs/EVIDENCE.md` dan kode aktual.
- Sumber: connector claude.ai (Consensus, Wiley Scholar Gateway, PubMed) + MCP `paper-search` (`.mcp.json`, butuh `uv`). Email Unpaywall & API key opsional di `~/.config/paper-search-mcp/.env`, **jangan** di repo.
- TA Telkom terdahulu (BAB 1-2): user menjalankan sendiri `openlib-scraper/` di laptopnya (login SSO lokal). Boleh membaca PDF hasilnya; **jangan ubah atau commit folder itu** (lihat AGENTS.md).
- Tidak boleh mengarang sumber, DOI, kutipan, atau nomor halaman. Tidak memakai Sci-Hub/LibGen. Tandai `abstract-only` jika full text tidak tersedia.
- Gaya sitasi default APA 7 kecuali pedoman menyatakan lain.
