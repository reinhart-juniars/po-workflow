# PO-workflow

Aplikasi internal W3S Catering: purchase order → SPK → pengiriman → penjualan aktual,
ditambah inventory, kas/pengeluaran, dan laporan keuangan. Laravel 12 / PHP 8.2 / MySQL,
satu antarmuka **3S ONE** (Business Control System): bilah aplikasi di atas (Owner, Admin,
Accounting, Inventory, Menu, Sales, Marketing, Production, Delivery) dan menu aplikasi di sidebar,
didefinisikan sekali di `App\Support\Navigation` dan dirender oleh layout Blade (`layouts.shell`)
maupun dua panel Filament: `/inventory` (inventory, pembelian, produksi, pengaturan) dan `/menu`
(resep, menu utama & sub menu, HPP & OHC). Cangkang panel dibagi lewat `ConfiguresShellPanel`. Satu login (`/login`), satu
sesi, satu tampilan (`resources/css/shell.css` dipakai keduanya); path lama `/admin` diarahkan ke
`/inventory`.

Tampilan aplikasi Blade meniru tabel panel Filament: filter dalam popover ikon dengan chip
"Filter aktif" (`<x-table-toolbar>`), aksi baris berupa ikon (`<x-row-action>`), dan animasi geser
antar menu. Acuan, angka, dan penyimpangan yang disengaja ada di [`docs/DESIGN.md`](docs/DESIGN.md).

## Modul Inventory Terpadu

Menyatukan Master Menu Revamp (resep & HPP, dulu SQLite lokal) ke dalam po-workflow dan
menggantikan HPP residual opname dengan pemakaian bahan riil dari resep × produksi.

| Phase | Isi | Di panel |
|---|---|---|
| 1 | Item inventaris bertingkat (bucket → bahan), Master Supplier (kontak, rekening, termin bayar, bahan yang dipasok, riwayat pembelian; pembelian/form kebutuhan/hutang menyimpan `supplier_id` + salinan `supplier_name`, termin mengisi jatuh tempo kredit yang kosong), pembelian, opname, saldo awal, laporan mutasi, import/export Excel | Inventory |
| 2 | Resep & sub-resep, Analisa HPP, aturan konversi satuan per bahan + pendeteksi pasangan yang belum diatur, Bahan Belum Cocok (pencocokan nama), Pencocokan Menu (master produk Admin App ↔ resep, berbasis porsi terjual 90 hari; satu resep boleh dipakai beberapa varian harga, tautan di `products.recipe_id`; export/import Excel sebagai lembar kerja staf: terima_usulan / resep_id / tanpa_resep per baris, baris kosong tidak diubah), import/export resep | Inventory |
| 3 | SPK Produksi dari slot SPK/PO, Form Kebutuhan bertahap per meja (Dibuat oleh produksi → Diajukan → Disetujui/Ditolak oleh supervisor gudang, dengan notifikasi lonceng → Penerimaan barang: diterima/ditolak/harga beli → Diperiksa: kartu stok + Pembelian Bahan Baku + kas/hutang otomatis → Tutup SPK), kartu stok per bahan, lembar kerja, plating, PDF, Perbandingan HPP resep vs opname | Produksi |
| 4 | Izin modul (spatie permission) per peran, Pengaturan modul, cangkang & menu 3S terpadu (satu sumber untuk Blade dan Filament), validasi & pembersihan pasca migrasi, runbook cutover & UAT | Sistem |
| B | Notifikasi perubahan harga bahan & harga jual menu (lonceng di semua aplikasi), notifikasi profit menu keseluruhan keluar dari batas atas/bawah (Pengaturan Inventory; `profit:check` harian), laporan Menu Tidak Diproduksi (rentang bawaan di Pengaturan, export Excel), Katalog Foto Menu berbasis SKU di aplikasi **Marketing** (peran `marketing`; centang menu yang tampil di website, SKU diketik manual karena menjadi nama menu di website; foto dikompres otomatis ke JPG 1600 px) | Inventory / Admin / Marketing |
| MM | Paritas Master Menu Revamp: **Breakdown Bahan** per PO (Admin › Detail PO) dan per SPK Produksi (menu → bahan mentah, rekap per bahan dicocokkan dengan Kartu Stok → Perlu Beli; `MaterialBreakdownService`), daftar Resep & Menu dengan Harga Jual/Profit %/Margin % + filter *Profit di bawah target* + export Daftar Menu, harga manual per baris resep, export riwayat harga bahan, Plating *Komponen per Menu* + Excel 2 sheet, Excel Breakdown & Lembar Kerja, halaman **Pekerjaan Menu** (template per menu + import/export Excel, membaca berkas Master Menu), migrasi dokumen produksi tanpa Pra SPK | Inventory / Admin |

## Revisi pasca presentasi Inventory (Okt 2026)

- **Aplikasi Menu** (`/menu`, peran baru `menu`): Menu Utama & Sub Menu, Pencocokan Menu, Pekerjaan
  Menu, Konversi Satuan, Bahan Belum Cocok, Perbandingan HPP, Menu Tidak Diproduksi. Admin, inventory,
  dan inventory-supervisor hanya **membaca** resep (`recipe.manage` dicabut; server: `access:sync --reset`).
  Tautan lama `/inventory/<resep...>` dialihkan 301.
- **HPP & OHC di Admin › Master Menu ikut resep** bila hitungan resepnya bersih (`ProductRecipeCostSync`,
  `products.cost_source`); field terkunci, import Excel mengabaikannya. Resep belum lengkap tetap manual
  dengan alasan di form. `menu:sync-product-costs` dijadwalkan 06:15.
- **Breakdown SPK Produksi bertingkat**: menu → sub menu → bahan, buka-tutup berpanah
  (`RecipeCostService::tree`, `partials/recipe-tree`).
- **Purchasing — Tagihan Pembelian**: gudang tidak lagi memilih akun kas. Periksa Form Kebutuhan
  (atau Belanja Lepas di Inventory › Pembelian) membuat pembelian bertipe `bill` + satu tagihan draft
  dengan hutangnya (Neraca seimbang sebelum dibayar). Gudang melampirkan foto nota (disk privat) lalu
  mengajukan; Accounting › Tagihan Pembelian membayar (kas keluar kategori Pembayaran Hutang), menjadikan
  hutang supplier, atau mengembalikan. Hutang tagihan hanya dibayar dari sana (`Payable::settleableByCredit`).
  Izin baru `purchase.pay`. Lihat `PurchaseBillService`.
- **Biaya Marketing di luar Laba Rugi**: mode kategori `wealth_reduction` ("Mengurangi Kekayaan"),
  di-port dari kode server; berlaku sejak `wealth_reduction.accounting_start` (bawaan 1 Okt 2026),
  tampil sebagai pengurang Kekayaan di Neraca. Satu scope `CashOut::inProfitAndLoss()` untuk semua
  laporan.
- **Admin = mini CRM**: sidebar Customer / Pesanan / Menu & Harga / Laporan; daftar customer dengan order
  terakhir, omzet 90 hari, status (baru / aktif / lama tidak order / belum pernah); profil customer
  (`CustomerInsightService`). Owner Dashboard: pie chart kategori diganti batang berurutan.
- **Satu bahasa visual** untuk Blade, kedua panel, dan halaman masuk (portal korporat, tanpa data contoh);
  angka & penyimpangan di [`docs/DESIGN.md`](docs/DESIGN.md).

## Barang Sisa, Barang Hilang & Susut Bahan (revisi Owner, v3.1)

- **Barang Sisa** (aplikasi Sales › Barang Sisa): retur Sales Actual yang disubmit tidak lagi otomatis
  dibawa ke draft customer yang sama. Retur masuk stok Barang Sisa (dihitung dari data, tanpa tabel
  saldo: `qty_return` − Penjualan Barang Sisa − `leftover_disposals`; `LeftoverStockService`) dan boleh
  dijual ke customer mana pun ("Penjualan Barang Sisa", dari halaman Barang Sisa atau panel di edit
  Sales Actual) atau dibuang dengan alasan. Cara bayar Penjualan Barang Sisa mengikuti PO customer
  pembeli di DO-nya (`sales_actual_items.purchase_order_id`); Sales Actual tanpa DO hanya untuk customer
  asal retur.
- Nilainya **HPP menu** (snapshot bahan baku di item). Retur yang belum terjual per akhir periode adalah
  persediaan: baris *Barang Sisa - Persediaan Akhir* di Neraca, *Barang Sisa Awal/Akhir* di blok HPP Laba
  Rugi (awal menambah, akhir mengurangi Bahan Baku Terpakai). Hanya retur sejak pengaturan
  `leftover.accounting_start` (bawaan 1 Okt 2026) supaya laporan bulan yang sudah dilaporkan tidak bergeser.
- **Rincian Barang Sisa per komponen** (adendum Owner, Okt 2026): sebagian porsi retur dipecah user menjadi
  komponen yang **diketik bebas** (nasi, telur, …) dengan nilai HPP yang juga diketik, acuannya HPP porsi
  (`leftover_breakdowns` + `leftover_components`). Total nilai tidak boleh melebihi HPP porsi yang dirinci;
  selisihnya waste pada tanggal rincian. Tiap komponen dijual sesuai yang diambil (Penjualan Barang Sisa dengan
  `leftover_component_id`, tanpa `product_id`, harga diisi Sales) atau di-waste; rincian bisa diubah/dibatalkan
  selama belum ada komponen yang terpakai. Persediaan = porsi utuh × HPP menu + sisa komponen × nilai/satuan.
- **Porsi Tambahan & ganti menu**: customer yang mengganti isi PO setelah dimasak (10+5 jadi 13+2) dicatat di
  Sales Actual: qty actual menu lama dikurangi (retur → Barang Sisa), menu pengganti lewat Porsi Tambahan
  (`is_extra_portion`, harga & cara bayar dari PO baris acuannya). Ubah PO di Admin menyegarkan SPK Produksi
  yang belum ditutup secara otomatis; bila SPK Produksi sudah ditutup, menu/qty PO ditolak dan diarahkan ke
  Sales Actual.
- **Barang Hilang / Barang Temuan**: penyesuaian kartu stok (hitung sisa saat Tutup SPK atau halaman
  **Opname Bahan** di Inventory) bertanda negatif/positif. Tampil sebagai rincian di bawah Bahan Baku
  Terpakai ("termasuk …") — terpisah dari Kerugian Barang Rusak di Pengeluaran, dan tidak mengubah Laba.
- **Susut Bahan** (Inventory › Resep & HPP): per bahan, susut = lebih pakai dari resep + hilang − temuan,
  dibagi kebutuhan resep; indikator hijau/kuning/merah (bawaan < 1% / 1–10% / > 10%, Pengaturan
  Inventory), klik bahan untuk melihat SPK dan menu yang memakainya (`InventoryShrinkageService`).

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
- [docs/DEPLOY-DIGITALOCEAN.md](docs/DEPLOY-DIGITALOCEAN.md) — deploy server baru DigitalOcean (3sone.w3scatering.com): latihan & pemetaan Oktober, resmi 1 November
- [docs/CUTOVER.md](docs/CUTOVER.md) — runbook deploy, migrasi data, rollback
- [docs/UAT.md](docs/UAT.md) — checklist UAT Bagian A bersama Owner

## Perintah artisan modul

| Perintah | Fungsi |
|---|---|
| `inventory:audit-master-menu` | Audit rekonsiliasi Master Menu vs po-workflow (Excel) |
| `inventory:migrate-master-menu {--db} {--database} {--dry-run} {--force}` | Pindahkan bahan, harga, resep, mismatch, pelaksana, template, SPK riwayat (idempoten) |
| `inventory:map-recipes-to-products {--dry-run}` | Tautkan produk → resep yang namanya cocok persis (sisanya lewat Pencocokan Menu) |
| `inventory:validate-migration {--fix} {--fail-on=error}` | Laporan validasi pasca migrasi + pembersihan aman |
| `inventory:carry-master-data --from-database= {--database} {--dry-run} {--force}` | Bawa data master (bahan, resep, pemetaan menu, konversi, supplier, pengaturan, akun & peran, riwayat produksi Master Menu) dari database latihan ke database resmi yang baru dimigrasi dari dump v2; ID bahan dipetakan ulang, transaksi latihan ditinggal. Lihat `docs/DEPLOY-DIGITALOCEAN.md` §14 |
| `access:sync {--reset}` | Sinkronkan izin modul ke peran |
| `profit:check` | Periksa profit menu keseluruhan terhadap batas; lonceng bila berubah keadaan (dijadwalkan harian 06:30) |
| `menu:sync-product-costs` | Hitung ulang HPP & OHC produk yang bertaut resep (dijadwalkan harian 06:15; perubahan resep/harga bahan sudah tersinkron otomatis) |
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
