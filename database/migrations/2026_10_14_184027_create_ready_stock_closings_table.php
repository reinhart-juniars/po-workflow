<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi v3.1 -- Tutup Stok Siap per tanggal.
 *
 * Porsi Rencana Masak yang sampai akhir hari tidak terdistribusi ke PO menjadi
 * Barang Sisa. Supaya seluruh mesin Barang Sisa (jual ke customer lain, rinci
 * per komponen, buang, nilai persediaan di Neraca & HPP) berlaku apa adanya,
 * sisanya dicatat sebagai Sales Actual INTERNAL tanpa pendapatan: qty actual
 * 0, retur = sisa, dinilai HPP resep per porsi -- persis seperti retur.
 *
 * Sales Actual internal ditandai is_internal dan disaring dari daftar Sales
 * Actual & laporan penjualan; customernya ("Stok Siap Dapur") ditandai
 * is_internal dan nonaktif supaya tidak muncul di pilihan customer PO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false)->after('is_lapak');
        });

        Schema::table('sales_actuals', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false)->after('status');
        });

        Schema::create('ready_stock_closings', function (Blueprint $table) {
            $table->id();
            $table->date('production_date')->unique();
            $table->foreignId('sales_actual_id')->nullable()->constrained('sales_actuals')->nullOnDelete();
            $table->decimal('leftover_qty', 15, 2)->default(0);
            $table->decimal('leftover_value', 15, 2)->default(0);
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ready_stock_closings');

        Schema::table('sales_actuals', function (Blueprint $table) {
            $table->dropColumn('is_internal');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('is_internal');
        });
    }
};
