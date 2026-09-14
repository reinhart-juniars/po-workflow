/**
 * Smoke test browser sungguhan untuk panel Inventory Terpadu (Phase 1-4).
 *
 * Membuka setiap halaman panel sebagai pengguna yang diberikan, mencatat
 * status HTTP, error konsol, dan halaman exception, lalu menjalankan alur
 * Phase 3 dari ujung ke ujung (Buat dari Slot SPK -> Form Kebutuhan -> Setujui
 * -> Periksa -> Tutup SPK -> PDF). Dipakai saat gladi cutover & UAT.
 *
 * Jalankan:
 *   npm i --no-save puppeteer-core
 *   QA_BASE=http://po-workflow.test QA_USER=... QA_PASS=... QA_SPK=478 \
 *   QA_CHROME="C:/Program Files/Google/Chrome/Application/chrome.exe" node scripts/qa/smoke.mjs
 *
 * Kredensial hanya lewat variabel lingkungan; jangan ditulis di file.
 * Alur Phase 3 MENULIS data (SPK Produksi baru + ledger) -- jalankan di
 * staging, bukan produksi, kecuali memang sedang smoke test pasca cutover
 * dengan slot SPK yang disiapkan untuk itu.
 */
import puppeteer from 'puppeteer-core';
import fs from 'node:fs';
import path from 'node:path';

const env = (k, d) => process.env[k] ?? d;
const BASE = env('QA_BASE', 'http://po-workflow.test');
const USER = env('QA_USER');
const PASS = env('QA_PASS');
const SPK = env('QA_SPK');
const CHROME = env('QA_CHROME', 'C:/Program Files/Google/Chrome/Application/chrome.exe');
const OUT = path.resolve(env('QA_OUT', 'storage/app/qa-smoke'));
const FLOW = env('QA_FLOW', SPK ? '1' : '0') === '1';

if (!USER || !PASS) {
  console.error('QA_USER dan QA_PASS wajib diisi.');
  process.exit(2);
}
fs.mkdirSync(OUT, { recursive: true });

const PAGES = [
  '/admin/inventory-items', '/admin/inventory-items/create', '/admin/inventory-purchases', '/admin/stock-opnames',
  '/admin/inventory-openings', '/admin/stock-mutation-report',
  '/admin/inventory-unit-conversions', '/admin/inventory-unit-conversions/create', '/admin/inventory-unit-conversions/butuh-aturan',
  '/admin/recipes', '/admin/recipes/create', '/admin/recipe-mismatches',
  '/admin/production-orders', '/admin/production-orders/create', '/admin/requisitions', '/admin/inventory-movements',
  '/admin/production-workers', '/admin/hpp-comparison-report',
  '/admin/pengaturan', '/admin/users',
];

const report = { pages: [], flow: [], errors: [], downloads: [] };
const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox'], defaultViewport: { width: 1440, height: 1000 } });
const page = await browser.newPage();
let where = 'login';
let navStatus = null;

page.on('pageerror', (e) => report.errors.push({ where, type: 'pageerror', text: String(e).slice(0, 300) }));
page.on('console', (m) => { if (m.type() === 'error' && !/favicon|insecure connection/.test(m.text())) report.errors.push({ where, type: 'console', text: m.text().slice(0, 300) }); });
page.on('response', async (r) => {
  if (r.request().isNavigationRequest() && r.request().resourceType() === 'document') navStatus = r.status();
  if (r.status() >= 400 && !/favicon/.test(r.url())) report.errors.push({ where, type: `http${r.status()}`, text: r.url() });
  if (r.url().includes('/livewire/update')) {
    try {
      const j = await r.json();
      for (const c of j.components ?? []) {
        const d = c.effects?.download;
        if (d) report.downloads.push({ where, name: d.name, isPdf: String(d.content ?? '').startsWith('JVBERi0') });
      }
    } catch {}
  }
});

const state = () => page.evaluate(() => ({
  h1: document.querySelector('h1')?.innerText?.trim(),
  exception: /ErrorException|Whoops|QueryException|SQLSTATE|Call to undefined/.test(document.body.innerText) && !document.querySelector('.fi-sidebar'),
  notifs: [...document.querySelectorAll('.fi-no-notification')].map((n) => n.innerText.replace(/\s+/g, ' ').trim().slice(0, 160)),
  formStatus: document.body.innerText.match(/Status Form\s*\n\s*([^\n]+)/)?.[1] ?? null,
}));
const shot = (name) => page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: true });
const idle = async () => { await page.waitForNetworkIdle({ idleTime: 600, timeout: 30000 }).catch(() => {}); await new Promise((r) => setTimeout(r, 500)); };
const component = (re) => page.evaluate((src) => window.Livewire.all().map((c) => c.name).find((n) => new RegExp(src).test(n)), re.source);
async function action(re, name, { confirm = false, data = null } = {}) {
  const comp = await component(re);
  if (!comp) throw new Error(`komponen ${re} tidak ditemukan`);
  const wire = (fn, ...args) => page.evaluate((c, f, ...a) => window.Livewire.all().find((x) => x.name === c).$wire[f](...a), comp, fn, ...args);
  await wire('mountAction', name); await idle();
  for (const [k, v] of Object.entries(data ?? {})) { await wire('set', `mountedActionsData.0.${k}`, v); }
  if (data) await idle();
  if (confirm || data) { await wire('callMountedAction'); await idle(); }
}
async function step(name, fn) {
  where = `flow:${name}`;
  const entry = { name };
  try { Object.assign(entry, { ok: true, ...((await fn()) ?? {}) }); } catch (e) { entry.ok = false; entry.error = String(e).slice(0, 300); }
  entry.state = await state();
  await shot(`flow-${String(report.flow.length + 1).padStart(2, '0')}-${name}`);
  report.flow.push(entry);
  const gagal = entry.state.notifs.find((n) => /^(Gagal|Belum bisa)/.test(n));
  if (entry.ok && gagal) { entry.ok = false; entry.error = gagal; }
  if (!entry.ok) throw new Error(`langkah ${name} gagal: ${entry.error}`);
  return entry;
}

// login
await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle0' });
await page.type('input[id$="login"]', USER);
await page.type('input[type=password]', PASS);
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[type=submit]')]);
if (page.url().includes('/login')) { console.error('Login gagal.'); await browser.close(); process.exit(1); }

// crawl
for (const url of PAGES) {
  where = url;
  navStatus = null;
  let status;
  try {
    const res = await page.goto(BASE + url, { waitUntil: 'networkidle0', timeout: 60000 });
    status = res?.status() ?? navStatus;
  } catch (e) {
    status = String(e);
  }
  report.pages.push({ url, status, ...(await state()) });
  await shot(url.replace(/^\/admin\//, '').replace(/[\/?=&]/g, '_'));
}

// Phase 3 flow
if (FLOW) {
  try {
    await step('dari_spk', async () => {
      await page.goto(`${BASE}/admin/production-orders`, { waitUntil: 'networkidle0' });
      const nav = page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 30000 }).catch(() => null);
      await action(/list-production-orders/, 'dari_spk', { data: { spk_id: Number(SPK) } });
      await nav;
      return { url: page.url() };
    });
    const id = page.url().match(/production-orders\/(\d+)/)?.[1];
    if (!id) throw new Error('SPK Produksi tidak terbentuk');
    const REQ = /requisition-form/;
    await step('susun', async () => { await page.goto(`${BASE}/admin/production-orders/${id}/kebutuhan`, { waitUntil: 'networkidle0' }); await action(REQ, 'susun'); });
    await step('isi_stok_awal', () => page.evaluate(() => {
      let n = 0;
      for (const i of document.querySelectorAll('.fi-fo-repeater-item input')) {
        if (/opening_stock_qty/.test(i.id) && i.value === '') { i.value = '1'; i.dispatchEvent(new Event('input', { bubbles: true })); n++; }
      }
      return { n };
    }).then(async (r) => { await new Promise((x) => setTimeout(x, 1200)); return r; }));
    await step('simpan', () => action(REQ, 'simpan'));
    await step('setujui', () => action(REQ, 'setujui', { confirm: true }));
    await step('periksa', () => action(REQ, 'periksa', { confirm: true }));
    await step('cetak_form', async () => { await action(REQ, 'cetak'); await new Promise((r) => setTimeout(r, 1500)); });
    await step('tutup', () => action(REQ, 'tutup', { confirm: true }));
    await step('cetak_spk', async () => { await page.goto(`${BASE}/admin/production-orders/${id}/edit`, { waitUntil: 'networkidle0' }); await action(/edit-production-order/, 'cetak'); await new Promise((r) => setTimeout(r, 1500)); });
  } catch (e) {
    report.flowError = String(e);
  }
}

fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 1));
const bad = report.pages.filter((p) => p.status !== 200 || p.exception);
const summary = {
  pages: `${report.pages.length - bad.length}/${report.pages.length} OK`,
  badPages: bad.map((p) => `${p.url} -> ${p.status}${p.exception ? ' (exception)' : ''}`),
  flow: report.flow.map((f) => `${f.name}: ${f.ok ? 'ok' : 'GAGAL ' + f.error}${f.state.formStatus ? ' [' + f.state.formStatus + ']' : ''}`),
  flowError: report.flowError ?? null,
  downloads: report.downloads,
  errors: report.errors,
  out: OUT,
};
console.log(JSON.stringify(summary, null, 1));
await browser.close();
process.exit(bad.length || report.flowError || report.errors.length ? 1 : 0);
