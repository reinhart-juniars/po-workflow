// Load test HTTP (staging, JANGAN produksi: fase tulis membuat PO sungguhan).
//   QA_USER=... QA_PASS=... [QA_BASE=http://po-workflow.test] [QA_ORDER=id SPK Produksi]
//   node scripts/qa/stress/load.mjs [readVUs=10] [readSec=60] [writeVUs=5] [writeSec=30]
// Kredensial hanya lewat variabel lingkungan; jangan ditulis di file.
const BASE = process.env.QA_BASE ?? 'http://po-workflow.test';
const USER = process.env.QA_USER;
const PASS = process.env.QA_PASS;
if (!USER || !PASS) { console.error('QA_USER dan QA_PASS wajib diisi.'); process.exit(2); }
const [READ_VU = 10, READ_SEC = 60, WRITE_VU = 5, WRITE_SEC = 30] = process.argv.slice(2).map(Number);
const M = 'date_from=2026-09-01&date_to=2026-09-30';

const READ_PAGES = [
  '/owner-app', '/admin-app/orders', '/admin-app/spk', '/sales-app', '/sales-app/barang-sisa',
  '/inventory/dashboard', `/inventory/production-orders/${process.env.QA_ORDER ?? 1}/kebutuhan`, '/inventory/inventory-items',
  `/accounting-app/reports/profit-loss?${M}`, `/accounting-app/reports/cashflow?${M}`,
  `/sales-app/reports/sales?${M}`, '/search/suggest?q=nasi', '/marketing-app/catalog', '/accounting-app/expenses',
];

class Session {
  constructor() { this.jar = new Map(); }
  cookie() { return [...this.jar].map(([k, v]) => `${k}=${v}`).join('; '); }
  store(res) { for (const c of res.headers.getSetCookie?.() ?? []) { const [kv] = c.split(';'); const i = kv.indexOf('='); this.jar.set(kv.slice(0, i), kv.slice(i + 1)); } }
  async req(path, opts = {}) {
    const res = await fetch(BASE + path, { redirect: 'manual', ...opts, headers: { cookie: this.cookie(), ...(opts.headers ?? {}) } });
    this.store(res);
    return res;
  }
  async csrf(path) {
    const html = await (await this.req(path)).text();
    return html.match(/name="_token" value="([^"]+)"/)?.[1] ?? html.match(/csrf-token" content="([^"]+)"/)?.[1];
  }
  async login(name) {
    const token = await this.csrf('/login');
    const body = new URLSearchParams({ _token: token, name, password: PASS });
    const res = await this.req('/login', { method: 'POST', body, headers: { 'content-type': 'application/x-www-form-urlencoded' } });
    if (res.status !== 302) throw new Error('login gagal ' + res.status);
  }
}

const stats = {};
const rec = (key, ms, status) => { (stats[key] ??= { t: [], err: 0, codes: {} }).t.push(ms); stats[key].codes[status] = (stats[key].codes[status] ?? 0) + 1; if (status >= 400) stats[key].err++; };
const pct = (a, p) => { const s = [...a].sort((x, y) => x - y); return s[Math.min(s.length - 1, Math.floor((p / 100) * s.length))]; };

async function readVU(until) {
  const s = new Session();
  await s.login(USER);
  while (Date.now() < until) {
    const page = READ_PAGES[Math.floor(Math.random() * READ_PAGES.length)];
    const t = performance.now();
    const res = await s.req(page);
    await res.arrayBuffer();
    rec(page, performance.now() - t, res.status);
  }
}

async function writeVU(until, n) {
  const s = new Session();
  await s.login(USER);
  const token = await s.csrf('/admin-app/orders');
  let i = 0;
  while (Date.now() < until) {
    const body = new URLSearchParams({
      _token: token, customer_id: process.env.QA_CUSTOMER ?? '1', recipient_name: `Load ${n}-${i++}`, shipping_address: 'Jl. Uji Beban', area_id: process.env.QA_AREA ?? '1',
      delivery_date: '2026-10-02', delivery_time: '09:00', payment_type: 'cash', cash_account_id: process.env.QA_CASH ?? '1',
      'items[0][product_id]': '1', 'items[0][qty]': '3', 'items[1][product_id]': '2', 'items[1][qty]': '2',
    });
    const t = performance.now();
    const res = await s.req('/admin-app/orders', { method: 'POST', body, headers: { 'content-type': 'application/x-www-form-urlencoded', accept: 'text/html' } });
    await res.arrayBuffer();
    rec('POST /admin-app/orders (buat PO)', performance.now() - t, res.status);
  }
}

const report = (title) => {
  console.log(`\n== ${title}`);
  console.log('halaman'.padEnd(58), 'n'.padStart(5), 'p50'.padStart(7), 'p95'.padStart(7), 'max'.padStart(7), ' status');
  for (const [k, v] of Object.entries(stats).sort((a, b) => pct(b[1].t, 95) - pct(a[1].t, 95))) {
    console.log(k.slice(0, 58).padEnd(58), String(v.t.length).padStart(5), pct(v.t, 50).toFixed(0).padStart(7), pct(v.t, 95).toFixed(0).padStart(7), Math.max(...v.t).toFixed(0).padStart(7), ' ', JSON.stringify(v.codes));
  }
  const all = Object.values(stats).flatMap((v) => v.t);
  console.log(`TOTAL ${all.length} request, p50 ${pct(all, 50).toFixed(0)} ms, p95 ${pct(all, 95).toFixed(0)} ms, error ${Object.values(stats).reduce((a, v) => a + v.err, 0)}`);
  for (const k of Object.keys(stats)) delete stats[k];
};

let until = Date.now() + READ_SEC * 1000;
await Promise.all(Array.from({ length: READ_VU }, () => readVU(until)));
report(`BACA: ${READ_VU} pengguna bersamaan, ${READ_SEC} dtk`);

if (WRITE_VU > 0) {
  until = Date.now() + WRITE_SEC * 1000;
  await Promise.all(Array.from({ length: WRITE_VU }, (_, n) => writeVU(until, n)));
  report(`TULIS: ${WRITE_VU} admin membuat PO bersamaan, ${WRITE_SEC} dtk`);
}
