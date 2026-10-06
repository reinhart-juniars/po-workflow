<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ambang stok minimum per item inventory.
 *
 * Sistem ini melacak stok dalam nilai rupiah, bukan kuantitas -- seluruh
 * saldo awal, pembelian, dan opname disimpan dengan qty = 1 dan unit_cost =
 * total. Karena itu ambangnya pun berbasis nilai. Ambang kuantitas per bahan
 * menyusul pada Phase 2 saat 305 ingredient punya satuan sungguhan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->decimal('minimum_stock_value', 15, 2)
                ->nullable()
                ->after('category')
                ->comment('Ambang nilai stok (Rp); alert menyala saat nilai stok turun di bawah ini. NULL = tanpa alert.');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('minimum_stock_value');
        });
    }
};
