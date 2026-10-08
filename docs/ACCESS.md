# Hak Akses Modul Inventory Terpadu

Aplikasi ini memakai **peran** (spatie/laravel-permission `Role`) sejak awal: aplikasi
Blade dijaga middleware `ensure.role:`, panel Inventory (`/inventory`, bagian dari cangkang 3S) dibuka untuk peran di
`User::PANEL_ROLES`, dan panel Menu (`/menu`) untuk peran di `User::MENU_ROLES`. Modul baru (Inventory, Resep, Produksi) menambah lapisan **izin**
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
| `recipe.view` / `recipe.manage` | Aplikasi Menu: Menu Utama & Sub Menu, rincian HPP, Pencocokan Menu, Pekerjaan Menu, Bahan Belum Cocok (tautkan/buat/abaikan), import/export resep. Konversi Satuan terbuka untuk `inventory.*` **atau** `recipe.*` |
| `production.view` / `production.manage` | SPK Produksi (buat dari slot, segarkan, siap, batalkan), Form Kebutuhan (susun, isi, simpan), Lembar Kerja, Plating, Pelaksana |
| `production.complete` | Tutup SPK (posting pemakaian & penyesuaian ke kartu stok) |
| `requisition.approve` | Setujui Form Kebutuhan |
| `requisition.check` | Penerimaan barang di Form Kebutuhan (Diterima/Ditolak/Harga Beli/supplier) dan Periksa (posting saldo awal & pembelian ke kartu stok, membuat Pembelian Bahan Baku + satu Tagihan Pembelian berikut hutangnya). Juga melengkapi & mengajukan Tagihan Pembelian dan mencatat Belanja Lepas |
| `purchase.pay` | Accounting › Tagihan Pembelian: bayar (kas keluar sebagai pelunasan hutang), jadikan hutang supplier, atau kembalikan ke gudang; menerima lonceng tagihan baru |
| `ledger.view` | Kartu Stok (di UI; kunci izinnya tetap `ledger`), Perbandingan HPP |
| `settings.manage` | Pengaturan modul |
| `notification.price` | Menerima lonceng perubahan harga bahan & harga jual menu (Bagian B.1) |
| `notification.profit` | Menerima lonceng profit menu keseluruhan keluar dari batas (Bagian B.2) |

`view` = daftar & detail; `manage` = tambah/ubah/hapus. Superadmin lolos semua lewat
`Gate::before`, tanpa perlu izin eksplisit.

## Matriks bawaan

| Izin | owner | admin | accounting | inventory | inventory-supervisor | menu | production |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| inventory.view | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ |
| inventory.manage | ✓ | ✓ | ✓ | ✓ | ✓ | – | – |
| recipe.view | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| recipe.manage | ✓ | – | – | – | – | ✓ | – |
| production.view | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ |
| production.manage | ✓ | ✓ | – | – | – | – | ✓ |
| production.complete | ✓ | ✓ | – | – | – | – | ✓ |
| requisition.approve | ✓ | ✓ | – | – | ✓ | – | – |
| requisition.check | ✓ | ✓ | ✓ | ✓ | ✓ | – | – |
| ledger.view | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| settings.manage | ✓ | – | – | – | – | – | – |
| notification.price | ✓ | ✓ | ✓ | – | ✓ | ✓ | – |
| notification.profit | ✓ | ✓ | – | – | – | ✓ | – |
| purchase.pay | ✓ | – | ✓ | – | – | – | – |

**Revisi 7 Okt 2026 — aplikasi Menu.** Resep, menu utama, sub menu, HPP, dan OHC dipegang
peran baru **`menu`** (tim menu/dapur pusat) di aplikasi Menu (`/menu`). `recipe.manage`
dicabut dari admin, inventory, dan inventory-supervisor: mereka tetap **membaca** resep di
aplikasi Menu, tapi tidak mengubahnya. Admin mengurus harga jual & profit ke customer di
Admin › Master Menu. Karena `access:sync` biasa tidak pernah mencabut izin, server yang sudah
berjalan **wajib** `php artisan access:sync --reset` sekali saat deploy revisi ini (catat dulu
izin yang digeser manual, karena `--reset` mengembalikannya ke matriks di atas).

Peran **`inventory-supervisor`** (Supervisor Gudang) = staf inventory + `requisition.approve`:
dialah yang menyetujui atau menolak Form Kebutuhan yang **diajukan** produksi (status
`submitted`). Owner/admin tetap bisa menyetujui sebagai cadangan. Alur meja:
produksi *Ajukan* → supervisor *Setujui* / *Tolak* (kembali ke produksi dengan alasan) →
gudang *Periksa* → produksi *Tutup SPK*; tiap perpindahan mengirim notifikasi lonceng.

Peran **`inventory`** (staf inventory/gudang) dipilih Owner di Master User dan hanya
membuka aplikasi Inventory (dan Menu, hanya baca) -- tidak punya aplikasi Blade manapun. Dia
mengelola bahan, pembelian, opname, saldo awal, dan memeriksa Form
Kebutuhan saat barang dibeli; menyusun/menyetujui/menutup SPK produksi tetap di
produksi/admin/owner.

Peran **`marketing`** hanya membuka aplikasi Blade Marketing (`/marketing-app`): Katalog Foto
Menu dan centang menu yang tampil di website. Tanpa izin modul apa pun. Admin/sales yang juga
mengurus katalog diberi peran tambahan `marketing` di Master User.

`sales`, `marketing`, dan `delivery` tidak membuka panel sama sekali (bukan `PANEL_ROLES`). Peran
`production` **ditambahkan** ke `PANEL_ROLES` di Phase 4 karena Form Kebutuhan
menggantikan form kertas yang diisi tim produksi.

Pemisahan tahap Form Kebutuhan: yang menyusun/mengisi (produksi) bukan yang menyetujui
(owner/admin), dan yang memeriksa saat barang dibeli (accounting/admin) bukan yang menutup
SPK (produksi/admin).

**Revisi 7 Okt 2026 — Tagihan Pembelian.** Gudang tidak lagi memilih akun kas saat Periksa
Form Kebutuhan. Belanja satu form menjadi satu Tagihan Pembelian (draft); hutangnya lahir saat
barang diterima supaya Neraca seimbang. Gudang melampirkan nota lalu *Ajukan*; accounting
(`purchase.pay`) *Bayar* / *Jadikan hutang supplier* / *Kembalikan*. Hutang milik tagihan tidak
muncul di Pengeluaran › Pembayaran Kredit dan ditolak bila dikirim lewat sana (satu pintu).
Pembelian milik tagihan tidak bisa diubah/dihapus satuan. Server perlu `access:sync` (menambah
`purchase.pay`; `--reset` sudah diperlukan untuk revisi aplikasi Menu di atas).

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
