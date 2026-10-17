<?php

namespace App\Support;

use Filament\Forms\Components\TextInput;

/**
 * Input harga rupiah di form Filament: tampil paling banyak 2 angka di
 * belakang koma, tanpa nol buntut ("181000", "8.75", bukan "181000.0000").
 *
 * Kolom harga disimpan decimal(…, 4) supaya harga per gram tetap teliti, dan
 * nilai lama bisa punya 4 desimal (133.3333 dari 400.000 / 3.000 gr). Nilai
 * itu tidak ikut dibulatkan bila penggunanya tidak mengubah angkanya: bagi
 * bahan, perubahan harga sekecil apa pun dicatat ke Histori Harga dan
 * membunyikan lonceng ke owner, jadi menyimpan form tanpa mengubah harga
 * tidak boleh tampil sebagai "harga turun".
 */
class PriceInput
{
    public const DECIMALS = 2;

    public static function configure(TextInput $input): TextInput
    {
        return $input
            ->numeric()
            ->minValue(0)
            ->step('any')
            ->formatStateUsing(fn (mixed $state) => static::display($state))
            ->dehydrateStateUsing(function (mixed $state, TextInput $component) {
                $original = $component->getRecord()?->getRawOriginal($component->getName());

                return static::store($state, $original);
            });
    }

    /** Angka untuk ditampilkan di input: dibulatkan 2 desimal, nol buntut dibuang. */
    public static function display(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return $value === '' ? null : $value;
        }

        $text = number_format(round((float) $value, self::DECIMALS), self::DECIMALS, '.', '');

        return rtrim(rtrim($text, '0'), '.');
    }

    /**
     * Angka untuk disimpan. Bila isian sama dengan tampilan nilai lama, nilai
     * lamanya yang disimpan (tidak berubah); isian baru dibulatkan 2 desimal.
     */
    public static function store(mixed $state, mixed $original = null): ?float
    {
        if ($state === null || $state === '' || ! is_numeric($state)) {
            return null;
        }

        if (is_numeric($original) && static::display($original) === static::display($state)
            && round((float) $state, self::DECIMALS) == (float) $state) {
            return (float) $original;
        }

        return round((float) $state, self::DECIMALS);
    }
}
