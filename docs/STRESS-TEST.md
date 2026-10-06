# Stress Test 3S ONE

Tujuan: memastikan aplikasi **tidak makin lambat seiring data bertambah**, dan menemukan titik yang jebol lebih dulu.
Ulangi sebelum rilis besar / cutover, dan setelah mengubah jalur panas (laporan penjualan, laba rugi, neraca, simpan PO).

## Budget (ditulis sebelum mengukur)

| Ukuran | Budget |
|---|---|
| Waktu server satu halaman, data 12× (±5 tahun operasi) | < 500 ms untuk layar harian, < 1,5 dtk untuk laporan bulanan |
| Jumlah query per halaman | < 60, tanpa N+1 |
| 10 pengguna bersamaan membuka layar harian | p95 < 1,5 dtk |
| 5 admin menyimpan PO bersamaan | 0 error |

## Cara mengulang

Selalu di **`po_workflow_staging`**, jangan di database kerja atau produksi (fase tulis membuat PO sungguhan).

```bash
php artisan db:clone-to-staging --force                                      # salinan segar
DB_DATABASE=po_workflow_staging php artisan migrate --force
DB_DATABASE=po_workflow_staging php scripts/qa/stress/profile.php sekarang   # baseline volume asli
DB_DATABASE=po_workflow_staging php -d memory_limit=3G scripts/qa/stress/inflate.php
DB_DATABASE=po_workflow_staging php -d memory_limit=3G scripts/qa/stress/profile.php besar
# load test HTTP: arahkan .env sementara ke staging, lalu
QA_USER="nama owner" QA_PASS=... node scripts/qa/stress/load.mjs 10 60 5 30
```

`inflate.php` menyalin transaksi 11× (9 salinan mundur 6 bulan per salinan = riwayat ±5 tahun, 2 salinan di tanggal yang sama = kepadatan bulan berjalan 3×) dan membuat kartu stok sintetis 5 tahun. Hasil `profile.php` (waktu, query, N+1, query terlambat per halaman) tersimpan di `storage/app/qa-stress/`.

## Hasil 30 September 2026

Volume asli: 3.486 PO, 12.576 baris Sales Actual, 24 ribu audit log (±6 bulan).
Volume uji 12×: 41.832 PO, 150.924 baris Sales Actual, 370.800 audit log, 255.796 baris kartu stok.

### Waktu server per halaman (satu request)

| Halaman | Volume asli | 12× sebelum | 12× sesudah |
|---|---:|---:|---:|
| Laporan Penjualan setahun | 8,2 dtk | 31,4 dtk | 7,3 dtk |
| Laporan Penjualan sebulan | 1,1 dtk | 3,3 dtk | 0,8–1,0 dtk |
| Laporan Cashflow sebulan | 0,9 dtk | 2,8 dtk | 0,5 dtk |
| Cashflow setahun per hari (9 variasi, uji data) | – | 345 dtk | 8 dtk |
| Audit Logs (Owner / Admin) | 0,58 / 0,46 dtk | 3,1 / 2,8 dtk | 0,08 / 0,03 dtk |
| Barang Sisa | 0,08 dtk | 0,81 dtk | 0,41 dtk |
| Opname Bahan | 0,18 dtk | 1,17 dtk | 0,6 dtk |
| Pencocokan Menu (waktu DB) | 0,10 dtk | 1,25 dtk | 0,20 dtk |
| Neraca / Dashboard Accounting / Laporan Final | 0,6–0,7 dtk | 4,3–5,2 dtk (±440 query) | 5,5–6,2 dtk (81–114 query) |

Output laporan yang dioptimasi dibandingkan **byte per byte** dengan versi lama: Laporan Penjualan (12 kombinasi rentang × mode × segmen, web + ekspor), Cashflow (9 kombinasi), dan Neraca (4 tanggal). Semuanya identik.

### Beban bersamaan (Herd lokal, 5 worker `php-cgi`)

| Skenario | Sebelum | Sesudah |
|---|---|---|
| 5 admin simpan PO, 30 dtk | 340 request, **14 gagal 500** (nomor PO kembar) | 339 request, **0 error**, p95 615 ms |
| 1 pengguna, layar harian | – | p50 397 ms, p95 673 ms |
| 10 pengguna, layar harian | p95 2,7 dtk | p95 2,7 dtk |

p95 dengan 10 pengguna tidak berubah karena request **mengantre di 5 worker PHP** Herd lokal, bukan karena halamannya lambat (dengan 1 pengguna p95 673 ms). Di server produksi, yang menentukan adalah jumlah worker PHP-FPM (`pm.max_children`).

## Yang diperbaiki

1. **Laporan Penjualan**: item dibaca sekali sebagai array (tanpa hidrasi model & cast decimal per akses), peta per baris dibuat jarang, penjumlahan hanya menyapu isi baris. Sebelumnya: baris × ratusan kolom × 7 putaran.
2. **Cashflow**: tanggal tiap entri di-parse sekali per laporan (sebelumnya sekali per hari × per entri).
3. **Neraca**: nilai persediaan untuk semua bahan dalam 3 query (sebelumnya 3 query per bahan).
4. **Filter tanggal kartu stok** (`moved_at`): `>= hari` dan `< hari berikutnya` menggantikan `whereDate()`, supaya index terpakai.
5. **Pencocokan Menu**: porsi terjual 90 hari dihitung dengan satu agregat, bukan subquery per produk yang menyapu seluruh riwayat.
6. **Index baru** (migration `add_performance_indexes_for_growing_tables`): `audit_logs(created_at)`, `audit_logs(action)`, `sales_actuals(status, submitted_at)`, `sales_actual_items(qty_return)`, `purchase_orders(created_at)`, `stock_opnames(inventory_item_id, opname_date)`, dan dua index penutup kartu stok.
7. **Simpan PO bersamaan**: satu transaksi (header + item + audit log), diulang dengan nomor baru bila nomornya keburu dipakai (`AutoNumberService::retryOnDuplicate`).

## Risiko yang tersisa

1. **Laba Ditahan dihitung ulang dari transaksi pertama** setiap kali Neraca, Dashboard Accounting, atau Laporan Final dibuka. Biayanya tumbuh linear dengan umur data: ±0,6 dtk sekarang, ±6 dtk pada ±5 tahun. Solusinya snapshot laba per periode yang sudah **ditutup** (Status Periode), dengan snapshot dibatalkan saat periode dibuka kembali. Ini perubahan desain akuntansi, jadi perlu diputuskan dulu.
2. **Laporan Penjualan untuk rentang setahun** menghasilkan HTML ±90 MB (tiap customer × tiap hari). Browser akan berat berapa pun cepatnya server. Sarannya: batasi tampilan web (mis. maksimal 2 bulan) dan arahkan rentang panjang ke Export Excel.
3. **Laba Rugi tahunan**: 267 query (per bulan dihitung terpisah), ±4,5 dtk pada 12×.
4. **Pencarian global** memakai `LIKE '%kata%'`, sehingga seluruh tabel PO disapu: ±0,3 dtk pada 12×. Masih aman.
5. **Server produksi**: set `pm.max_children` minimal sejumlah pengguna yang aktif bersamaan (±10), aktifkan OPcache, dan jalankan ulang `load.mjs` di staging server sebelum cutover.
6. Sekali tercatat Dashboard Accounting 254 dtk pada data 12×. Tidak terulang pada dua pengukuran berikutnya (±6 dtk, cache kosong) dan tidak ada lock/error di log. Pantau request lambat di produksi (log akses Nginx dengan `$request_time`).
