# PO-workflow

Aplikasi internal W3S Catering: purchase order → SPK → pengiriman → penjualan aktual,
ditambah inventory, kas/pengeluaran, dan laporan keuangan. Laravel 12 / PHP 8.2 / MySQL,
satu antarmuka **3S ONE** (Business Control System): bilah aplikasi di atas (Owner, Admin,
Accounting, Inventory, Sales, Production, Delivery) dan menu aplikasi di sidebar, didefinisikan
sekali di `App\Support\Navigation` dan dirender oleh layout Blade (`layouts.shell`) maupun panel
Filament di `/inventory` (inventory, resep, produksi, pengaturan). Satu login (`/login`), satu
sesi, satu tampilan (`resources/css/shell.css` dipakai keduanya); path lama `/admin` diarahkan ke
`/inventory`.

## Modul Inventory Terpadu

Menyatukan Master Menu Revamp (resep & HPP, dulu SQLite lokal) ke dalam po-workflow dan
menggantikan HPP residual opname dengan pemakaian bahan riil dari resep × produksi.

| Phase | Isi | Di panel |
|---|---|---|
| 1 | Item inventaris bertingkat (bucket → bahan), pembelian, opname, saldo awal, laporan mutasi, import/export Excel | Inventory |
| 2 | Resep & sub-resep, Analisa HPP, aturan konversi satuan per bahan + pendeteksi pasangan yang belum diatur, Bahan Belum Cocok (pencocokan nama), Pencocokan Menu (master produk Admin App ↔ resep, berbasis porsi terjual 90 hari; satu resep boleh dipakai beberapa varian harga, tautan di `products.recipe_id`; export/import Excel sebagai lembar kerja staf: terima_usulan / resep_id / tanpa_resep per baris, baris kosong tidak diubah), import/export resep | Inventory |
| 3 | SPK Produksi dari slot SPK/PO, Form Kebutuhan bertahap per meja (Dibuat oleh produksi → Diajukan → Disetujui/Ditolak oleh supervisor gudang, dengan notifikasi lonceng → Penerimaan barang: diterima/ditolak/harga beli → Diperiksa: kartu stok + Pembelian Bahan Baku + kas/hutang otomatis → Tutup SPK), kartu stok per bahan, lembar kerja, plating, PDF, Perbandingan HPP resep vs opname | Produksi |
| 4 | Izin modul (spatie permission) per peran, Pengaturan modul, cangkang & menu 3S terpadu (satu sumber untuk Blade dan Filament), validasi & pembersihan pasca migrasi, runbook cutover & UAT | Sistem |
| B | Notifikasi perubahan harga bahan & harga jual menu (lonceng di semua aplikasi), notifikasi profit menu keseluruhan keluar dari batas atas/bawah (Pengaturan Inventory; `profit:check` harian), laporan Menu Tidak Diproduksi (rentang bawaan di Pengaturan, export Excel), Katalog Foto Menu berbasis SKU (Admin mengelola, Sales melihat; foto dikompres otomatis ke JPG 1600 px) | Inventory / Admin / Sales |

## Pencarian global

Tombol **Cari dokumen…** di atas sidebar (pintasan <kbd>Ctrl</kbd>/<kbd>Cmd</kbd>+<kbd>K</kbd>)
membuka panel pencarian sebagai overlay; tersedia di layout Blade maupun panel Filament.
Menemukan Purchase Order, Delivery Order, SPK Produksi, pelanggan, menu, dan bahan — termasuk
lewat kolom relasinya (nomor PO ketemu dari nama pelanggannya).

Letaknya **bukan** di bilah atas dan itu disengaja: bilah itu memuat sampai 8 tab aplikasi untuk
superadmin/owner, dan menambahkan apa pun di sana — bahkan tombol ikon 38px — membuat tab terakhir
terpotong. Penempatan ini dijaga tes (`tests/Feature/GlobalSearchTest.php`), bukan sekadar
kesepakatan.

Aturannya: **sebuah hasil hanya muncul bila peran pengguna memang boleh membuka halaman
tujuannya.** Jadi Sales tidak menemukan PO, Admin tidak menemukan DO, dan satu PO yang sama
menautkan Admin ke detail Admin App tetapi Produksi ke halaman progres Production App. Sumber yang
tidak punya tujuan yang boleh dibuka tidak ikut dicari sama sekali. Daftar sumber beserta pagarnya
ada di `App\Services\GlobalSearchService::sources()`; menambah entitas baru berarti menuliskan
pagarnya di sana, dan tes arsitektur di `tests/Feature/Security/GlobalSearchAccessTest.php`
membuat build merah bila ada sumber tanpa pagar.

Tanpa JavaScript tombolnya menjadi tautan biasa ke `/search`, dan halaman itu membawa form GET-nya
sendiri — jadi pencarian tetap bisa dilakukan.

Dokumen:

- `version.txt` — riwayat rilis; **baris terakhirnya** jadi nomor versi yang tampil di halaman masuk (`App\Support\AppVersion`). Rilis baru = tambah satu baris `v.X.Y keterangan`.
- [docs/PANDUAN-3S-ONE.pdf](docs/PANDUAN-3S-ONE.pdf) — **Panduan Pengguna** (bahasa awam, dengan diagram alur). Sumbernya `docs/panduan/panduan-3s-one.html`; bangun ulang dengan `php artisan panduan:pdf`.
- [docs/ACCESS.md](docs/ACCESS.md) — izin & matriks peran, cara mengubahnya
- [docs/CUTOVER.md](docs/CUTOVER.md) — runbook deploy, migrasi data, rollback
- [docs/UAT.md](docs/UAT.md) — checklist UAT Bagian A bersama Owner

## Perintah artisan modul

| Perintah | Fungsi |
|---|---|
| `inventory:audit-master-menu` | Audit rekonsiliasi Master Menu vs po-workflow (Excel) |
| `inventory:migrate-master-menu {--db} {--database} {--dry-run} {--force}` | Pindahkan bahan, harga, resep, mismatch, pelaksana, template, SPK riwayat (idempoten) |
| `inventory:map-recipes-to-products {--dry-run}` | Tautkan produk → resep yang namanya cocok persis (sisanya lewat Pencocokan Menu) |
| `inventory:validate-migration {--fix} {--fail-on=error}` | Laporan validasi pasca migrasi + pembersihan aman |
| `access:sync {--reset}` | Sinkronkan izin modul ke peran |
| `profit:check` | Periksa profit menu keseluruhan terhadap batas; lonceng bila berubah keadaan (dijadwalkan harian 06:30) |
| `db:clone-to-staging` | Salin database kerja ke `po_workflow_staging` |
| `po:audit-cash-in {--fix}` | Audit data kas PO lama |

## Pengembangan

Dilayani Laravel Herd di `http://po-workflow.test` (jangan jalankan `php artisan serve`).

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate && php artisan db:seed      # peran + izin modul ikut tersemai
npm run dev                                      # atau npm run build
php artisan test --compact                       # Pest, SQLite :memory:
vendor/bin/pint --dirty
```

`MASTER_MENU_DB_PATH` di `.env` menunjuk `app.db` Master Menu untuk migrasi/audit; kosongkan
bila tidak ada.

## Keamanan

- Halaman inventory (`/inventory/...`) hanya untuk peran `superadmin`, `owner`, `admin`, `accounting`, `inventory` (staf gudang, dipilih di Master User), `production`;
  setiap resource dijaga policy berbasis izin modul (`tests/Feature/Security/`). Halaman Blade dijaga `ensure.role`.
- Tes arsitektur menolak resource/halaman panel baru yang tidak punya policy/`canAccess`.
- Pencarian global menyaring per jenis dokumen dan gagal tertutup: sumber tanpa tujuan yang boleh
  dibuka tidak dicari. Endpoint saran dibatasi 60 permintaan/menit.
- Rahasia hanya di `.env` (gitignored; di server mode 600 milik user deploy).
