<?php

namespace App\Support\Access;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Satu-satunya daftar izin modul Inventory Terpadu dan matriks peran bawaannya.
 *
 * Aplikasi ini sudah lama berjalan dengan peran (spatie role) tanpa izin
 * satupun: setiap policy mengembalikan true dan pagar sesungguhnya ada di
 * middleware peran. Modul baru (inventory, resep, produksi) dijaga lewat izin
 * spatie supaya pemilik bisa menggeser hak per peran tanpa mengubah kode --
 * tetapi daftar izinnya sendiri ditulis di sini, bukan di database, supaya
 * policy, seeder, dan tes membaca sumber yang sama.
 *
 * Nama izin: {modul}.{kemampuan}. "view" membuka daftar/detail; "manage"
 * membuat, mengubah, dan menghapus. Kemampuan bertahap pada Form Kebutuhan
 * dipisah sendiri karena satu orang tidak boleh menyusun, menyetujui, dan
 * memeriksa formnya sendiri.
 */
class ModuleAccess
{
    /** Peran yang mendapat semua izin tanpa perlu disebut satu per satu. */
    public const SUPER_ROLES = ['superadmin'];

    /**
     * Peran lama => peran pengganti. Supervisor Gudang digabung ke Inventory
     * (12 Okt 2026): di W3S bagian inventory dipegang satu orang.
     *
     * @var array<string, string>
     */
    public const RETIRED_ROLES = [
        'inventory-supervisor' => 'inventory',
    ];

    /**
     * @var array<string, string> izin => label (Bahasa Indonesia)
     */
    public const PERMISSIONS = [
        'inventory.view' => 'Lihat inventory (bahan, supplier, pembelian, opname, saldo awal, konversi satuan)',
        'inventory.manage' => 'Kelola inventory (tambah/ubah/hapus)',
        'recipe.view' => 'Lihat resep, HPP, dan bahan belum cocok',
        'recipe.manage' => 'Kelola resep (termasuk import/export dan pencocokan bahan)',
        'production.view' => 'Lihat SPK produksi, form kebutuhan, lembar kerja, plating',
        'production.manage' => 'Kelola SPK produksi (susun, isi form, lembar kerja, pelaksana)',
        'production.complete' => 'Tutup SPK produksi (posting pemakaian ke kartu stok)',
        'plan.manage' => 'Susun Rencana Masak (stok siap untuk lapak) beserta rencana belanjanya',
        'requisition.approve' => 'Setujui form kebutuhan',
        'requisition.check' => 'Periksa form kebutuhan (barang dibeli)',
        'ledger.view' => 'Lihat kartu stok dan perbandingan HPP',
        'settings.manage' => 'Ubah pengaturan modul',
        'notification.price' => 'Terima notifikasi perubahan harga bahan & harga jual menu',
        'notification.profit' => 'Terima notifikasi profit menu keluar dari batas',
        'purchase.pay' => 'Bayar / jadikan hutang / kembalikan Tagihan Pembelian dari gudang (Accounting)',
    ];

    /**
     * Matriks bawaan. Yang ditulis di sini hanya izin awal; pemilik boleh
     * menggesernya di database dan `access:sync` tidak akan menimpanya kecuali
     * dipaksa (`--reset`).
     *
     * @var array<string, list<string>> peran => izin
     */
    public const DEFAULT_MATRIX = [
        'owner' => [
            'inventory.view', 'inventory.manage',
            'recipe.view', 'recipe.manage',
            'production.view', 'production.manage', 'production.complete',
            'plan.manage',
            'requisition.approve', 'requisition.check',
            'ledger.view',
            'settings.manage',
            'notification.price', 'notification.profit',
            'purchase.pay',
        ],
        // Admin mengurus customer & harga jual (Admin › Master Menu); resep dan
        // biayanya hanya dibaca -- dipegang tim menu (revisi 7 Okt 2026).
        'admin' => [
            'inventory.view', 'inventory.manage',
            'recipe.view',
            'production.view', 'production.manage', 'production.complete',
            'plan.manage',
            'requisition.approve', 'requisition.check',
            'ledger.view',
            'notification.price', 'notification.profit',
        ],
        'accounting' => [
            'inventory.view', 'inventory.manage',
            'recipe.view',
            'production.view',
            'requisition.check',
            'ledger.view',
            'notification.price',
            'purchase.pay',
        ],
        // Inventory/gudang (dipegang satu orang): kelola bahan, pembelian,
        // opname, saldo awal; menyetujui / menolak Form Kebutuhan yang
        // diajukan produksi, lalu memeriksanya saat barang dibeli. Resep hanya
        // dibaca. Tidak menyusun/menutup SPK produksi.
        // Revisi v3.1: gudang juga menyusun Rencana Masak (stok siap lapak) dan
        // rencana belanjanya; SPK Produksi slot tetap dipegang produksi.
        'inventory' => [
            'inventory.view', 'inventory.manage',
            'recipe.view',
            'production.view', 'plan.manage',
            'requisition.approve', 'requisition.check',
            'ledger.view',
            'notification.price',
        ],
        // Tim menu: susun resep menu utama & sub menu, HPP, OHC, pencocokan
        // ke produk, konversi satuan. Membuka aplikasi Menu saja.
        'menu' => [
            'recipe.view', 'recipe.manage',
            'ledger.view',
            'notification.price', 'notification.profit',
        ],
        'production' => [
            'inventory.view',
            'recipe.view',
            'production.view', 'production.manage', 'production.complete',
            'ledger.view',
        ],
        'sales' => [],
        // Marketing hanya membuka aplikasi Marketing (katalog foto & menu website),
        // tidak menyentuh modul inventory.
        'marketing' => [],
        'delivery' => [],
    ];

    /**
     * Pastikan seluruh izin dan peran ada, lalu berikan izin bawaan.
     *
     * Tanpa $reset, izin yang sudah dipunyai suatu peran tidak dicabut --
     * hanya izin bawaan yang belum ada yang ditambahkan. Dengan $reset, setiap
     * peran dikembalikan persis ke matriks bawaan.
     *
     * @return array{permissions: int, roles: int}
     */
    public static function sync(bool $reset = false): array
    {
        return DB::transaction(function () use ($reset) {
            foreach (array_keys(self::PERMISSIONS) as $name) {
                Permission::findOrCreate($name, 'web');
            }

            foreach (self::SUPER_ROLES as $role) {
                Role::findOrCreate($role, 'web');
            }

            foreach (self::DEFAULT_MATRIX as $roleName => $permissions) {
                $role = Role::findOrCreate($roleName, 'web');

                if ($reset) {
                    $role->syncPermissions($permissions);
                } else {
                    $role->givePermissionTo($permissions);
                }
            }

            self::retireRoles();

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return ['permissions' => count(self::PERMISSIONS), 'roles' => count(self::DEFAULT_MATRIX) + count(self::SUPER_ROLES)];
        });
    }

    /**
     * Peran yang dihapus: pemegangnya dipindah ke peran pengganti, lalu
     * perannya dibuang. Ikut berjalan di setiap sync (idempoten), jadi
     * database latihan maupun resmi beres lewat deploy biasa.
     */
    private static function retireRoles(): void
    {
        $modelKey = config('permission.column_names.model_morph_key', 'model_id');
        $pivot = config('permission.table_names.model_has_roles');

        foreach (self::RETIRED_ROLES as $oldName => $newName) {
            $old = Role::query()->where('name', $oldName)->where('guard_name', 'web')->first();

            if (! $old) {
                continue;
            }

            $new = Role::findOrCreate($newName, 'web');

            $holders = DB::table($pivot)->where('role_id', $old->id)->get();
            $alreadyNew = DB::table($pivot)->where('role_id', $new->id)->get()
                ->map(fn ($row) => $row->model_type.':'.$row->{$modelKey})->flip();

            foreach ($holders as $row) {
                if (! $alreadyNew->has($row->model_type.':'.$row->{$modelKey})) {
                    DB::table($pivot)->insert([
                        'role_id' => $new->id, 'model_type' => $row->model_type, $modelKey => $row->{$modelKey},
                    ]);
                }
            }

            // Spatie membersihkan pivot peran & izinnya saat peran dihapus.
            $old->delete();
        }
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::PERMISSIONS);
    }
}
