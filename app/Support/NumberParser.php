<?php

namespace App\Support;

/**
 * Membaca angka dari sel Excel/CSV impor.
 *
 * Sel angka asli (int/float -- termasuk hasil ekspor 3S ONE sendiri) dipakai
 * apa adanya. Teks yang diketik manusia dibaca dengan aturan Indonesia:
 *
 *   "1.500.000,50" -> 1500000.5   (titik ribuan, koma desimal)
 *   "1,500,000.50" -> 1500000.5   (gaya Inggris: pemisah terakhir = desimal)
 *   "12.000"       -> 12000       (satu titik + tepat 3 digit = ribuan)
 *   "147.5"        -> 147.5       (titik dengan 1-2/4+ digit = desimal)
 *   "0.125"        -> 0.125       (diawali "0." tidak mungkin ribuan)
 *   "10,5"         -> 10.5
 *
 * Bug yang ditutup: impor Item Inventaris dulu membuang SEMUA titik, sehingga
 * berkas hasil ekspor yang diimpor kembali menaikkan harga 147,5 menjadi 1.475.
 */
final class NumberParser
{
    public static function parse(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $text = preg_replace('/[^\d,.\-]/', '', trim((string) $value));

        if ($text === null || $text === '' || $text === '-') {
            return null;
        }

        $lastComma = strrpos($text, ',');
        $lastDot = strrpos($text, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // Pemisah yang muncul terakhir adalah desimal.
            $text = $lastComma > $lastDot
                ? str_replace(',', '.', str_replace('.', '', $text))
                : str_replace(',', '', $text);
        } elseif ($lastComma !== false) {
            $fraction = strlen(substr($text, $lastComma + 1));
            $text = substr_count($text, ',') > 1 || ($fraction === 3 && ! str_starts_with($text, '0,'))
                ? str_replace(',', '', $text)
                : str_replace(',', '.', $text);
        } elseif ($lastDot !== false) {
            $fraction = strlen(substr($text, $lastDot + 1));

            if (substr_count($text, '.') > 1 || ($fraction === 3 && ! str_starts_with($text, '0.'))) {
                $text = str_replace('.', '', $text);
            }
        }

        return is_numeric($text) ? (float) $text : null;
    }
}
