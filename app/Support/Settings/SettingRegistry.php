<?php

namespace App\Support\Settings;

use InvalidArgumentException;

/**
 * Daftar pengaturan modul yang boleh diubah pemilik dari halaman Pengaturan.
 *
 * Setiap kunci punya tipe, nilai bawaan, dan label; kode yang memakainya
 * membaca lewat Settings::get() sehingga nilai bawaan di sini adalah perilaku
 * aplikasi bila belum pernah diubah. Menambah pengaturan = menambah satu
 * entri di sini (dan memakainya) -- halaman Pengaturan membangun formnya dari
 * daftar ini.
 */
class SettingRegistry
{
    public const TYPE_PERCENT = 'percent';

    public const TYPE_BOOL = 'bool';

    public const TYPE_SELECT = 'select';

    public const TYPE_TEXT = 'text';

    public const TYPE_INT = 'int';

    /**
     * @return array<string, array{group: string, label: string, type: string, default: mixed, help?: string, options?: array<string, string>, min?: int|float, max?: int|float}>
     */
    public static function all(): array
    {
        return [
            'recipe.default_ohc_pct' => [
                'group' => 'Resep & HPP',
                'label' => 'OHC bawaan resep baru',
                'type' => self::TYPE_PERCENT,
                'default' => 40,
                'min' => 0,
                'max' => 500,
                'help' => 'Persen dari HPP bahan yang dipakai saat resep baru dibuat. Resep yang sudah ada tidak berubah.',
            ],
            'recipe.default_profit_pct' => [
                'group' => 'Resep & HPP',
                'label' => 'Target profit bawaan resep baru',
                'type' => self::TYPE_PERCENT,
                'default' => 25,
                'min' => 0,
                'max' => 500,
                'help' => 'Persen dari HPP + OHC.',
            ],
            'hpp.usage_source' => [
                'group' => 'Resep & HPP',
                'label' => 'Sumber pemakaian bahan untuk HPP',
                'type' => self::TYPE_SELECT,
                'default' => 'residual',
                'options' => ['residual' => 'Residual opname (Saldo Awal + Beli - Sisa)', 'resep' => 'Ledger resep x produksi (+ penyesuaian sisa fisik)'],
                'help' => 'Ganti ke ledger resep hanya setelah Perbandingan HPP beberapa periode disetujui Owner. Berlaku ke Laporan Pemakaian Bahan, Laba Rugi, dan Neraca.',
            ],
            'hpp.comparison_default_range' => [
                'group' => 'Resep & HPP',
                'label' => 'Periode awal Perbandingan HPP',
                'type' => self::TYPE_SELECT,
                'default' => 'bulan_ini',
                'options' => ['bulan_ini' => 'Bulan ini', 'bulan_lalu' => 'Bulan lalu'],
            ],
            'requisition.round_purchase_up' => [
                'group' => 'Form Kebutuhan',
                'label' => 'Bulatkan usulan Beli ke atas',
                'type' => self::TYPE_BOOL,
                'default' => false,
                'help' => 'Usulan Beli (Kebutuhan - Stok Awal) dibulatkan ke bilangan bulat satuan harga, karena pembelian biasanya per kemasan utuh.',
            ],
            'production.require_remaining_on_close' => [
                'group' => 'Form Kebutuhan',
                'label' => 'Wajib isi Sisa Stok sebelum Tutup SPK',
                'type' => self::TYPE_BOOL,
                'default' => false,
                'help' => 'Bila aktif, SPK tidak bisa ditutup sebelum setiap bahan diisi sisa stok fisiknya.',
            ],
            'document.production_prefix' => [
                'group' => 'Penomoran Dokumen',
                'label' => 'Awalan nomor SPK Produksi',
                'type' => self::TYPE_TEXT,
                'default' => 'SPKP',
                'help' => 'Hanya berlaku untuk dokumen yang dibuat setelah diubah.',
            ],
            'document.requisition_prefix' => [
                'group' => 'Penomoran Dokumen',
                'label' => 'Awalan nomor Form Kebutuhan',
                'type' => self::TYPE_TEXT,
                'default' => 'FKB',
            ],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** @return array{group: string, label: string, type: string, default: mixed, help?: string, options?: array<string, string>, min?: int|float, max?: int|float} */
    public static function definition(string $key): array
    {
        return self::all()[$key] ?? throw new InvalidArgumentException("Pengaturan '{$key}' tidak terdaftar.");
    }

    /** @return array<string, list<string>> grup => kunci */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::all() as $key => $definition) {
            $groups[$definition['group']][] = $key;
        }

        return $groups;
    }
}
