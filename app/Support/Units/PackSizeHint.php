<?php

namespace App\Support\Units;

/**
 * Usulan faktor konversi yang dibaca dari nama bahan.
 *
 * Nama bahan di data klien sering sudah memuat isi kemasannya sendiri:
 * "thinwall kotak 500ml @500pcs", "telur @1kg isi 16butir", "mie burung dara
 * 36pack". Mengetiknya ulang satu per satu untuk 178 pasangan adalah kerja
 * yang bisa dihindari.
 *
 * Yang dihasilkan hanya USULAN, tidak pernah aturan yang tersimpan sendiri:
 * nama bahan adalah teks bebas yang ditulis manusia, dan menebak isi kemasan
 * berarti menebak harga. Karena itu usulan hanya diberikan bila satuan yang
 * terbaca benar-benar menjembatani pasangan yang sedang bermasalah -- kalau
 * angkanya tidak menyelesaikan apa-apa, lebih baik diam.
 */
class PackSizeHint
{
    /** Angka diikuti huruf, mis. "500pcs", "12 pack", "1,5 liter". */
    protected const TOKEN_PATTERN = '/(\d+(?:[.,]\d+)?)\s*([a-zA-Z]+)/';

    /**
     * Usulkan aturan "1 packUnit = factor U" dari nama bahan.
     *
     * @param  string  $name  Nama bahan apa adanya.
     * @param  string  $packUnit  Satuan harga bahan, mis. "dus".
     * @param  string  $recipeUnit  Satuan yang dipakai resep, mis. "pc".
     * @return array{from_unit: string, to_unit: string, factor: float}|null
     */
    public static function suggest(string $name, ?string $packUnit, ?string $recipeUnit): ?array
    {
        $pack = Unit::tryFromAlias($packUnit)?->value ?? trim((string) $packUnit);
        $recipe = Unit::tryFromAlias($recipeUnit);

        if ($pack === '' || $recipe === null) {
            return null;
        }

        if (! preg_match_all(self::TOKEN_PATTERN, $name, $matches, PREG_SET_ORDER)) {
            return null;
        }

        /** @var array<string, array<int, float>> $candidates */
        $candidates = [];

        foreach ($matches as [, $rawNumber, $rawUnit]) {
            $unit = Unit::tryFromAlias($rawUnit);

            // Angka yang satuannya sama dengan satuan harga bukan isi kemasan,
            // melainkan ukuran kemasannya sendiri ("@1kg" pada bahan per kg).
            if ($unit === null || $unit->value === $pack) {
                continue;
            }

            $factor = (float) str_replace(',', '.', $rawNumber);

            if ($factor > 0) {
                $candidates[$unit->value][] = $factor;
            }
        }

        // Nama yang menyebut lebih dari satu satuan isi hampir selalu bertingkat
        // -- "santan kara 200ml @12pack 200ml" berarti satu dus berisi 12 pack
        // berisi 200 ml, dan angka mana pun yang diambil sendirian akan salah.
        // Nama seperti itu dibiarkan diisi manusia.
        if (count($candidates) !== 1) {
            return null;
        }

        $unitValue = array_key_first($candidates);
        $numbers = array_unique($candidates[$unitValue]);

        // Satu satuan dengan dua angka berbeda sama ambigunya.
        if (count($numbers) !== 1) {
            return null;
        }

        $unit = Unit::from($unitValue);

        // Usulan hanya berarti bila satuan yang terbaca sepadan dengan satuan
        // yang dipakai resep; kalau tidak, aturannya tidak menutup kegagalan
        // yang sedang ditangani.
        if (! self::bridges($unit, $recipe)) {
            return null;
        }

        $factor = (float) reset($numbers);

        if (! self::isPlausible($pack, $unit, $factor)) {
            return null;
        }

        return [
            'from_unit' => $pack,
            'to_unit' => $unit->value,
            'factor' => $factor,
        ];
    }

    /**
     * Saring bacaan yang mustahil secara fisik.
     *
     * "1 kg = 16 butir" masuk akal (satu butir ±62 gram), tetapi "1 gram = 10
     * ikat" tidak -- itu muncul dari bahan yang satuan harganya memang keliru,
     * dan usulan seperti itu hanya akan menularkan kekeliruannya ke HPP.
     * Ambangnya sengaja longgar: cukup satu satuan basis per butir isi.
     */
    protected static function isPlausible(string $packValue, Unit $content, float $factor): bool
    {
        $packUnit = Unit::tryFrom($packValue);

        if ($packUnit === null
            || ! $packUnit->dimension()->isConvertible()
            || $content->dimension()->isConvertible()) {
            return true;
        }

        return $packUnit->factorToBase() / $factor >= 1.0;
    }

    /** Dua satuan sepadan: sama persis, atau sebesaran dan bisa dikonversi. */
    protected static function bridges(Unit $candidate, Unit $recipeUnit): bool
    {
        return $candidate === $recipeUnit
            || ($candidate->dimension() === $recipeUnit->dimension() && $candidate->dimension()->isConvertible());
    }

    /** Bacaan usulan dalam bahasa manusia, mis. "1 dus = 500 pcs". */
    public static function describe(array $hint): string
    {
        return sprintf(
            '1 %s = %s %s',
            $hint['from_unit'],
            rtrim(rtrim(number_format($hint['factor'], 4, ',', '.'), '0'), ','),
            $hint['to_unit'],
        );
    }
}
