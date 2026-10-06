<?php

namespace App\Services;

use App\Models\InventoryUnitConversion;
use App\Support\Units\Unit;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Konversi kuantitas resep ke satuan harga bahan.
 *
 * Registri satuan (UnitConverter) hanya sanggup mengonversi di dalam besaran
 * yang sama. Pengukuran di lapangan menunjukkan itu belum cukup: dari 6.466
 * baris resep yang sudah tertaut ke bahan, 1.496 gagal justru karena melintasi
 * besaran -- resep menulis takaran (gr, ml) sementara bahannya dihargai per
 * kemasan (pcs, pack, dus, botol).
 *
 * Jembatannya adalah aturan per bahan yang diisi pengguna, mis. "1 pcs = 250
 * gr". Satu aturan bisa dipakai dua arah dan boleh disambung dengan konversi
 * registri di kedua ujungnya, sehingga aturan "pcs <-> gram" juga menyelesaikan
 * permintaan "kg -> pcs" tanpa perlu aturan kedua.
 *
 * Penyambungannya sengaja dibatasi satu aturan saja. Merangkai beberapa aturan
 * (pcs -> gr, gr -> dus, dus -> pack) membuat hasilnya bergantung pada urutan
 * penelusuran dan sulit dijelaskan ketika HPP-nya ternyata meleset.
 */
class IngredientUnitConverter
{
    /**
     * Aturan per bahan, dimuat sekali per permintaan.
     *
     * @var Collection<int, InventoryUnitConversion>|null
     */
    protected ?Collection $rules = null;

    public function __construct(
        protected UnitConverter $converter
    ) {}

    /** Buang cache aturan, mis. setelah aturan baru disimpan dalam proses yang sama. */
    public function flush(): void
    {
        $this->rules = null;
    }

    /**
     * Ubah kuantitas dari satuan resep ke satuan tujuan.
     *
     * Mengembalikan null bila tidak ada jalan yang sah, supaya pemanggil
     * menandainya alih-alih memakai angka yang salah.
     *
     * @param  int|null  $inventoryItemId  Bahan yang aturannya ikut dipertimbangkan.
     */
    public function convert(float $quantity, ?string $from, ?string $to, ?int $inventoryItemId = null): ?float
    {
        $direct = $this->convertByRegistry($quantity, $from, $to);

        if ($direct !== null) {
            return $direct;
        }

        foreach ($this->rulesFor($inventoryItemId) as $rule) {
            $bridged = $this->applyRule($quantity, $from, $to, $rule);

            if ($bridged !== null) {
                return $bridged;
            }
        }

        return null;
    }

    /**
     * Aturan yang dipakai menjembatani sebuah konversi, bila ada.
     *
     * Dipakai laporan dan antarmuka untuk menjelaskan dari mana angkanya
     * datang; perhitungannya sendiri memakai convert().
     */
    public function ruleUsed(?string $from, ?string $to, ?int $inventoryItemId = null): ?InventoryUnitConversion
    {
        if ($this->convertByRegistry(1.0, $from, $to) !== null) {
            return null;
        }

        foreach ($this->rulesFor($inventoryItemId) as $rule) {
            if ($this->applyRule(1.0, $from, $to, $rule) !== null) {
                return $rule;
            }
        }

        return null;
    }

    /** Konversi bisa dilakukan, entah lewat registri atau lewat aturan bahan. */
    public function canConvert(?string $from, ?string $to, ?int $inventoryItemId = null): bool
    {
        return $this->convert(1.0, $from, $to, $inventoryItemId) !== null;
    }

    /**
     * Terapkan satu aturan "1 a = factor b" pada permintaan from -> to.
     *
     * Dicoba dua arah: aturan "1 botol = 600 ml" harus menyelesaikan baik
     * "ml -> botol" maupun "botol -> ml".
     */
    protected function applyRule(float $quantity, ?string $from, ?string $to, InventoryUnitConversion $rule): ?float
    {
        $factor = (float) $rule->factor;

        // Faktor nol atau negatif tidak punya arti dan akan membagi nol.
        if ($factor <= 0) {
            return null;
        }

        // Arah maju: from -> a, kalikan faktor, b -> to.
        $inA = $this->convertByRegistry($quantity, $from, $rule->from_unit);

        if ($inA !== null) {
            $out = $this->convertByRegistry($inA * $factor, $rule->to_unit, $to);

            if ($out !== null) {
                return $out;
            }
        }

        // Arah balik: from -> b, bagi faktor, a -> to.
        $inB = $this->convertByRegistry($quantity, $from, $rule->to_unit);

        if ($inB !== null) {
            return $this->convertByRegistry($inB / $factor, $rule->from_unit, $to);
        }

        return null;
    }

    /**
     * Konversi yang hanya mengandalkan registri satuan.
     *
     * Satuan yang teksnya sama persis diterima meski tidak dikenal registri --
     * dua sisi memakai satuan yang sama tidak butuh konversi apa pun.
     */
    public function convertByRegistry(float $quantity, ?string $from, ?string $to): ?float
    {
        $fromText = mb_strtolower(trim((string) $from));
        $toText = mb_strtolower(trim((string) $to));

        if ($fromText !== '' && $fromText === $toText) {
            return $quantity;
        }

        $fromUnit = Unit::tryFromAlias($from);
        $toUnit = Unit::tryFromAlias($to);

        if ($fromUnit === null || $toUnit === null) {
            return null;
        }

        if ($fromUnit === $toUnit) {
            return $quantity;
        }

        try {
            return $this->converter->convert($quantity, $fromUnit, $toUnit);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Aturan yang berlaku untuk sebuah bahan, aturan miliknya lebih dulu.
     *
     * Seluruh aturan dimuat sekali lalu disaring di memori: menghitung HPP satu
     * menu menyentuh puluhan bahan, dan menanyakannya satu per satu ke basis
     * data membuat laporan resep menjadi ratusan kueri.
     *
     * @return Collection<int, InventoryUnitConversion>
     */
    protected function rulesFor(?int $inventoryItemId): Collection
    {
        $this->rules ??= InventoryUnitConversion::query()->orderBy('id')->get();

        return $this->rules
            ->filter(fn (InventoryUnitConversion $rule) => $rule->inventory_item_id === null
                || $rule->inventory_item_id === $inventoryItemId)
            ->sortBy(fn (InventoryUnitConversion $rule) => $rule->inventory_item_id === null ? 1 : 0)
            ->values();
    }
}
