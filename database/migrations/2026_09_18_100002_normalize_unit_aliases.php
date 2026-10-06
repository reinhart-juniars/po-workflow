<?php

use App\Support\Units\Unit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Satu ejaan per satuan pada data yang sudah ada.
 *
 * Resep hasil migrasi Master Menu menyimpan satuan apa adanya: "gr" 7.387
 * baris, "gram" 139, "pc" di samping "pcs", "lbr" di samping "lembar", dst.
 * Perhitungan HPP sudah mengenali semua aliasnya, tetapi di layar pengguna
 * melihat dua satuan untuk hal yang sama. Mulai sekarang model menyimpan
 * ejaan baku (Unit::canonical) dan data lama disamakan di sini.
 *
 * Teks yang tidak dikenal (mis. "bnggl") dibiarkan: menebak satuan berarti
 * menebak harga.
 */
return new class extends Migration
{
    /** @var array<string, string> tabel => kolom satuan */
    private const COLUMNS = [
        'recipe_items' => 'unit',
        'recipes' => 'yield_unit',
        'inventory_items' => 'unit',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            $values = DB::table($table)->whereNotNull($column)->distinct()->pluck($column);

            foreach ($values as $raw) {
                $canonical = Unit::canonical($raw);

                if ($canonical !== null && $canonical !== $raw) {
                    DB::table($table)->where($column, $raw)->update([$column => $canonical]);
                }
            }
        }
    }

    public function down(): void
    {
        // Ejaan asli tidak disimpan; perhitungan tetap benar dengan ejaan baku.
    }
};
