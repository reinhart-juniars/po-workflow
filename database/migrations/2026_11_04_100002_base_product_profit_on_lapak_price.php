<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Profit menu dihitung dari Harga Lapak (revisi user 4 Nov 2026).
 *
 * Kolom products.profit menyimpan hasil hitungan lama dari Harga Non Lapak
 * (base_price), jadi menu yang kedua harganya berbeda dihitung ulang di sini.
 * Lewat query langsung: tanpa baris riwayat dan lonceng untuk seluruh menu.
 *
 * Riwayat harga mulai mencatat Harga Lapak, karena angka itu kini menentukan
 * profit. Baris riwayat lama tidak diisi: harga lapak saat itu tidak diketahui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_price_histories', function (Blueprint $table) {
            $table->decimal('lapak_price', 12, 2)->nullable()->after('base_price');
        });

        $this->recalculate('COALESCE(lapak_price, base_price)');
    }

    public function down(): void
    {
        $this->recalculate('base_price');

        Schema::table('product_price_histories', function (Blueprint $table) {
            $table->dropColumn('lapak_price');
        });
    }

    private function recalculate(string $priceExpression): void
    {
        DB::table('products')->update([
            'profit' => DB::raw("ROUND({$priceExpression} - (COALESCE(raw_material_cost, 0) + COALESCE(overhead_cost, 0)), 2)"),
        ]);
    }
};
