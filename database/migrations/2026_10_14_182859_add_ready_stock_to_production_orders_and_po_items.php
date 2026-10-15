<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi v3.1 -- Rencana Masak (stok siap).
 *
 * Inventory menyusun rencana masak lebih awal (H-2 s/d H-7) untuk lapak yang
 * pesanannya pasti: SPK Produksi berjenis rencana, tanpa slot/PO. Admin lalu
 * mendistribusikan porsinya ke PO: item PO bertanda from_ready_stock diambil
 * dari rencana masak bertanggal sama dengan tanggal kirim PO, dan tidak ikut
 * dimasak lagi di SPK Produksi slot -- bahannya terhitung sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->boolean('is_ready_stock')->default(false)->after('spk_id');
            $table->index(['is_ready_stock', 'production_date']);
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->boolean('from_ready_stock')->default(false)->after('price_tier');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('from_ready_stock');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropIndex(['is_ready_stock', 'production_date']);
            $table->dropColumn('is_ready_stock');
        });
    }
};
