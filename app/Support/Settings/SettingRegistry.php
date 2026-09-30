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

    /** Tanggal, disimpan sebagai teks Y-m-d. */
    public const TYPE_DATE = 'date';

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
                'options' => ['residual' => 'Residual opname (Saldo Awal + Beli - Sisa)', 'resep' => 'Resep x produksi dari kartu stok (+ penyesuaian sisa fisik)'],
                'help' => 'Ganti ke resep x produksi hanya setelah Perbandingan HPP beberapa periode disetujui Owner. Berlaku ke Laporan Pemakaian Bahan, Laba Rugi, dan Neraca.',
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
            'profit.lower_bound_pct' => [
                'group' => 'Profit Menu',
                'label' => 'Batas bawah profit menu keseluruhan',
                'type' => self::TYPE_PERCENT,
                'default' => 20,
                'min' => 0,
                'max' => 1000,
                'help' => 'Profit keseluruhan = (total harga jual - total biaya) / total biaya atas semua menu aktif yang punya resep terhitung; biaya = HPP bahan + OHC. Lonceng dikirim ke pemegang izin notifikasi profit saat angka ini turun di bawah batas.',
            ],
            'profit.upper_bound_pct' => [
                'group' => 'Profit Menu',
                'label' => 'Batas atas profit menu keseluruhan',
                'type' => self::TYPE_PERCENT,
                'default' => 60,
                'min' => 0,
                'max' => 1000,
                'help' => 'Lonceng dikirim saat profit keseluruhan melampaui batas ini (biasanya pertanda harga bahan di sistem belum diperbarui).',
            ],
            'report.idle_menu_months' => [
                'group' => 'Laporan',
                'label' => 'Rentang bawaan Menu Tidak Diproduksi (bulan)',
                'type' => self::TYPE_INT,
                'default' => 3,
                'min' => 1,
                'max' => 36,
                'help' => 'Menu aktif yang tidak pernah muncul di SPK Produksi selama sekian bulan terakhir dianggap tidak diproduksi.',
            ],
            'requisition.reject_default_treatment' => [
                'group' => 'Form Kebutuhan',
                'label' => 'Perlakuan bawaan barang ditolak',
                'type' => self::TYPE_SELECT,
                'default' => 'retur',
                'options' => ['retur' => 'Retur / tidak dibayar', 'dibayar' => 'Dibayar (kerugian barang rusak)'],
                'help' => 'Dipakai bila petugas penerimaan tidak memilih perlakuan untuk barang yang ditolak.',
            ],
            'requisition.update_master_price' => [
                'group' => 'Form Kebutuhan',
                'label' => 'Harga beli memperbarui harga master bahan',
                'type' => self::TYPE_BOOL,
                'default' => true,
                'help' => 'Saat Periksa, harga beli dari nota menjadi harga bahan (tercatat di histori harga) sehingga HPP resep memakai harga terbaru.',
            ],
            'production.require_remaining_on_close' => [
                'group' => 'Form Kebutuhan',
                'label' => 'Wajib isi Sisa Stok sebelum Tutup SPK',
                'type' => self::TYPE_BOOL,
                'default' => false,
                'help' => 'Bila aktif, SPK tidak bisa ditutup sebelum setiap bahan diisi sisa stok fisiknya.',
            ],
            'shrinkage.green_max_pct' => [
                'group' => 'Susut Bahan',
                'label' => 'Batas susut hijau',
                'type' => self::TYPE_PERCENT,
                'default' => 1,
                'min' => 0,
                'max' => 100,
                'help' => 'Susut bahan di bawah angka ini berwarna hijau (wajar). Susut = (pemakaian lebih dari resep + barang hilang - barang temuan) / kebutuhan resep.',
            ],
            'shrinkage.yellow_max_pct' => [
                'group' => 'Susut Bahan',
                'label' => 'Batas susut kuning',
                'type' => self::TYPE_PERCENT,
                'default' => 10,
                'min' => 0,
                'max' => 1000,
                'help' => 'Susut dari batas hijau sampai angka ini berwarna kuning (perlu diperhatikan); di atasnya merah (perlu ditelusuri).',
            ],
            'leftover.accounting_start' => [
                'group' => 'Barang Sisa',
                'label' => 'Barang Sisa dihitung sebagai persediaan mulai',
                'type' => self::TYPE_DATE,
                'default' => '2026-10-01',
                'help' => 'Retur yang masuk Barang Sisa sejak tanggal ini dinilai sebesar HPP menunya dan tercatat sebagai persediaan di Neraca & Laba Rugi. Retur sebelumnya tidak diubah supaya laporan bulan yang sudah dilaporkan tidak bergeser.',
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
