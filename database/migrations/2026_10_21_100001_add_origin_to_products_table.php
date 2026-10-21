<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asal menu jual: `v2` untuk yang datang dari PO Workflow versi 2.
 *
 * Seluruh produk yang ada saat migrasi ini berjalan berasal dari dump v2 --
 * pada cutover 1 Nov, DB resmi dibangun dari dump v2 lalu dimigrasi, dan
 * carry-master-data tidak membuat produk latihan. Produk yang dibuat di
 * 3S ONE sesudahnya dibiarkan kosong (tanpa badge).
 *
 * Ditampilkan sebagai badge di Pencocokan Menu supaya tim menu dan tim admin
 * bisa melihat data siapa yang sedang dicocokkan; pasangannya, badge MMR
 * untuk resep, dibaca dari recipes.source_recipe_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('origin', 10)->nullable()->after('is_3s');
        });

        DB::table('products')->update(['origin' => 'v2']);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
