<?php

namespace App\Support\Units;

/**
 * Besaran sebuah satuan.
 *
 * Konversi hanya sah di dalam besaran yang sama: gram ke kilogram boleh,
 * kilogram ke liter tidak -- itu butuh massa jenis bahan, yang tidak dimiliki
 * sistem ini.
 */
enum UnitDimension: string
{
    case Mass = 'mass';
    case Volume = 'volume';
    case Count = 'count';

    public function label(): string
    {
        return match ($this) {
            self::Mass => 'Berat',
            self::Volume => 'Volume',
            self::Count => 'Satuan Hitung',
        };
    }

    /**
     * Besaran yang punya pembanding tetap antar satuannya.
     *
     * Satuan hitung tidak termasuk: satu ikat bukan kelipatan tetap dari satu
     * pcs, dan berapa gram satu butir telur berbeda-beda per bahan. Konversi
     * semacam itu perlu aturan per bahan, bukan tabel satuan.
     */
    public function isConvertible(): bool
    {
        return $this !== self::Count;
    }
}
