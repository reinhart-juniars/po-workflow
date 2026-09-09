<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ingredients Master Menu masuk sebagai item detail di bawah bucket yang ada.
 *
 * Tiga bucket lama (Bahan Baku, Inventaris, Packaging) tetap menjadi induk dan
 * tetap menjadi sumber angka Laba Rugi -- seluruh histori 252 pembelian dan 14
 * opname menempel di sana dan tidak boleh berpindah, karena memindahkannya akan
 * mengubah laporan periode yang sudah ditutup buku.
 *
 * 305 ingredient bergabung sebagai anak dengan parent_id, membawa harga
 * satuannya sendiri. Harga itulah yang dipakai menghitung HPP dari resep,
 * berjalan paralel dengan HPP residual opname sampai hasilnya disetujui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('inventory_items')
                ->nullOnDelete()
                ->comment('Bucket induk; NULL berarti item ini sendiri sebuah bucket.');

            // Cara bahan dibeli: 1 pack berisi pack_qty satuan seharga pack_price.
            $table->decimal('pack_qty', 15, 4)->nullable()->after('unit');
            $table->decimal('pack_price', 15, 2)->nullable()->after('pack_qty');

            // Harga per satuan -- turunan pack_price / pack_qty, disimpan supaya
            // perhitungan HPP tidak perlu membagi ulang di setiap baris resep.
            $table->decimal('unit_price', 15, 4)->nullable()->after('pack_price');

            $table->boolean('is_prepared')
                ->default(false)
                ->after('unit_price')
                ->comment('Bahan olahan/setengah jadi, bukan bahan mentah.');

            // Jejak ke ingredients.id di Master Menu Revamp, untuk audit dan
            // migrasi ulang bila datanya perlu disegarkan sebelum cutover.
            $table->unsignedBigInteger('source_ingredient_id')->nullable()->after('is_prepared');

            $table->index('parent_id', 'inventory_items_parent_index');
            $table->unique('source_ingredient_id', 'inventory_items_source_ingredient_unique');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropUnique('inventory_items_source_ingredient_unique');
            $table->dropIndex('inventory_items_parent_index');
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn([
                'pack_qty',
                'pack_price',
                'unit_price',
                'is_prepared',
                'source_ingredient_id',
            ]);
        });
    }
};
