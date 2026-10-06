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
        'requisition.approve' => 'Setujui form kebutuhan',
        'requisition.check' => 'Periksa form kebutuhan (barang dibeli)',
        'ledger.view' => 'Lihat kartu stok dan perbandingan HPP',
        'settings.manage' => 'Ubah pengaturan modul',
        'notification.price' => 'Terima notifikasi perubahan harga bahan & harga jual menu',
        'notification.profit' => 'Terima notifikasi profit menu keluar dari batas',
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
            'requisition.approve', 'requisition.check',
            'ledger.view',
            'settings.manage',
            'notification.price', 'notification.profit',
        ],
        'admin' => [
            'inventory.view', 'inventory.manage',
            'recipe.view', 'recipe.manage',
            'production.view', 'production.manage', 'production.complete',
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
        ],
        // Staf inventory/gudang: kelola bahan, pembelian, opname, saldo awal,
        // cocokkan nama bahan resep, dan periksa form kebutuhan saat barang
        // dibeli. Tidak menyusun/menyetujui/menutup SPK produksi.
        'inventory' => [
            'inventory.view', 'inventory.manage',
            'recipe.view', 'recipe.manage',
            'production.view',
            'requisition.check',
            'ledger.view',
        ],
        // Supervisor gudang: seperti staf inventory, ditambah menyetujui /
        // menolak Form Kebutuhan yang diajukan produksi.
        'inventory-supervisor' => [
            'inventory.view', 'inventory.manage',
            'recipe.view', 'recipe.manage',
            'production.view',
            'requisition.approve', 'requisition.check',
            'ledger.view',
            'notification.price',
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

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return ['permissions' => count(self::PERMISSIONS), 'roles' => count(self::DEFAULT_MATRIX) + count(self::SUPER_ROLES)];
        });
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::PERMISSIONS);
    }
}
