<?php

namespace App\Services;

use App\Support\Units\Unit;
use RuntimeException;

/**
 * Konversi kuantitas antar satuan.
 *
 * Dipakai untuk menghitung HPP dari resep: baris resep boleh ditulis dalam
 * satuan sehari-hari (250 gr) sementara harga bahan tersimpan dalam satuan
 * pembelian (per kg), dan keduanya harus ketemu tanpa dihitung manual.
 *
 * Konversi yang tidak sah ditolak, tidak dibulatkan menjadi tebakan. Satu
 * kilogram tidak bisa dijadikan liter tanpa massa jenis, dan satu ikat bukan
 * kelipatan tetap dari satu pcs -- menebaknya berarti menebak harga.
 */
class UnitConverter
{
    public function canConvert(Unit $from, Unit $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return $from->dimension() === $to->dimension()
            && $from->dimension()->isConvertible();
    }

    public function convert(float $quantity, Unit $from, Unit $to): float
    {
        if ($from === $to) {
            return $quantity;
        }

        if (! $this->canConvert($from, $to)) {
            throw new RuntimeException(sprintf(
                'Satuan %s tidak bisa dikonversi ke %s.',
                $from->label(),
                $to->label(),
            ));
        }

        // Keduanya sudah dipastikan sebesaran, jadi cukup lewat satuan basis.
        return $quantity * $from->factorToBase() / $to->factorToBase();
    }

    /**
     * Biaya sebuah baris resep terhadap harga satuan bahan.
     *
     * @param  float  $quantity  Kuantitas pada resep, mis. 250
     * @param  Unit  $recipeUnit  Satuan pada resep, mis. gram
     * @param  float  $pricePerUnit  Harga bahan per satuan harganya, mis. 12000
     * @param  Unit  $priceUnit  Satuan harga bahan, mis. kg
     */
    public function lineCost(float $quantity, Unit $recipeUnit, float $pricePerUnit, Unit $priceUnit): float
    {
        return round($this->convert($quantity, $recipeUnit, $priceUnit) * $pricePerUnit, 2);
    }
}
