# Runbook Deploy & Cutover — Modul Inventory Terpadu

Panduan memindahkan Master Menu Revamp (SQLite lokal) ke po-workflow di server dan
menyalakan modul Inventory/Resep/Produksi untuk pemakaian sehari-hari. Ditulis untuk
orang yang melakukan deploy; setiap langkah punya perintah dan cara memastikannya.

Prasyarat: po-workflow sudah berjalan di server (nginx + php-fpm 8.2 + MySQL), deploy
lewat `git pull` sebagai **user deploy** (bukan `www-data`).

---

## 0. Aturan main

- **Rahasia** (`.env`, dump database, `app.db`) tidak pernah masuk git, chat, atau
  screenshot. `.env` di server: mode `600`, pemilik user deploy. Setelah `config:cache`
  proses web tidak membaca `.env` lagi.
- Setiap langkah yang menulis ke database produksi didahului **backup** (langkah 3).
- Yang boleh menjalankan runbook ini: satu orang, dengan akses `superadmin` di aplikasi
  dan shell user deploy di server. Catat jam mulai/selesai tiap langkah di kolom
  "Jejak" pada checklist di bagian akhir.

## 1. Bekukan Master Menu Revamp (H-1)

1. Umumkan ke tim: setelah jam X tidak ada lagi input di Master Menu Revamp.
2. Ambil `app.db` terakhir dari mesin klien (`Master Menu Revamp/app/data/app.db`),
   salin ke server lewat `scp` ke direktori **di luar** web root, mis.
   `/home/deploy/cutover/app.db`, mode `600`.
3. Simpan SHA-256-nya untuk jejak: `sha256sum /home/deploy/cutover/app.db`.
4. Ubah nama folder aplikasi Master Menu di mesin klien (mis. `Master Menu Revamp.FROZEN`)
   supaya tidak bisa dibuka tanpa sengaja. Jangan dihapus sampai UAT selesai + 30 hari.

## 2. Deploy kode

```bash
cd /var/www/po-workflow
git fetch && git checkout main && git pull --ff-only
composer install --no-dev --optimize-autoloader
npm ci && npm run build            # atau upload public/build hasil build lokal
php artisan down --secret=cutover  # maintenance; kamu tetap bisa masuk lewat /cutover
```

Tambahkan ke `.env` (tanpa mengubah yang lain):

```
MASTER_MENU_DB_PATH=/home/deploy/cutover/app.db
```

## 3. Backup produksi

```bash
mysqldump --single-transaction --routines po_workflow | gzip > /home/deploy/cutover/pre-cutover-$(date +%F-%H%M).sql.gz
chmod 600 /home/deploy/cutover/*.sql.gz
```

Verifikasi ukurannya masuk akal (`ls -lh`) dan bisa dibaca (`zcat … | head`).
Alternatif: menu Superadmin → Backup di aplikasi (DatabaseBackupService), lalu unduh.

## 4. Migrasi skema, izin, pengaturan

```bash
php artisan migrate --force
php artisan access:sync             # izin modul ke peran (idempoten) -- termasuk peran inventory & inventory-supervisor
# .env: APP_LOCALE=id (kerangka panel -- tombol, pencarian, lonceng notifikasi -- berbahasa Indonesia)
php artisan storage:link           # Katalog Foto Menu (Bagian B.4): public/storage -> storage/app/public
# cron tiap menit untuk jadwal Laravel (profit:check harian 06:30, Bagian B.2):
#   * * * * * cd /path/po-workflow && php artisan schedule:run >> /dev/null 2>&1
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Cek: `php artisan access:sync` menampilkan tabel peran → izin sesuai `docs/ACCESS.md`.

## 5. Gladi bersih di staging (wajib sebelum produksi)

```bash
php artisan db:clone-to-staging --force            # po_workflow -> po_workflow_staging
php artisan inventory:migrate-master-menu --database=mysql_staging --dry-run
php artisan inventory:migrate-master-menu --database=mysql_staging --force
php artisan inventory:map-recipes-to-products --database=mysql_staging
php artisan inventory:validate-migration --database=mysql_staging --fix
```

Lolos bila `validate-migration` mengakhiri dengan **0 error**. Peringatan (warn) boleh ada
tetapi harus tercatat dan disetujui Owner (lihat §7). Kalau ada error: perbaiki di sumber
atau lewat panel di staging, ulangi dari `migrate-master-menu` (idempoten).

## 6. Migrasi data ke produksi

Urutannya sama dengan staging, tanpa `--database`:

```bash
php artisan inventory:migrate-master-menu --dry-run
php artisan inventory:migrate-master-menu --force
php artisan inventory:map-recipes-to-products
php artisan inventory:validate-migration --fix
```

Yang dipindahkan: 305 bahan (+ histori harga), 460 resep / 11.595 baris, 365 bahan belum
cocok, 8 pelaksana, 540 template kerja, 17 SPK riwayat (status selesai, hanya arsip).
Angka pastinya dibandingkan otomatis oleh `validate-migration` (baris "sumber vs tujuan").

Yang **tidak** dipindahkan: ledger stok dimulai kosong; saldo awal tiap bahan diambil dari
kolom Stok Awal pada Form Kebutuhan pertama yang menyebut bahan itu (keputusan Phase 3).

## 7. Keputusan klien yang masih terbuka setelah migrasi

Ini bukan bug; sistem sengaja tidak menebak. Tampil sebagai badge di panel:

| Antrean | Di mana | Siapa |
|---|---|---|
| ~365 nama bahan belum cocok | Inventory → Bahan Belum Cocok | Admin dapur |
| ~178 pasangan satuan tanpa aturan konversi (18 teratas menutup separuh baris) | Inventory → Konversi Satuan → Butuh Aturan | Admin dapur |
| ~380 produk aktif belum ditautkan ke resep (20 terlaris = ⅔ porsi, 60 = 86%) | Inventory → Pencocokan Menu (urut porsi terjual; Tautkan / Tanpa Resep / Buat Resep) | Admin + Owner |

Produk ikut SPK Produksi hanya bila menunjuk resep (`products.recipe_id`; varian harga
10K/12K boleh berbagi satu resep, produk yang memang tidak dimasak ditandai "tanpa resep");
HPP resep hanya lengkap bila pasangan satuannya punya aturan. Target sebelum go-live:
18 aturan teratas + 60 produk terlaris di Pencocokan Menu.

## 8. Pengguna & peran

- Tambahkan peran `production` ke akun tim produksi yang akan mengisi Form Kebutuhan
  (Sistem → Pengguna). Peran ini sekarang membuka panel, terbatas ke grup Produksi.
- Pastikan yang **menyetujui** form (owner/admin) bukan orang yang **menyusun**nya
  (production). Matriks lengkap: `docs/ACCESS.md`.
- Password sementara: paksa ganti saat login pertama (kolom "force password change").

## 9. Buka aplikasi

```bash
php artisan up
```

Smoke test 10 menit sebagai admin (checklist §11, baris 9). Bisa diotomatkan dengan
`scripts/qa/smoke.mjs` (Chrome headless: membuka 20 halaman panel + alur Phase 3 penuh;
kredensial lewat variabel lingkungan, lihat komentar di berkas). Kalau gagal → §10.

## 10. Rollback

Batas waktu keputusan rollback: **2 jam** setelah `php artisan up`. Sesudah itu data baru
(form kebutuhan, ledger) sudah masuk dan rollback berarti kehilangan pekerjaan orang.

```bash
php artisan down --secret=cutover
zcat /home/deploy/cutover/pre-cutover-*.sql.gz | mysql po_workflow
git checkout <tag-sebelum-cutover> && composer install --no-dev
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

Master Menu Revamp yang dibekukan (§1) tetap bisa dipakai kembali karena tidak pernah
diubah.

## 11. Checklist cutover

| # | Langkah | Cara memastikan | Jejak (jam, oleh) |
|---|---|---|---|
| 1 | Master Menu dibekukan, `app.db` + SHA-256 tersimpan | `sha256sum` cocok dengan catatan | |
| 2 | Kode ter-deploy, aset ter-build | halaman login tampil tanpa error Vite | |
| 3 | Backup pra-cutover | file ada, `zcat | head` terbaca | |
| 4 | `migrate`, `access:sync`, cache | `access:sync` tabel sesuai ACCESS.md | |
| 5 | Gladi staging 0 error | keluaran `validate-migration --database=mysql_staging` | |
| 6 | Migrasi produksi 0 error | keluaran `validate-migration` | |
| 7 | Antrean keputusan klien dicatat | angka badge Bahan Belum Cocok / Konversi / Belum Dipetakan | |
| 8 | Peran production diberikan | Sistem → Pengguna | |
| 9 | `php artisan up` + smoke: login → SPK Produksi → Buat dari Slot SPK → Form Kebutuhan → Cetak PDF | PDF terunduh, tidak ada 500 | |
| 10 | UAT (docs/UAT.md) ditandatangani Owner | dokumen UAT | |
| 11 | `MASTER_MENU_DB_PATH` dihapus dari `.env` (sumber tidak diperlukan lagi), `config:cache` | `php artisan inventory:validate-migration` menampilkan "Pembanding sumber dilewati" | |

## 12. Setelah cutover

- Minggu 1–4: jalankan **Perbandingan HPP** (Produksi → Perbandingan HPP) tiap akhir
  bulan; residual opname masih dihitung berdampingan sampai Owner menyetujui angka resep.
- Backup harian: menu Superadmin → Backup, atau cron `mysqldump` seperti §3.
- `php artisan inventory:validate-migration` boleh dijalankan kapan saja (read-only tanpa
  `--fix`); pasang di cron mingguan dengan `--fail-on=error` dan kirim keluarannya.
