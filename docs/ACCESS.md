# Hak Akses Modul Inventory Terpadu

Aplikasi ini memakai **peran** (spatie/laravel-permission `Role`) sejak awal: aplikasi
Blade dijaga middleware `ensure.role:`, dan panel inventory (`/inventory`, bagian dari cangkang 3S) dibuka untuk peran di
`User::PANEL_ROLES`. Modul baru (Inventory, Resep, Produksi) menambah lapisan **izin**
di atasnya supaya pemilik bisa menggeser hak per peran tanpa mengubah kode.

Sumber kebenaran daftar izin dan matriks bawaannya: `app/Support/Access/ModuleAccess.php`.
Policy di `app/Policies/*Policy.php` (turunan `ModulePolicy`) dan halaman khusus
(`canAccess()`) membaca izin itu. Tes yang menjaganya: `tests/Feature/Security/ModuleAccessTest.php`
(tes arsitektur membaca daftar resource panel yang sebenarnya, jadi resource baru tanpa
policy membuat build merah).

## Izin

| Izin | Membuka |
|---|---|
| `inventory.view` / `inventory.manage` | Item Inventaris, Pembelian Bahan Baku, Stock Opname, Saldo Awal, Konversi Satuan, Laporan Mutasi Stok (+ import/export) |
| `recipe.view` / `recipe.manage` | Resep & Menu, Analisa HPP, Bahan Belum Cocok (tautkan/buat/abaikan), import/export resep |
| `production.view` / `production.manage` | SPK Produksi (buat dari slot, segarkan, siap, batalkan), Form Kebutuhan (susun, isi, simpan), Lembar Kerja, Plating, Pelaksana |
| `production.complete` | Tutup SPK (posting pemakaian & penyesuaian ke ledger) |
| `requisition.approve` | Setujui Form Kebutuhan |
| `requisition.check` | Periksa Form Kebutuhan (barang dibeli; posting saldo awal & pembelian ke ledger) |
| `ledger.view` | Ledger Stok, Perbandingan HPP |
| `settings.manage` | Pengaturan modul |

`view` = daftar & detail; `manage` = tambah/ubah/hapus. Superadmin lolos semua lewat
`Gate::before`, tanpa perlu izin eksplisit.

## Matriks bawaan

| Izin | owner | admin | accounting | production |
|---|:-:|:-:|:-:|:-:|
| inventory.view | ✓ | ✓ | ✓ | ✓ |
| inventory.manage | ✓ | ✓ | ✓ | – |
| recipe.view | ✓ | ✓ | ✓ | ✓ |
| recipe.manage | ✓ | ✓ | – | – |
| production.view | ✓ | ✓ | ✓ | ✓ |
| production.manage | ✓ | ✓ | – | ✓ |
| production.complete | ✓ | ✓ | – | ✓ |
| requisition.approve | ✓ | ✓ | – | – |
| requisition.check | ✓ | ✓ | ✓ | – |
| ledger.view | ✓ | ✓ | ✓ | ✓ |
| settings.manage | ✓ | – | – | – |

`sales` dan `delivery` tidak membuka panel sama sekali (bukan `PANEL_ROLES`). Peran
`production` **ditambahkan** ke `PANEL_ROLES` di Phase 4 karena Form Kebutuhan
menggantikan form kertas yang diisi tim produksi.

Pemisahan tahap Form Kebutuhan: yang menyusun/mengisi (produksi) bukan yang menyetujui
(owner/admin), dan yang memeriksa saat barang dibeli (accounting/admin) bukan yang menutup
SPK (produksi/admin).

## Mengubah hak

- Geser izin lewat database (mis. tinker: `Role::findByName('production')->givePermissionTo('recipe.manage')`),
  lalu `php artisan permission:cache-reset`. Pengubahan manual **tidak** ditimpa `access:sync` biasa.
- `php artisan access:sync` — dijalankan setiap deploy; menambah izin/peran yang belum ada dan
  memberikan izin bawaan yang hilang, tanpa mencabut yang ditambahkan manual.
- `php artisan access:sync --reset` — kembalikan setiap peran persis ke matriks bawaan.
- Izin baru: tambahkan ke `ModuleAccess::PERMISSIONS` + `DEFAULT_MATRIX`, pakai di policy/aksi,
  dan tambahkan kasusnya ke `ModuleAccessTest`.

Perhatian: `mountAction` Livewire bisa dipanggil dari konsol browser meski tombolnya
tersembunyi, jadi aksi bertahap memakai `->authorize('izin')` (Filament menolak
mount/call untuk aksi yang tidak berizin) **dan** metode `save()` halaman memeriksa izin
sendiri (`abort_unless`).
