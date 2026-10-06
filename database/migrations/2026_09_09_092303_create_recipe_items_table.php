<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baris resep (BOM).
 *
 * Sebuah baris menunjuk salah satu dari tiga hal:
 *   1. bahan  -> inventory_item_id
 *   2. sub-menu -> ref_recipe_id, biayanya dihitung dari resep itu sendiri
 *   3. belum cocok -> keduanya NULL, hanya raw_name; masuk daftar mismatch
 *
 * raw_name selalu diisi apa adanya dari sumber, bahkan ketika baris sudah cocok.
 * Tanpa itu, baris yang salah tautan tidak bisa ditelusuri kembali ke teks
 * aslinya saat rekonsiliasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();

            $table->unsignedInteger('sort_order')->default(0);
            $table->string('section')->nullable()->comment('Pengelompokan di dalam resep, mis. "Bumbu Halus".');

            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->foreignId('ref_recipe_id')->nullable()->constrained('recipes')->nullOnDelete();

            $table->string('raw_name');

            $table->decimal('qty', 15, 4)->default(0);
            $table->string('unit', 30)->nullable();

            // Harga satuan saat baris ini terakhir dihitung. Bukan sumber
            // kebenaran -- hanya cadangan untuk baris yang belum punya bahan.
            $table->decimal('unit_price_snapshot', 15, 4)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['recipe_id', 'sort_order']);
            $table->index('inventory_item_id');
            $table->index('ref_recipe_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_items');
    }
};
