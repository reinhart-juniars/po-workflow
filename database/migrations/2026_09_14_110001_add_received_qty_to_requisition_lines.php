<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jumlah yang benar-benar diterima saat barang datang. Diisi pada tahap
 * Disetujui (sebelum Periksa): barang yang datang rusak/"Tidak Baik" dikurangi
 * di sini supaya ledger stok hanya bertambah sebesar yang layak pakai --
 * sejalan dengan InventoryPurchase::CONDITION_DAMAGED pada nilai rupiah.
 * NULL berarti diterima persis sejumlah Beli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_lines', function (Blueprint $table) {
            $table->decimal('received_qty', 18, 4)->nullable()->after('purchase_qty');
        });
    }

    public function down(): void
    {
        Schema::table('requisition_lines', function (Blueprint $table) {
            $table->dropColumn('received_qty');
        });
    }
};
