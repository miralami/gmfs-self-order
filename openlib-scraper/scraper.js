import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/* ============================ KONFIGURASI ============================ */
const ORIGIN = 'https://openlibrary.telkomuniversity.ac.id';
const CATALOG_URL = `${ORIGIN}/home/catalog.html`;

// Parameter advanced search
const PUBLISHER = process.env.OPENLIB_PUBLISHER || 'Kampus Jakarta';

// Folder hasil download
const DOWNLOAD_DIR =
  process.env.OPENLIB_OUT ||
  'C:\\Users\\Afif\\OneDrive - Telkom University\\TUGAS AKHIR\\openlib-download';

// Profil browser lokal (biar login SSO cukup sekali). Tetap di komputer kamu.
const PROFILE_DIR = path.join(__dirname, '.profile');

// 0 = semua halaman / semua item
const MAX_PAGES = Number(process.env.OPENLIB_MAX_PAGES || 0);
const MAX_ITEMS = Number(process.env.OPENLIB_MAX_ITEMS || 0);

// Jeda antar request (sopan ke server)
const DELAY_MS = Number(process.env.OPENLIB_DELAY_MS || 400);

// Bab yang diambil
const BAB_TARGETS = [
  { key: 'bab1', label: 'BAB 1' },
  { key: 'bab2', label: 'BAB 2' },
];

const MANIFEST_PATH = path.join(DOWNLOAD_DIR, '_manifest.json');
/* ==================================================================== */

const log = (...a) => console.log(...a);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function ensureDir(dir) {
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
}

function slugify(s, max = 60) {
  return (s || '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, max)
    .replace(/-+$/g, '');
}

function short(s, n = 70) {
  s = (s || '').replace(/\s+/g, ' ').trim();
  return s.length > n ? s.slice(0, n - 1) + '…' : s;
}

function loadManifest() {
  try {
    return JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf-8'));
  } catch {
    return { created: new Date().toISOString(), publisher: PUBLISHER, items: {} };
  }
}

function saveManifest(m) {
  ensureDir(DOWNLOAD_DIR);
  fs.writeFileSync(MANIFEST_PATH, JSON.stringify(m, null, 2), 'utf-8');
}

async function isLoggedIn(page) {
  try {
    if (!page.url().includes('openlibrary.telkomuniversity.ac.id')) return false;
    return await page.evaluate(() => {
      const hasLogout = !!document.querySelector(
        'a.btn-logout, .btn-logout, a[href*="logout"], a[href*="signout"]'
      );
      const hasLoginBtn = !!document.querySelector('button[data-target="#loginModal"]');
      // Anggap login kalau ada tombol logout, atau tombol "Login (SSO)" sudah hilang
      return hasLogout || !hasLoginBtn;
    });
  } catch {
    return false;
  }
}

async function waitForLogin(page, timeoutMs = 15 * 60 * 1000) {
  if (await isLoggedIn(page)) {
    log('[LOGIN] Sesi login terdeteksi. Lanjut otomatis.');
    return true;
  }

  log('');
  log('==============================================================');
  log(' SILAKAN LOGIN SSO DI BROWSER YANG TERBUKA');
  log(' Script akan lanjut OTOMATIS begitu login terdeteksi.');
  log(' (MFA/OTP juga boleh, semua di browser lokal kamu)');
  log('==============================================================');
  log('');

  const start = Date.now();
  let lastNotice = 0;
  while (Date.now() - start < timeoutMs) {
    await page.waitForTimeout(2000);
    if (await isLoggedIn(page)) {
      log('[LOGIN] Berhasil. Lanjut otomatis...');
      return true;
    }
    const elapsed = Math.floor((Date.now() - start) / 1000);
    if (elapsed - lastNotice >= 30) {
      lastNotice = elapsed;
      log(`[LOGIN] Menunggu login... (${elapsed}s) — URL: ${short(page.url(), 90)}`);
    }
  }
  return false;
}

async function runAdvancedSearch(page) {
  log(`[SEARCH] Membuka katalog: ${CATALOG_URL}`);
  await page.goto(CATALOG_URL, { waitUntil: 'load', timeout: 60000 });
  await page.waitForTimeout(800);

  try {
    log('[SEARCH] Buka modal Advanced ...');
    await page.locator('button[data-target="#modal-filter"]').first().click({ timeout: 15000 });
    await page.locator('#modal-filter').waitFor({ state: 'visible', timeout: 15000 });
    await page.locator('#search-publisher').fill(PUBLISHER);

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null),
      page.locator('#modal-filter input[type="submit"]').first().click(),
    ]);
  } catch (err) {
    log(`[SEARCH] Fallback submit langsung (${err.message})`);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null),
      page.evaluate((publisher) => {
        const input = document.getElementById('search-publisher');
        input.value = publisher;
        input.form.requestSubmit();
      }, PUBLISHER),
    ]);
  }

  await page.waitForTimeout(1200);
  const summary = await page
    .locator('.search-summary-modern')
    .innerText()
    .catch(() => '');
  log(`[SEARCH] Penerbit = "${PUBLISHER}"`);
  log(`[SEARCH] ${summary.replace(/\s+/g, ' ').trim() || '(ringkasan tidak terbaca)'}`);
}

async function crawlListing(page) {
  const items = [];
  const seen = new Set();
  let pageNo = 1;
  let totalPages = 1;
  let filteredOut = 0;

  while (true) {
    if (pageNo > 1) {
      const url = `${ORIGIN}/home/catalog/page/${pageNo}.html`;
      log(`[LIST] Halaman ${pageNo}/${totalPages || '?'} → ${url}`);
      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.waitForTimeout(700);
    }

    const cards = page.locator('.card-horizontal');
    const count = await cards.count();
    if (count === 0) {
      log(`[LIST] Halaman ${pageNo} kosong. Selesai crawl.`);
      break;
    }

    for (let i = 0; i < count; i++) {
      const card = cards.nth(i);
      const link = card.locator('h3.card-title-hoz a').first();
      const href = await link.getAttribute('href').catch(() => null);
      if (!href) continue;

      const title = (await link.innerText().catch(() => '')).replace(/\s+/g, ' ').trim();
      const publisher = (
        await card
          .locator('.meta-list-hoz > div')
          .first()
          .innerText()
          .catch(() => '')
      )
        .replace(/\s+/g, ' ')
        .trim();
      const code = (await card.locator('.kode-badge').innerText().catch(() => ''))
        .replace(/\s+/g, ' ')
        .trim();

      const url = new URL(href, ORIGIN).href;
      const id = (url.match(/\/id\/(\d+)\//) || [])[1] || '';
      if (!id || seen.has(id)) continue;
      seen.add(id);

      if (!publisher.toLowerCase().includes(PUBLISHER.toLowerCase())) {
        filteredOut++;
        continue;
      }

      items.push({ id, url, title, publisher, code });
    }

    const lastHref = await page
      .locator('.pagination li:last-child a')
      .first()
      .getAttribute('href')
      .catch(() => null);
    if (lastHref) {
      const n = Number((lastHref.match(/\/page\/(\d+)\.html/) || [])[1]);
      if (n) totalPages = n;
    }

    if (pageNo >= totalPages) break;
    if (MAX_PAGES > 0 && pageNo >= MAX_PAGES) {
      log(`[LIST] Berhenti di MAX_PAGES=${MAX_PAGES}`);
      break;
    }
    pageNo++;
    await sleep(DELAY_MS);
  }

  log(`[LIST] Kandidat cocok: ${items.length} item (dilewati karena penerbit lain: ${filteredOut})`);
  return items;
}

function decodePayloadName(urlLike) {
  try {
    const m = String(urlLike).match(/\/(?:flippingbook|flippingbook_url_download)\/([^/?#]+)/);
    if (!m) return null;
    let b = m[1].replace(/-/g, '+').replace(/_/g, '/');
    while (b.length % 4) b += '=';
    const json = JSON.parse(Buffer.from(b, 'base64').toString('utf-8'));
    return json?.dwn?.name || json?.name || null;
  } catch {
    return null;
  }
}

/**
 * Ambil satu file bab.
 * Alur situs: /pustaka/files/{id}/bab1/<slug>.pdf
 *   -> 302 /open/index.php/download/files/<payload>
 *   -> 302 /open/index.php/download/flippingbook/<payload>  (halaman viewer)
 *   -> di dalam JS halaman ada: pdfUrl: "/open/index.php/download/flippingbook_url_download/<payload>"
 */
async function downloadBab(context, href, destNoExt) {
  const viewer = await context.newPage();
  try {
    const startUrl = new URL(href, ORIGIN).href;
    const resp = await viewer.goto(startUrl, { waitUntil: 'domcontentloaded', timeout: 90000 });

    // Kadang server langsung mengembalikan PDF
    let raw = null;
    try {
      raw = await resp?.body();
    } catch {
      raw = null;
    }
    if (raw && raw.subarray(0, 4).toString() === '%PDF') {
      const dest = `${destNoExt}.pdf`;
      fs.writeFileSync(dest, raw);
      return { path: dest, bytes: raw.length };
    }

    const html = await viewer.content();
    const m = html.match(/pdfUrl\s*:\s*"([^"]+)"/);
    if (!m) throw new Error('pdfUrl tidak ditemukan di halaman flipbook');
    const pdfUrl = new URL(m[1], ORIGIN).href;

    const isPdf = (buf) => buf && buf.length > 4 && buf.subarray(0, 4).toString() === '%PDF';

    // 1) fetch dari dalam halaman (paling mirip perilaku viewer + bawa cookie)
    try {
      const b64 = await viewer.evaluate(async (u) => {
        const r = await fetch(u, { credentials: 'include' });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const buf = new Uint8Array(await r.arrayBuffer());
        if (!buf.length) throw new Error('kosong');
        let bin = '';
        const chunk = 0x8000;
        for (let i = 0; i < buf.length; i += chunk) {
          bin += String.fromCharCode.apply(null, buf.subarray(i, i + chunk));
        }
        return btoa(bin);
      }, pdfUrl);

      const buf = Buffer.from(b64, 'base64');
      if (!isPdf(buf)) throw new Error('respons bukan PDF (kemungkinan sesi/login)');
      const dest = `${destNoExt}.pdf`;
      fs.writeFileSync(dest, buf);
      return { path: dest, bytes: buf.length };
    } catch (e1) {
      log(`    [WARN] in-page fetch gagal (${e1.message}), coba request context...`);
    }

    // 2) request context (cookie ikut)
    const ua = await viewer.evaluate(() => navigator.userAgent).catch(() => undefined);
    const r = await context.request.get(pdfUrl, {
      headers: { Referer: viewer.url(), ...(ua ? { 'User-Agent': ua } : {}) },
      timeout: 180000,
    });
    if (!r.ok()) throw new Error(`HTTP ${r.status()}`);
    const buf2 = await r.body();
    if (!isPdf(buf2)) throw new Error('respons bukan PDF (kemungkinan sesi/login)');
    const dest2 = `${destNoExt}.pdf`;
    fs.writeFileSync(dest2, buf2);
    return { path: dest2, bytes: buf2.length };
  } finally {
    await viewer.close().catch(() => {});
  }
}

async function processItem(context, item, manifest) {
  const folderName = `${item.id}_${slugify(item.title) || 'dokumen'}`;
  const folder = path.join(DOWNLOAD_DIR, folderName);
  ensureDir(folder);

  const detail = await context.newPage();
  // Auto-accept dialog bawaan (confirm() pada link file)
  detail.on('dialog', (d) => d.accept().catch(() => {}));

  try {
    await detail.goto(item.url, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await detail.waitForTimeout(500);

    const meta = await detail.evaluate(() => {
      const rows = {};
      document.querySelectorAll('.data-row').forEach((r) => {
        const l = r.querySelector('.data-label')?.innerText?.trim();
        const v = r.querySelector('.data-value')?.innerText?.trim();
        if (l) rows[l] = (v || '').replace(/\s+/g, ' ');
      });
      return rows;
    });

    const publisher = meta['Penerbit'] || item.publisher || '';
    if (publisher && !publisher.toLowerCase().includes(PUBLISHER.toLowerCase())) {
      log(`  [SKIP] Penerbit bukan "${PUBLISHER}": ${short(publisher, 80)}`);
      return { status: 'skipped-publisher' };
    }

    const links = await detail.evaluate(() =>
      Array.from(document.querySelectorAll('.file_download_list a')).map((a) => ({
        text: (a.innerText || '').replace(/\s+/g, ' ').trim(),
        href: a.getAttribute('href') || '',
      }))
    );

    const files = {};
    for (const target of BAB_TARGETS) {
      const match =
        links.find((l) => l.href.toLowerCase().includes(`/${target.key}/`)) ||
        links.find((l) => new RegExp(`\\b${target.label.replace(' ', '\\s*')}\\b`, 'i').test(l.text));

      if (!match) {
        log(`  [MISS] ${target.label} tidak tersedia`);
        continue;
      }

      const guessed = decodePayloadName(match.href);
      const baseName = guessed
        ? guessed.replace(/\.pdf$/i, '')
        : `${item.code || item.id}_${target.key}`;

      log(`  [GET ] ${target.label} → ${short(match.text, 60)}`);

      let result = null;
      for (let attempt = 1; attempt <= 2 && !result; attempt++) {
        try {
          result = await downloadBab(context, match.href, path.join(folder, baseName));
        } catch (e) {
          log(`  [WARN] ${target.label} gagal (attempt ${attempt}): ${e.message}`);
          await sleep(1200 * attempt);
        }
      }

      if (result) {
        log(`  [OK  ] ${path.basename(result.path)} (${(result.bytes / 1024 / 1024).toFixed(2)} MB)`);
        files[target.key] = {
          file: path.basename(result.path),
          bytes: result.bytes,
          fetched_at: new Date().toISOString(),
        };
      }
    }

    // metadata.md per dokumen
    const md = [
      `# ${meta['Judul'] || item.title}`,
      '',
      `- Katalog ID: ${item.id}`,
      `- Kode: ${meta['Kode'] || item.code || '-'}`,
      `- Pengarang: ${meta['Nama'] || '-'}`,
      `- Pembimbing: ${meta['Pembimbing'] || '-'}`,
      `- Penerbit: ${publisher || '-'}`,
      `- Tahun: ${meta['Tahun'] || '-'}`,
      `- Jenis: ${meta['Jenis'] || '-'}`,
      `- Subjek: ${meta['Subjek'] || '-'}`,
      `- URL: ${item.url}`,
      '',
      '## Berkas',
      ...(Object.keys(files).length
        ? Object.entries(files).map(
            ([k, v]) => `- ${k}: \`${v.file}\` (${(v.bytes / 1024 / 1024).toFixed(2)} MB)`
          )
        : ['- (tidak ada file yang berhasil diunduh)']),
      '',
    ].join('\n');
    fs.writeFileSync(path.join(folder, 'metadata.md'), md, 'utf-8');

    manifest.items[item.id] = {
      title: meta['Judul'] || item.title,
      code: meta['Kode'] || item.code || '',
      author: meta['Nama'] || '',
      publisher,
      year: meta['Tahun'] || '',
      url: item.url,
      folder: folderName,
      files,
      updated_at: new Date().toISOString(),
    };
    saveManifest(manifest);

    const wanted = BAB_TARGETS.length;
    const got = Object.keys(files).length;
    return { status: got === wanted ? 'complete' : got > 0 ? 'partial' : 'none', got, wanted };
  } finally {
    await detail.close().catch(() => {});
  }
}

function writeIndex(manifest) {
  const rows = Object.entries(manifest.items || {});
  const lines = [
    `# OpenLib ${PUBLISHER} — Bab 1 & Bab 2`,
    '',
    `Total dokumen: ${rows.length}`,
    `Diperbarui: ${new Date().toISOString()}`,
    '',
    '| ID | Kode | Judul | Tahun | Bab 1 | Bab 2 |',
    '|---|---|---|---|---|---|',
  ];
  for (const [id, it] of rows) {
    const f = it.files || {};
    const link = (v) =>
      v ? `[pdf](${encodeURI(it.folder || '')}/${encodeURIComponent(v.file)})` : '–';
    lines.push(
      `| ${id} | ${it.code || '-'} | ${(it.title || '').replace(/\|/g, '\\|')} | ${
        it.year || '-'
      } | ${link(f.bab1)} | ${link(f.bab2)} |`
    );
  }
  fs.writeFileSync(path.join(DOWNLOAD_DIR, '_index.md'), lines.join('\n') + '\n', 'utf-8');
}

async function main() {
  ensureDir(DOWNLOAD_DIR);
  const manifest = loadManifest();
  manifest.publisher = PUBLISHER;

  log('==============================================================');
  log(' OPENLIB TELKOM — AUTO SCRAPER BAB 1 & BAB 2');
  log('==============================================================');
  log(`Penerbit : ${PUBLISHER}`);
  log(`Output   : ${DOWNLOAD_DIR}`);
  log(`Profil   : ${PROFILE_DIR}`);
  log(`Mode     : ${MAX_PAGES ? `maks ${MAX_PAGES} halaman` : 'semua halaman'}`);
  log('');

  const context = await chromium.launchPersistentContext(PROFILE_DIR, {
    headless: false,
    acceptDownloads: true,
    viewport: { width: 1360, height: 900 },
    slowMo: 60,
  });
  context.on('page', (p) => p.on('dialog', (d) => d.accept().catch(() => {})));

  const page = context.pages()[0] || (await context.newPage());
  await page.goto(ORIGIN, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});

  const ok = await waitForLogin(page);
  if (!ok) {
    log('[FATAL] Login tidak terdeteksi dalam batas waktu. Browser dibiarkan terbuka.');
    return;
  }

  await runAdvancedSearch(page);
  const items = await crawlListing(page);

  const targets = MAX_ITEMS > 0 ? items.slice(0, MAX_ITEMS) : items;
  if (MAX_ITEMS > 0) log(`[LIMIT] Diproses hanya ${targets.length} item (MAX_ITEMS=${MAX_ITEMS})`);

  log('');
  log('==============================================================');
  log(` MULAI UNDUH ${targets.length} DOKUMEN`);
  log('==============================================================');

  const stats = { complete: 0, partial: 0, none: 0, skipped: 0, already: 0 };

  for (let i = 0; i < targets.length; i++) {
    const item = targets[i];
    const tag = `[${i + 1}/${targets.length}]`;
    const existing = manifest.items[item.id];
    if (
      existing &&
      existing.files?.bab1 &&
      existing.files?.bab2 &&
      fs.existsSync(path.join(DOWNLOAD_DIR, existing.folder, existing.files.bab1.file)) &&
      fs.existsSync(path.join(DOWNLOAD_DIR, existing.folder, existing.files.bab2.file))
    ) {
      stats.already++;
      log(`${tag} [SKIP] Sudah lengkap: ${short(existing.title || item.title)}`);
      continue;
    }

    log('');
    log(`${tag} ${short(item.title, 95)}`);
    log(`        kode=${item.code || '-'} id=${item.id}`);

    try {
      const res = await processItem(context, item, manifest);
      if (res.status === 'complete') stats.complete++;
      else if (res.status === 'partial') stats.partial++;
      else if (res.status === 'skipped-publisher') stats.skipped++;
      else stats.none++;
    } catch (e) {
      stats.none++;
      log(`  [ERROR] ${e.message}`);
      manifest.items[item.id] = {
        title: item.title,
        url: item.url,
        code: item.code,
        folder: null,
        files: {},
        error: e.message,
        updated_at: new Date().toISOString(),
      };
      saveManifest(manifest);
    }

    await sleep(DELAY_MS);
  }

  writeIndex(manifest);
  saveManifest(manifest);

  log('');
  log('==============================================================');
  log(' SELESAI');
  log(` Lengkap       : ${stats.complete}`);
  log(` Sebagian      : ${stats.partial}`);
  log(` Gagal/kosong  : ${stats.none}`);
  log(` Sudah ada     : ${stats.already}`);
  log(` Skip penerbit : ${stats.skipped}`);
  log(` Output        : ${DOWNLOAD_DIR}`);
  log('==============================================================');
  log('Browser dibiarkan terbuka. Tutup manual kalau sudah selesai.');
}

main().catch((err) => {
  console.error('[FATAL]', err);
  process.exit(1);
});
