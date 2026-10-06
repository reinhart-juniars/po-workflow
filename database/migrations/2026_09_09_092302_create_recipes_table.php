<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resep (menu utama & sub-menu).
 *
 * Arah relasinya sengaja resep -> produk, bukan sebaliknya. products (477 baris,
 * dipakai 457 kali di sales_actual_items dan 2.701 sales actual) adalah induk
 * yang sudah dipakai seluruh alur penjualan; resep menempel padanya lewat
 * product_id yang nullable, karena dari 460 resep hanya sebagian yang punya
 * padanan produk dan sisanya menunggu sesi mapping bersama klien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            // Nama ternormalisasi: pembanding tunggal saat impor & pencocokan,
            // sekaligus penjaga agar resep tidak terduplikasi karena beda ejaan.
            $table->string('name_norm')->unique();

            $table->foreignId('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete()
                ->comment('Produk yang dijual; NULL berarti belum dipetakan.');

            // utama = menu yang dijual; sub = komponen yang dipakai resep lain.
            $table->string('jenis', 10)->default('utama');
            $table->string('kategori')->nullable();

            // Hasil satu kali produksi resep ini, mis. 10 porsi.
            $table->decimal('yield_qty', 15, 4)->default(1);
            $table->string('yield_unit', 30)->default('porsi');

            // Persentase overhead & profit terhadap HPP, mengikuti pola Master Menu.
            $table->decimal('ohc_pct', 8, 4)->default(0.40);
            $table->decimal('profit_pct', 8, 4)->default(0.25);
            $table->decimal('target_price', 15, 2)->nullable();

            // Menu yang tidak punya rincian resep tetapi punya angka HPP/OHC/profit
            // di Excel. Dipakai sebagai nilai cadangan agar menu itu tetap bisa
            // dihitung, dan ditandai jelas bahwa angkanya bukan dari rincian bahan.
            $table->decimal('snapshot_hpp', 15, 2)->nullable();
            $table->decimal('snapshot_ohc', 15, 2)->nullable();
            $table->decimal('snapshot_profit', 15, 2)->nullable();

            $table->text('notes')->nullable();
            $table->string('source_sheet')->nullable();
            $table->unsignedBigInteger('source_recipe_id')->nullable()->comment('Jejak ke recipes.id Master Menu Revamp.');

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('jenis');
            $table->index('kategori');
            $table->unique('source_recipe_id', 'recipes_source_recipe_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
