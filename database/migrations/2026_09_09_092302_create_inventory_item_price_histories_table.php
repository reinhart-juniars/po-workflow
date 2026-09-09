<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histori harga bahan.
 *
 * Master Menu Revamp mencatat setiap perubahan harga bahan beserta alasannya --
 * termasuk penolakan penurunan harga, yang di sana dipakai sebagai kontrol agar
 * harga tidak diturunkan tanpa sepengetahuan. 88 baris histori itu ikut pindah,
 * karena tanpanya perubahan HPP tidak bisa ditelusuri ke perubahan harga bahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_item_price_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inventory_item_id')
                ->constrained('inventory_items')
                ->cascadeOnDelete();

            $table->decimal('old_unit_price', 15, 4)->nullable();
            $table->decimal('new_unit_price', 15, 4)->nullable();
            $table->decimal('old_pack_price', 15, 2)->nullable();
            $table->decimal('new_pack_price', 15, 2)->nullable();

            // set-awal | naik | turun-ditolak | turun-dipaksa
            $table->string('action', 30);

            // manual | import | migrasi
            $table->string('source', 20);

            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['inventory_item_id', 'created_at'], 'item_price_history_item_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_item_price_histories');
    }
};
