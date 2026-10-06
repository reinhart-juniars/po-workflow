<?php

namespace App\Support;

/**
 * Versi sistem dibaca dari baris terakhir version.txt di akar proyek --
 * berkas riwayat rilis yang selama ini dirawat manual ("v.2.3.10 patch ...").
 * Dengan begitu menaikkan versi cukup menambah satu baris di sana, tanpa
 * menyentuh kode atau .env, dan halaman masuk selalu menunjukkan rilis yang
 * benar-benar terpasang.
 */
class AppVersion
{
    private static ?string $current = null;

    /** Nomor versi tanpa awalan, mis. "3.0"; "-" jika berkasnya tidak ada. */
    public static function current(): string
    {
        if (self::$current !== null) {
            return self::$current;
        }

        $line = self::lastLine(base_path('version.txt'));

        // Format baris: "v.3.0 keterangan" atau "v3.0 keterangan".
        return self::$current = preg_match('/^v\.?(\S+)/i', $line, $m) ? $m[1] : '-';
    }

    /** Label untuk ditampilkan, mis. "v3.0". */
    public static function label(): string
    {
        return 'v'.self::current();
    }

    private static function lastLine(string $path): string
    {
        if (! is_file($path)) {
            return '';
        }

        $lines = array_values(array_filter(array_map('trim', file($path) ?: []), fn ($l) => $l !== ''));

        return $lines === [] ? '' : end($lines);
    }

    /** Untuk tes: buang cache supaya berkas dibaca ulang. */
    public static function flush(): void
    {
        self::$current = null;
    }
}
