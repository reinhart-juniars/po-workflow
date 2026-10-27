/**
 * Uji skrip build website dengan "3S ONE" tiruan (server HTTP lokal).
 *
 *   node --test tools/sync-menu.test.mjs
 */
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { mkdtemp, readFile, rm, stat, writeFile, mkdir } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { after, before, describe, it } from 'node:test';
import { build, escapeHtml, replaceCounts, replaceRegion } from './sync-menu.mjs';

const TOKEN = 'token-uji';

function feed(base) {
  const item = (id, name, photo = true) => ({ id, name, photo: photo ? { version: `v${id}`, url: `${base}/photo/${id}` } : null });

  return {
    version: 'abc123def456',
    total: 4,
    brands: [
      {
        key: 'wwp', label: 'Warung Wuenak Poll', page: 'wuenak-poll', count: 3,
        categories: [
          { key: 'ayam', title: 'Menu Ayam', filter: 'ayam', description: 'Ayam & <teman>', items: [item(1, 'Nasi Ayam Teriyaki'), item(2, 'Nasi Ayam $& Mentega', false), item(9, 'Foto Rusak')] },
          { key: 'telur', title: 'Menu Telur', filter: 'telur', description: '-', items: [] },
        ],
      },
      { key: '3s', label: 'Kuliner Tradisional 3S', page: '3s', count: 0, categories: [{ key: 'premium', title: 'Premium', filter: 'premium', description: '-', items: [] }] },
    ],
  };
}

let server;
let base;
let mode = 'ok';

before(async () => {
  server = createServer((req, res) => {
    if (req.headers.authorization !== `Bearer ${TOKEN}`) {
      res.writeHead(401).end('{}');
      return;
    }
    if (req.url === '/menu') {
      if (mode === 'belum') return res.writeHead(404, { 'Content-Type': 'application/json' }).end('{"message":"belum"}');
      if (mode === 'rusak') return res.writeHead(500).end('error');
      return res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify(feed(base)));
    }
    if (req.url === '/photo/1') return res.writeHead(200, { 'Content-Type': 'image/webp' }).end(Buffer.from('RIFFwebp'));
    return res.writeHead(404).end();
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  base = `http://127.0.0.1:${server.address().port}`;
});

after(() => server.close());

/** Situs mini dengan penanda menu + berkas kerja yang tidak boleh ikut tayang. */
async function site() {
  const dir = await mkdtemp(path.join(os.tmpdir(), 'situs-'));
  await writeFile(path.join(dir, 'wuenak-poll.html'), [
    '<meta name="description" content="172 pilihan menu">',
    '<p>Sembilan dari 172</p>',
    '    <!-- menu-3sone:wwp:mulai — cadangan -->',
    '    <div class="menu-group">MENU LAMA WWP</div>',
    '    <!-- menu-3sone:wwp:selesai -->',
  ].join('\n'));
  await writeFile(path.join(dir, '3s.html'), '<!-- menu-3sone:3s:mulai -->\n<div>MENU LAMA 3S</div>\n<!-- menu-3sone:3s:selesai -->\n<p>172 pilihan menu</p>');
  await writeFile(path.join(dir, 'README.md'), 'internal');
  await mkdir(path.join(dir, 'docs'));
  await writeFile(path.join(dir, 'docs', 'DESIGN.md'), 'internal');

  return dir;
}

describe('sync-menu', () => {
  it('menulis menu terbitan, mengunduh foto, dan tidak menayangkan berkas kerja', async () => {
    mode = 'ok';
    const dir = await site();
    const out = path.join(dir, 'dist');

    const result = await build({ siteDir: dir, outDir: out, apiUrl: `${base}/menu`, token: TOKEN });
    const wwp = await readFile(path.join(out, 'wuenak-poll.html'), 'utf8');

    assert.equal(result.synced, true);
    assert.equal(result.photos, 1);
    assert.doesNotMatch(wwp, /MENU LAMA WWP/);
    assert.match(wwp, /<img src="assets\/menu-3sone\/1-v1\.webp" alt="Nasi Ayam Teriyaki"/);
    // Foto gagal diunduh -> tampil tanpa foto; "$&" di nama tidak dibaca sebagai pola.
    assert.match(wwp, /<li>Nasi Ayam \$&amp; Mentega<\/li>/);
    assert.match(wwp, /<li>Foto Rusak<\/li>/);
    assert.match(wwp, /Menu lain di kategori ini/);
    assert.match(wwp, /Ayam &amp; &lt;teman&gt;/);
    assert.doesNotMatch(wwp, /Menu Telur/, 'kategori kosong tidak tampil');
    assert.match(wwp, /3 pilihan menu/);
    assert.match(wwp, /Sembilan dari 3/);
    // Brand tanpa menu terbit: menu cadangan dipertahankan.
    assert.match(await readFile(path.join(out, '3s.html'), 'utf8'), /MENU LAMA 3S/);
    await stat(path.join(out, 'assets', 'menu-3sone', '1-v1.webp'));
    await assert.rejects(stat(path.join(out, 'README.md')));
    await assert.rejects(stat(path.join(out, 'docs')));
    assert.match(await readFile(path.join(out, '_headers'), 'utf8'), /immutable/);

    await rm(dir, { recursive: true, force: true });
  });

  it('memakai menu cadangan bila belum pernah terbit atau token belum diatur', async () => {
    const dir = await site();
    const out = path.join(dir, 'dist');

    mode = 'belum';
    assert.equal((await build({ siteDir: dir, outDir: out, apiUrl: `${base}/menu`, token: TOKEN })).synced, false);
    assert.match(await readFile(path.join(out, 'wuenak-poll.html'), 'utf8'), /MENU LAMA WWP/);

    assert.equal((await build({ siteDir: dir, outDir: out, apiUrl: '', token: '' })).synced, false);
    assert.match(await readFile(path.join(out, 'wuenak-poll.html'), 'utf8'), /172 pilihan menu/);

    await rm(dir, { recursive: true, force: true });
  });

  it('gagal (supaya versi lama tetap tayang) bila 3S ONE error atau token salah', async () => {
    const dir = await site();

    mode = 'rusak';
    await assert.rejects(build({ siteDir: dir, apiUrl: `${base}/menu`, token: TOKEN }), /menjawab 500/);

    mode = 'ok';
    await assert.rejects(build({ siteDir: dir, apiUrl: `${base}/menu`, token: 'salah' }), /menolak token/);

    // Kontrol positif: token benar lolos.
    assert.equal((await build({ siteDir: dir, apiUrl: `${base}/menu`, token: TOKEN })).synced, true);

    await rm(dir, { recursive: true, force: true });
  });

  it('meloloskan karakter HTML dan menolak HTML tanpa penanda', () => {
    assert.equal(escapeHtml('<b>"A" & \'B\''), '&lt;b&gt;&quot;A&quot; &amp; &#39;B&#39;');
    assert.throws(() => replaceRegion('<p>tanpa penanda</p>', 'wwp', 'x'), /tidak ditemukan/);
    assert.equal(replaceCounts('172 menu harian, 1720 lain', 90), '90 menu harian, 1720 lain');
  });
});
