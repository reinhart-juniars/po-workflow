<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cara bayar Penjualan Barang Sisa yang dipilih langsung: tunai ke akun kas
 * tertentu, tanpa PO. Dipakai untuk customer yang hari itu tidak punya DO
 * (mis. lapak). Null berarti cara bayar mengikuti PO (purchase_order_id atau
 * rantai retur) seperti sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_actual_items', function (Blueprint $table) {
            $table->foreignId('cash_account_id')
                ->nullable()
                ->after('purchase_order_id')
                ->constrained('cash_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_actual_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_account_id');
        });
    }
};
