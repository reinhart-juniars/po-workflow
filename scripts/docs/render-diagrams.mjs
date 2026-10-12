/**
 * Merender diagram alur panduan (docs/panduan/diagram/alur.html) menjadi JPEG
 * di docs/panduan/img/<id>.jpg, satu berkas per <svg id="alur-...">.
 *
 * Dompdf tidak bisa menggambar SVG dengan andal (mata panah, CSS), jadi
 * panduan memakai gambar hasil render Chrome (JPEG: PNG beralpha sebesar ini
 * menghabiskan memori dompdf); sumber yang disunting tetap SVG-nya.
 *
 *   node scripts/docs/render-diagrams.mjs
 *   QA_CHROME="C:/Program Files/Google/Chrome/Application/chrome.exe" node scripts/docs/render-diagrams.mjs
 */
import puppeteer from 'puppeteer-core';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const source = path.join(root, 'docs/panduan/diagram/alur.html');
const outDir = path.join(root, 'docs/panduan/img');
const chrome = process.env.QA_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';

const browser = await puppeteer.launch({ executablePath: chrome });
const page = await browser.newPage();
// Skala 3: lebar 600 menjadi 1800 px, kira-kira 260 dpi di halaman A4.
await page.setViewport({ width: 700, height: 900, deviceScaleFactor: 3 });
await page.goto(pathToFileURL(source).href, { waitUntil: 'networkidle0' });
await page.evaluate(() => document.fonts.ready);

const diagrams = await page.$$('svg.alur');
for (const svg of diagrams) {
  const id = await svg.evaluate((el) => el.id);
  const file = path.join(outDir, `${id}.jpg`);
  // Beri ruang 4px supaya garis tepi kotak yang menempel di batas viewBox tidak terpotong.
  const box = await svg.boundingBox();
  await page.screenshot({ path: file, type: 'jpeg', quality: 92, clip: { x: box.x - 4, y: box.y - 4, width: box.width + 8, height: box.height + 8 } });
  console.log(`ditulis ${path.relative(root, file)}`);
}

await browser.close();
