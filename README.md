# PO-workflow

Aplikasi internal W3S Catering: purchase order → SPK → pengiriman → penjualan aktual,
ditambah inventory, kas/pengeluaran, dan laporan keuangan. Laravel 12 / PHP 8.2 / MySQL,
dua lapis antarmuka: aplikasi Blade per peran (`/owner-app`, `/admin-app`, …) dan
**Inventory App** di `/inventory-app` — panel Filament yang memakai header, warna, font,
pengalih aplikasi, dan sesi login yang sama dengan aplikasi Blade (path lama `/admin`
diarahkan ke sana).

## Modul Inventory Terpadu

Menyatukan Master Menu Revamp (resep & HPP, dulu SQLite lokal) ke dalam po-workflow dan
menggantikan HPP residual opname dengan pemakaian bahan riil dari resep × produksi.

| Phase | Isi | Di panel |
|---|---|---|
| 1 | Item inventaris bertingkat (bucket → bahan), pembelian, opname, saldo awal, laporan mutasi, import/export Excel | Inventory |
| 2 | Resep & sub-resep, Analisa HPP, aturan konversi satuan per bahan + pendeteksi pasangan yang belum diatur, Bahan Belum Cocok (pencocokan nama), import/export resep | Inventory |
| 3 | SPK Produksi dari slot SPK/PO, Form Kebutuhan bertahap (Dibuat → Disetujui → Diperiksa → Tutup SPK), ledger stok per bahan, lembar kerja, plating, PDF, Perbandingan HPP resep vs opname | Produksi |
| 4 | Izin modul (spatie permission) per peran, Pengaturan modul, navigasi terpadu, validasi & pembersihan pasca migrasi, runbook cutover & UAT | Sistem |

Dokumen:

- [docs/ACCESS.md](docs/ACCESS.md) — izin & matriks peran, cara mengubahnya
- [docs/CUTOVER.md](docs/CUTOVER.md) — runbook deploy, migrasi data, rollback
- [docs/UAT.md](docs/UAT.md) — checklist UAT Bagian A bersama Owner

## Perintah artisan modul

| Perintah | Fungsi |
|---|---|
| `inventory:audit-master-menu` | Audit rekonsiliasi Master Menu vs po-workflow (Excel) |
| `inventory:migrate-master-menu {--db} {--database} {--dry-run} {--force}` | Pindahkan bahan, harga, resep, mismatch, pelaksana, template, SPK riwayat (idempoten) |
| `inventory:map-recipes-to-products {--dry-run}` | Petakan resep → produk yang namanya cocok persis |
| `inventory:validate-migration {--fix} {--fail-on=error}` | Laporan validasi pasca migrasi + pembersihan aman |
| `access:sync {--reset}` | Sinkronkan izin modul ke peran |
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

- Inventory App (`/inventory-app`) hanya untuk peran `superadmin`, `owner`, `admin`, `accounting`, `production`;
  di dalamnya setiap resource dijaga policy berbasis izin modul (`tests/Feature/Security/`).
- Tes arsitektur menolak resource/halaman panel baru yang tidak punya policy/`canAccess`.
- Rahasia hanya di `.env` (gitignored; di server mode 600 milik user deploy).
