<?php

namespace App\Support\Units;

use App\Models\CustomUnit;
use Illuminate\Support\Facades\Schema;

/**
 * Semua satuan yang bisa dipilih: satuan bawaan (Unit) ditambah satuan dari
 * Master Satuan. Dipakai setiap dropdown satuan supaya satuan yang ditambah
 * gudang langsung muncul di Item Inventaris, resep, dan Konversi Satuan.
 */
class UnitCatalog
{
    public const CUSTOM_GROUP = 'Satuan Tambahan';

    /** Kunci cache di container: hidup satu request (dan satu tes), bukan selamanya. */
    protected const CACHE_KEY = 'unit-catalog.custom';

    /**
     * Nama satuan tambahan yang aktif, huruf kecil. Disimpan per request
     * karena dropdown satuan dirender sekali per baris resep.
     *
     * @return list<string>
     */
    public static function customUnits(): array
    {
        if (app()->bound(self::CACHE_KEY)) {
            return app(self::CACHE_KEY);
        }

        // Sebelum migrate (deploy setengah jalan) tabelnya belum ada: tetap
        // tampilkan satuan bawaan alih-alih membuat semua form gagal.
        $names = Schema::hasTable('custom_units')
            ? CustomUnit::query()->active()->orderBy('name')->pluck('name')->all()
            : [];

        app()->instance(self::CACHE_KEY, $names);

        return $names;
    }

    public static function flush(): void
    {
        app()->forgetInstance(self::CACHE_KEY);
    }

    /**
     * Pilihan dropdown dikelompokkan per besaran, ditambah kelompok Satuan Tambahan.
     *
     * @return array<string, array<string, string>>
     */
    public static function groupedOptions(): array
    {
        $options = Unit::groupedOptions();

        foreach (static::customUnits() as $name) {
            $options[self::CUSTOM_GROUP][$name] = $name;
        }

        return $options;
    }

    /** Satuan dikenal sistem: bawaan (termasuk alias) atau Master Satuan aktif. */
    public static function isKnown(?string $raw): bool
    {
        if (Unit::tryFromAlias($raw) !== null) {
            return true;
        }

        return in_array(CustomUnit::normalize($raw), static::customUnits(), true);
    }
}
