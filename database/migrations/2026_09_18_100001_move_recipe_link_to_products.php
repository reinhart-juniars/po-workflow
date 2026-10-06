<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tautan resep <-> produk pindah ke sisi produk.
 *
 * Semula `recipes.product_id`: satu resep hanya bisa menunjuk satu produk.
 * Padahal master produk menyimpan satu baris per varian harga ("NASI CAPJAY
 * 10K/12K/15K") yang dimasak dengan resep yang sama, sehingga varian kedua
 * dan seterusnya tidak pernah bisa ditautkan. Dengan `products.recipe_id`
 * banyak produk boleh memakai satu resep, dan satu produk tetap hanya punya
 * satu resep (keputusan SPK Produksi tetap tunggal).
 *
 * `needs_recipe` menandai produk yang memang tidak dimasak (EXTRA 1K, HARGA
 * UP 2K, DONAT beli jadi) supaya keluar dari daftar kerja pencocokan tanpa
 * dipaksa memilih resep.
 *
 * Produk tetap induk (keputusan arsitektur #2): resep menempel ke produk,
 * bukan sebaliknya -- kolom ini hanya mengubah kardinalitasnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('recipe_id')
                ->nullable()
                ->after('is_3s')
                ->constrained('recipes')
                ->nullOnDelete()
                ->comment('Resep yang dipakai memasak produk ini; NULL berarti belum ditautkan.');
            $table->boolean('needs_recipe')
                ->default(true)
                ->after('recipe_id')
                ->comment('false = produk memang tidak dimasak, tidak perlu resep.');
        });

        // Pindahkan pemetaan yang sudah ada. Skema lama sudah menjamin satu
        // produk paling banyak dimiliki satu resep, jadi tidak ada tabrakan.
        foreach (DB::table('recipes')->whereNotNull('product_id')->get(['id', 'product_id']) as $row) {
            DB::table('products')->where('id', $row->product_id)->update(['recipe_id' => $row->id]);
        }

        Schema::table('recipes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->foreignId('product_id')
                ->nullable()
                ->after('name_norm')
                ->constrained('products')
                ->nullOnDelete();
        });

        // Kembali ke satu produk per resep: yang pertama (id terkecil) menang.
        foreach (DB::table('products')->whereNotNull('recipe_id')->orderBy('id')->get(['id', 'recipe_id']) as $row) {
            DB::table('recipes')->where('id', $row->recipe_id)->whereNull('product_id')->update(['product_id' => $row->id]);
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipe_id');
            $table->dropColumn('needs_recipe');
        });
    }
};
