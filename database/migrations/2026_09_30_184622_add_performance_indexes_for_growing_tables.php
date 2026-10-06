<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index untuk query yang biayanya ikut membesar seiring umur data (hasil
 * stress test dengan data +/-12x, setara +/-5 tahun operasi):
 *
 * - audit_logs.created_at & action: halaman Audit Logs memfilter & mengurutkan
 *   created_at dan mengisi pilihan filter dari DISTINCT action; tanpa index
 *   tabel terbesar disapu penuh tiap halaman dibuka.
 * - sales_actuals(status, submitted_at): total penjualan/retur memakai
 *   EXISTS status = submitted AND submitted_at BETWEEN.
 * - sales_actual_items.qty_return: stok Barang Sisa mencari qty_return > 0.
 * - purchase_orders.created_at: Laporan PO per rentang tanggal dibuat.
 * - stock_opnames(inventory_item_id, opname_date): opname terakhir per bahan.
 * - inventory_movements: index penutup untuk agregat kartu stok -- nilai per
 *   jenis & tanggal (laporan pemakaian, laba rugi, neraca) dan saldo qty per
 *   bahan (Opname Bahan, Breakdown Bahan) terbaca dari index saja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('created_at');
            $table->index('action');
        });

        Schema::table('sales_actuals', function (Blueprint $table) {
            $table->index(['status', 'submitted_at']);
        });

        Schema::table('sales_actual_items', function (Blueprint $table) {
            $table->index('qty_return');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->index(['inventory_item_id', 'opname_date']);
        });

        // Index baru dibuat dulu supaya foreign key inventory_item_id tetap
        // punya index yang melayaninya saat index lama dilepas.
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->index(['type', 'moved_at', 'inventory_item_id', 'total_value'], 'inventory_movements_type_date_item_value_index');
            $table->index(['inventory_item_id', 'moved_at', 'qty'], 'inventory_movements_item_date_qty_index');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex(['type', 'moved_at']);
            $table->dropIndex(['inventory_item_id', 'moved_at']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->index(['type', 'moved_at']);
            $table->index(['inventory_item_id', 'moved_at']);
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex('inventory_movements_type_date_item_value_index');
            $table->dropIndex('inventory_movements_item_date_qty_index');
        });

        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->dropIndex(['inventory_item_id', 'opname_date']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('sales_actual_items', function (Blueprint $table) {
            $table->dropIndex(['qty_return']);
        });

        Schema::table('sales_actuals', function (Blueprint $table) {
            $table->dropIndex(['status', 'submitted_at']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['action']);
        });
    }
};
