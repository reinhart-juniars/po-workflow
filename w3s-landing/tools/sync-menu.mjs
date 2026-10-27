/**
 * Build www.w3scatering.com dengan menu dari 3S ONE (Bagian C).
 *
 * Dijalankan Cloudflare Pages setiap kali marketing menekan "Terbitkan ke
 * Website" (Deploy Hook), dan setiap commit di folder w3s-landing:
 *
 *   node tools/sync-menu.mjs            -> menulis situs siap tayang ke dist/
 *
 * Lingkungan:
 *   WEBSITE_API_URL    https://3sone.w3scatering.com/api/website/menu
 *   WEBSITE_API_TOKEN  sama dengan WEBSITE_API_TOKEN di .env server 3S ONE (rahasia)
 *   OUT_DIR, SITE_DIR  opsional (bawaan dist/ dan folder situs ini)
 *
 * Yang diambil adalah snapshot terbitan terakhir di 3S ONE, bukan data yang
 * sedang disunting. Aturan gagal:
 *   - belum pernah terbit (404) atau token tidak diatur -> menu cadangan di HTML
 *     dipakai apa adanya, build tetap sukses;
 *   - 3S ONE tidak bisa diakses / token salah -> build GAGAL, sehingga Cloudflare
 *     tetap menayangkan versi sebelumnya (website tidak pernah kosong);
 *   - satu foto gagal diunduh -> menu itu tampil tanpa foto, build jalan terus.
 */
import { cp, mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));

/** Berkas/folder sumber yang tidak ikut tayang. */
export const EXCLUDE = new Set(['dist', 'tools', 'docs', 'README.md', 'vercel.json', 'node_modules', '.git', '.node-version', 'package.json']);

/** Folder foto menu dari 3S ONE di dalam situs yang tayang. */
export const PHOTO_DIR = 'assets/menu-3sone';

/** Jumlah menu WWP yang tertulis di teks halaman lama (meta, hero, katalog). */
export const ORIGINAL_WWP_COUNT = 172;

const PHOTO_CONCURRENCY = 6;
const TIMEOUT_MS = 30_000;

export function escapeHtml(text) {
  return String(text ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

/** Nama berkas foto lokal satu menu; memuat versi supaya foto baru tidak tertahan cache. */
export function photoFile(item) {
  return `${PHOTO_DIR}/${item.id}-${String(item.photo.version).replace(/[^a-z0-9]/gi, '')}.webp`;
}

/**
 * HTML kelompok menu satu brand, sama dengan markup katalog lama: daftar
 * berfoto, lalu "Menu lain di kategori ini" untuk yang belum berfoto.
 * Kategori kosong tidak ditampilkan. `photos` = Set id menu yang fotonya ada.
 */
export function renderBrand(brand, photos) {
  const groups = [];

  for (const category of brand.categories) {
    if (!category.items.length) continue;

    const withPhoto = category.items.filter((item) => item.photo && photos.has(item.id));
    const withoutPhoto = category.items.filter((item) => !(item.photo && photos.has(item.id)));
    const lines = [
      `    <div class="menu-group" data-reveal data-cat="${escapeHtml(category.filter)}">`,
      '      <div class="menu-group__head">',
      `        <h2>${escapeHtml(category.title)}</h2>`,
      // Halaman 3S tidak mencantumkan harga, sama seperti halaman lama.
      ...(brand.key === '3s' ? ['        <span class="menu-group__price">Hubungi kami untuk harga</span>'] : []),
      '      </div>',
      `      <p class="menu-group__desc">${escapeHtml(category.description)}</p>`,
    ];

    if (withPhoto.length) {
      lines.push('      <ul class="menu-list menu-list--photo">');
      for (const item of withPhoto) {
        const name = escapeHtml(item.name);
        lines.push(`        <li><img src="${photoFile(item)}" alt="${name}" loading="lazy" decoding="async" width="560" height="420"><span class="mi__n">${name}</span></li>`);
      }
      lines.push('      </ul>');
    }

    if (withoutPhoto.length) {
      if (withPhoto.length) lines.push('      <p class="menu-sub">Menu lain di kategori ini</p>');
      lines.push('      <ul class="menu-list menu-list--text">');
      for (const item of withoutPhoto) lines.push(`        <li>${escapeHtml(item.name)}</li>`);
      lines.push('      </ul>');
    }

    lines.push('    </div>');
    groups.push(lines.join('\n'));
  }

  return groups.join('\n\n');
}

/** Ganti isi di antara penanda menu-3sone:<key>:mulai / :selesai. */
export function replaceRegion(html, key, content) {
  const pattern = new RegExp(`(<!-- menu-3sone:${key}:mulai[^>]*-->)[\\s\\S]*?<!-- menu-3sone:${key}:selesai -->`);

  if (!pattern.test(html)) {
    throw new Error(`Penanda menu-3sone:${key} tidak ditemukan di HTML.`);
  }

  // Fungsi pengganti (bukan string) supaya "$" di nama menu tidak dibaca sebagai pola.
  return html.replace(pattern, (_, start) => `${start}\n${content}\n    <!-- menu-3sone:${key}:selesai -->`);
}

/** Angka "172 pilihan menu" dll. di teks halaman mengikuti jumlah menu WWP sebenarnya. */
export function replaceCounts(html, count) {
  const n = String(ORIGINAL_WWP_COUNT);

  return html
    .replace(new RegExp(`\\b${n}(?= pilihan| menu harian)`, 'g'), String(count))
    .replace(new RegExp(`(dari )${n}\\b`, 'g'), `$1${count}`);
}

async function fetchWithTimeout(url, options = {}) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);

  try {
    return await fetch(url, { ...options, signal: controller.signal });
  } finally {
    clearTimeout(timer);
  }
}

/** Snapshot menu dari 3S ONE; null bila belum pernah terbit (404). Gagal lain = lempar. */
export async function fetchFeed(apiUrl, token) {
  let response;
  try {
    response = await fetchWithTimeout(apiUrl, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
  } catch (error) {
    throw new Error(`3S ONE tidak bisa dihubungi (${error.message}).`);
  }

  if (response.status === 404) return null;
  if (response.status === 401) throw new Error('3S ONE menolak token (401). Samakan WEBSITE_API_TOKEN di Cloudflare dengan .env server.');
  if (!response.ok) throw new Error(`3S ONE menjawab ${response.status}.`);

  const feed = await response.json();
  if (!Array.isArray(feed.brands)) throw new Error('Isi menu dari 3S ONE tidak dikenali (tidak ada "brands").');

  return feed;
}

/** Unduh foto menu ke OUT_DIR; mengembalikan Set id yang fotonya berhasil. */
async function downloadPhotos(feed, outDir, token) {
  const items = feed.brands.flatMap((brand) => brand.categories.flatMap((category) => category.items)).filter((item) => item.photo?.url);
  const ok = new Set();
  await mkdir(path.join(outDir, PHOTO_DIR), { recursive: true });

  let next = 0;
  async function worker() {
    while (next < items.length) {
      const item = items[next++];
      try {
        const response = await fetchWithTimeout(item.photo.url, { headers: { Authorization: `Bearer ${token}` } });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        await writeFile(path.join(outDir, photoFile(item)), Buffer.from(await response.arrayBuffer()));
        ok.add(item.id);
      } catch (error) {
        console.warn(`  ! foto "${item.name}" gagal diunduh (${error.message}); tampil tanpa foto`);
      }
    }
  }
  await Promise.all(Array.from({ length: Math.min(PHOTO_CONCURRENCY, items.length) }, worker));

  return ok;
}

/** Salin situs sumber ke outDir, tanpa berkas kerja (README, docs, tools, ...). */
async function copySite(siteDir, outDir) {
  await rm(outDir, { recursive: true, force: true });
  await mkdir(outDir, { recursive: true });

  for (const entry of await readdir(siteDir)) {
    if (EXCLUDE.has(entry) || entry.startsWith('.')) continue;
    await cp(path.join(siteDir, entry), path.join(outDir, entry), { recursive: true });
  }
}

export async function build({ siteDir = path.resolve(HERE, '..'), outDir, apiUrl, token } = {}) {
  outDir ??= path.join(siteDir, 'dist');
  await copySite(siteDir, outDir);
  console.log(`Situs disalin ke ${path.relative(process.cwd(), outDir) || outDir}`);

  if (!apiUrl || !token) {
    console.warn('! WEBSITE_API_URL / WEBSITE_API_TOKEN belum diatur: menu cadangan di HTML dipakai apa adanya.');
    return { synced: false };
  }

  const feed = await fetchFeed(apiUrl, token);
  if (feed === null) {
    console.log('Menu belum pernah diterbitkan dari 3S ONE: menu cadangan di HTML dipakai apa adanya.');
    return { synced: false };
  }

  const photos = await downloadPhotos(feed, outDir, token);
  const counts = {};

  for (const brand of feed.brands) {
    const file = path.join(outDir, `${brand.page}.html`);
    if (brand.count === 0) {
      // Lebih aman menampilkan menu lama daripada halaman brand tanpa menu.
      console.warn(`! ${brand.label}: 0 menu terbit; menu cadangan di ${brand.page}.html dipertahankan.`);
      continue;
    }
    const html = await readFile(file, 'utf8');
    await writeFile(file, replaceRegion(html, brand.key, renderBrand(brand, photos)));
    counts[brand.key] = brand.count;
    console.log(`${brand.label}: ${brand.count} menu ditulis ke ${brand.page}.html`);
  }

  if (counts.wwp) {
    for (const entry of await readdir(outDir)) {
      if (!entry.endsWith('.html')) continue;
      const file = path.join(outDir, entry);
      await writeFile(file, replaceCounts(await readFile(file, 'utf8'), counts.wwp));
    }
  }

  // Foto bernama versi -> boleh disimpan lama di cache browser/CDN.
  await writeFile(path.join(outDir, '_headers'), `/${PHOTO_DIR}/*\n  Cache-Control: public, max-age=31536000, immutable\n`);

  console.log(`Selesai: ${feed.total} menu, ${photos.size} foto (terbitan ${feed.version?.slice(0, 8) ?? '-'}).`);

  return { synced: true, total: feed.total, photos: photos.size };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  build({
    siteDir: process.env.SITE_DIR,
    outDir: process.env.OUT_DIR,
    apiUrl: process.env.WEBSITE_API_URL,
    token: process.env.WEBSITE_API_TOKEN,
  }).catch((error) => {
    console.error(`Build website gagal: ${error.message}`);
    console.error('Website tetap menayangkan versi sebelumnya.');
    process.exit(1);
  });
}
