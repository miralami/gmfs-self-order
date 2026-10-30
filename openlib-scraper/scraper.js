import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/* ============================ ARGUMEN CLI ============================ */
function parseArgs(argv) {
  const out = {};
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (!a.startsWith('--')) continue;
    const key = a.slice(2);
    const next = argv[i + 1];
    if (next && !next.startsWith('--')) {
      out[key] = next;
      i++;
    } else {
      out[key] = 'true';
    }
  }
  return out;
}
const ARGS = parseArgs(process.argv.slice(2));
const pick = (argKey, envKey, fallback) =>
  ARGS[argKey] !== undefined ? ARGS[argKey] : process.env[envKey] !== undefined ? process.env[envKey] : fallback;

/* ============================ KONFIGURASI ============================ */
const ORIGIN = 'https://openlibrary.telkomuniversity.ac.id';
const CATALOG_URL = `${ORIGIN}/home/catalog.html`;

// Parameter advanced search
const PUBLISHER = pick('publisher', 'OPENLIB_PUBLISHER', 'Kampus Jakarta');

// Folder hasil download
const DOWNLOAD_DIR =
  pick('out', 'OPENLIB_OUT', 'C:\\Users\\Afif\\OneDrive - Telkom University\\TUGAS AKHIR\\openlib-download');

// Profil browser lokal (biar login SSO cukup sekali). Tetap di komputer kamu.
const PROFILE_DIR = path.join(__dirname, '.profile');

// 0 = semua halaman / semua item
const MAX_PAGES = Number(pick('pages', 'OPENLIB_MAX_PAGES', 0));
const MAX_ITEMS = Number(pick('items', 'OPENLIB_MAX_ITEMS', 0));

// Rentang urutan item (1-based, inklusif). --from 51 --to 100
const START_INDEX = Number(pick('from', 'OPENLIB_FROM', 1));
const END_INDEX = Number(pick('to', 'OPENLIB_TO', 0)); // 0 = sampai habis

// Crawl ulang daftar (abaikan _order.json)
const REFRESH_ORDER = ['1', 'true', 'yes'].includes(String(pick('refresh', 'OPENLIB_REFRESH', '')).toLowerCase());

// Jeda antar request (sopan ke server)
const DELAY_MS = Number(pick('delay', 'OPENLIB_DELAY_MS', 400));

// Setelah login, browser otomatis pindah ke headless. Pakai --headed untuk tetap terlihat.
const KEEP_HEADFUL = ['1', 'true', 'yes'].includes(
  String(pick('headed', 'OPENLIB_HEADED', '')).toLowerCase()
);

// Bab yang diambil
const BAB_TARGETS = [
  { key: 'bab1', label: 'BAB 1' },
  { key: 'bab2', label: 'BAB 2' },
];

const MANIFEST_PATH = path.join(DOWNLOAD_DIR, '_manifest.json');
const ORDER_PATH = path.join(DOWNLOAD_DIR, '_order.json');
const AUTH_STATE_PATH = path.join(__dirname, '.auth.json');
/* ==================================================================== */

class SessionError extends Error {}

let stopping = false;
process.on('SIGINT', () => {
  if (stopping) process.exit(1);
  stopping = true;
  log('\n[PAUSE] Diminta berhenti. Menyelesaikan item yang sedang berjalan... (Ctrl+C lagi untuk paksa keluar)');
});


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

function loadOrder() {
  try {
    const data = JSON.parse(fs.readFileSync(ORDER_PATH, 'utf-8'));
    if (!Array.isArray(data.items) || data.items.length === 0) return null;
    return data;
  } catch {
    return null;
  }
}

function saveOrder(items) {
  ensureDir(DOWNLOAD_DIR);
  fs.writeFileSync(
    ORDER_PATH,
    JSON.stringify(
      { publisher: PUBLISHER, created_at: new Date().toISOString(), total: items.length, items },
      null,
      2
    ),
    'utf-8'
  );
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

    // Pengaman: halaman blokir / sesi mati jangan dianggap "tidak tersedia"
    const pageTitle = await detail.title().catch(() => '');
    if (/just a moment|attention required|access denied|verify you are human/i.test(pageTitle)) {
      throw new SessionError(`Halaman diblokir (${short(pageTitle, 60)})`);
    }
    if (links.length === 0 && !(await isLoggedIn(detail))) {
      throw new SessionError('Sesi login tidak aktif saat membuka detail');
    }

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
  const order = loadOrder();
  const orderIds = order?.items?.map((it) => String(it.id)) || [];
  const entries = Object.entries(manifest.items || {});
  entries.sort((a, b) => {
    const ia = orderIds.indexOf(String(a[0]));
    const ib = orderIds.indexOf(String(b[0]));
    if (ia === -1 && ib === -1) return 0;
    if (ia === -1) return 1;
    if (ib === -1) return -1;
    return ia - ib;
  });

  const lines = [
    `# OpenLib ${PUBLISHER} — Bab 1 & Bab 2`,
    '',
    `Total dokumen: ${entries.length}`,
    `Diperbarui: ${new Date().toISOString()}`,
    '',
    '| No | ID | Kode | Judul | Tahun | Bab 1 | Bab 2 |',
    '|---|---|---|---|---|---|---|',
  ];
  entries.forEach(([id, it], idx) => {
    const f = it.files || {};
    const link = (v) =>
      v ? `[pdf](${encodeURI(it.folder || '')}/${encodeURIComponent(v.file)})` : '–';
    const no = orderIds.indexOf(String(id));
    lines.push(
      `| ${no >= 0 ? no + 1 : idx + 1} | ${id} | ${it.code || '-'} | ${(it.title || '').replace(
        /\|/g,
        '\\|'
      )} | ${it.year || '-'} | ${link(f.bab1)} | ${link(f.bab2)} |`
    );
  });
  fs.writeFileSync(path.join(DOWNLOAD_DIR, '_index.md'), lines.join('\n') + '\n', 'utf-8');
}

async function openBrowser(headless) {
  const ctx = await chromium.launchPersistentContext(PROFILE_DIR, {
    headless,
    acceptDownloads: true,
    viewport: { width: 1360, height: 900 },
    slowMo: headless ? 0 : 60,
  });

  // Session cookie OpenLib tidak ikut tersimpan saat browser ditutup,
  // jadi inject ulang dari .auth.json.
  if (fs.existsSync(AUTH_STATE_PATH)) {
    try {
      const state = JSON.parse(fs.readFileSync(AUTH_STATE_PATH, 'utf-8'));
      if (Array.isArray(state.cookies) && state.cookies.length) {
        await ctx.addCookies(state.cookies);
      }
    } catch (e) {
      log(`[WARN] Gagal memuat sesi tersimpan: ${e.message}`);
    }
  }

  ctx.on('page', (p) => p.on('dialog', (d) => d.accept().catch(() => {})));
  const pg = ctx.pages()[0] || (await ctx.newPage());
  await pg.goto(ORIGIN, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});
  return { ctx, pg };
}

async function saveAuthState(context) {
  try {
    await context.storageState({ path: AUTH_STATE_PATH });
    log('[LOGIN] Cookie sesi disimpan ke .auth.json (lokal, tidak di-commit).');
  } catch (e) {
    log(`[WARN] Gagal menyimpan sesi: ${e.message}`);
  }
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
  log(`Rentang  : ${START_INDEX} → ${END_INDEX || 'akhir'}`);
  log(`Tampilan : ${KEEP_HEADFUL ? 'headful (selalu terlihat)' : 'headless setelah login'}`);
  log('');

  // 1) Coba langsung headless pakai sesi tersimpan.
  let { ctx: context, pg: page } = await openBrowser(true);

  if (!(await isLoggedIn(page))) {
    log('[LOGIN] Sesi tidak ditemukan. Membuka browser untuk login SSO...');
    await context.close();
    await sleep(800);

    ({ ctx: context, pg: page } = await openBrowser(false));
    const ok = await waitForLogin(page);
    if (!ok) {
      log('[FATAL] Login tidak terdeteksi dalam batas waktu. Browser dibiarkan terbuka.');
      return;
    }
    await saveAuthState(context);

    if (!KEEP_HEADFUL) {
      log('[MODE] Login tersimpan. Menutup browser dan beralih ke headless...');
      await context.close();
      await sleep(800);
      ({ ctx: context, pg: page } = await openBrowser(true));
      if (await isLoggedIn(page)) {
        log('[MODE] Headless aktif. Lanjut otomatis.');
      } else {
        log('[WARN] Sesi tidak terbaca setelah relaunch. Jalankan lagi atau pakai --headed.');
      }
    }
  } else {
    log('[LOGIN] Sesi login tersimpan terdeteksi — jalan headless.');
    await saveAuthState(context);
  }

  // Daftar urutan item: pakai cache _order.json kalau ada (biar nomor urut stabil saat pause/lanjut)
  let items;
  const cached = loadOrder();
  if (cached && !REFRESH_ORDER && cached.publisher === PUBLISHER) {
    items = cached.items;
    log(
      `[ORDER] Pakai urutan tersimpan: ${items.length} item (dibuat ${new Date(
        cached.created_at
      ).toLocaleString('id-ID')}). Pakai --refresh untuk crawl ulang.`
    );
  } else {
    await runAdvancedSearch(page);
    items = await crawlListing(page);
    if (items.length) saveOrder(items);
    log(`[ORDER] Urutan disimpan ke ${ORDER_PATH}`);
  }

  const total = items.length;
  const startIdx = Math.max(1, START_INDEX || 1);
  const endIdx = END_INDEX > 0 ? Math.min(total, END_INDEX) : total;
  let targets = startIdx > total ? [] : items.slice(startIdx - 1, endIdx);
  if (MAX_ITEMS > 0) targets = targets.slice(0, MAX_ITEMS);

  if (targets.length === 0) {
    log('[INFO] Tidak ada item pada rentang tersebut.');
    return;
  }

  log(`[RANGE] Memproses urutan ${startIdx}–${endIdx} dari total ${total} (${targets.length} item)`);

  log('');
  log('==============================================================');
  log(` MULAI UNDUH ${targets.length} DOKUMEN`);
  log('==============================================================');

  const stats = { complete: 0, partial: 0, none: 0, skipped: 0, already: 0 };
  let lastIndex = startIdx - 1;
  let consecutiveEmpty = 0;
  let sessionLost = null;

  for (let i = 0; i < targets.length; i++) {
    if (stopping) break;
    const item = targets[i];
    const absIndex = startIdx + i;
    const tag = `[${absIndex}/${total}]`;
    lastIndex = absIndex;
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
      if (res.status === 'none') consecutiveEmpty++;
      else consecutiveEmpty = 0;
    } catch (e) {
      if (e instanceof SessionError) {
        sessionLost = e.message;
        log(`  [STOP] ${e.message}`);
        break;
      }
      stats.none++;
      consecutiveEmpty++;
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

    // Kalau banyak item beruntun kosong, kemungkinan besar sesi/akses bermasalah
    if (consecutiveEmpty >= 8) {
      sessionLost = `${consecutiveEmpty} item beruntun tanpa Bab 1/Bab 2 (kemungkinan sesi tidak aktif)`;
      log(`  [STOP] ${sessionLost}`);
      break;
    }

    await sleep(DELAY_MS);
  }

  writeIndex(manifest);
  saveManifest(manifest);

  log('');
  log('==============================================================');
  log(stopping ? ' DIJEDA' : sessionLost ? ' DIHENTIKAN' : ' SELESAI');
  log(` Lengkap       : ${stats.complete}`);
  log(` Sebagian      : ${stats.partial}`);
  log(` Gagal/kosong  : ${stats.none}`);
  log(` Sudah ada     : ${stats.already}`);
  log(` Skip penerbit : ${stats.skipped}`);
  log(` Output        : ${DOWNLOAD_DIR}`);
  log('==============================================================');

  if (sessionLost) {
    log(`[MASALAH] ${sessionLost}`);
    log('[SARAN] 1) Hapus sesi lama: npm run reset-session');
    log('[SARAN] 2) Login ulang terlihat: npm start -- --headed --from ' + Math.max(1, lastIndex - 1));
    log('[SARAN] 3) Setelah login, Ctrl+C lalu jalankan headless lagi dari nomor yang sama.');
  }

  const next = lastIndex + 1;
  if (stopping && next <= total) {
    log(`[PAUSE] Berhenti di urutan ${lastIndex} dari ${total}.`);
    log(`[PAUSE] Lanjut nanti dengan perintah:`);
    log(`        npm start -- --from ${next}`);
  } else if (lastIndex < total) {
    log(`[NEXT ] Sisa urutan ${next}–${total}. Lanjut: npm start -- --from ${next}`);
  }
  log('Browser dibiarkan terbuka. Tutup manual kalau sudah selesai.');
}

main().catch((err) => {
  console.error('[FATAL]', err);
  process.exit(1);
});
