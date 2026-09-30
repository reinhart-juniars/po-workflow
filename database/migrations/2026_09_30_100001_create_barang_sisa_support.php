<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barang Sisa: retur penjualan menjadi stok barang jadi yang bisa dijual ke
 * customer mana pun, bukan lagi otomatis dibawa ke draft customer yang sama.
 *
 * Stoknya tidak disimpan sebagai saldo -- dihitung dari data yang sudah ada:
 *   masuk  = qty_return item Sales Actual yang sudah disubmit
 *   keluar = item "Penjualan Barang Sisa" (source_sales_actual_item_id) dan
 *            pembuangan di leftover_disposals
 *
 * Karena itu satu retur kini boleh dijual ke beberapa customer: unique pada
 * source_sales_actual_item_id diganti index biasa (index baru dibuat dulu
 * supaya foreign key-nya tidak kehilangan index di MySQL).
 *
 * purchase_order_id menentukan cara bayar (tunai/piutang, akun kas) sebuah
 * Penjualan Barang Sisa: PO customer pembeli, bukan PO customer asal retur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_actual_items', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')
                ->nullable()
                ->after('purchase_order_item_id')
                ->constrained('purchase_orders')
                ->nullOnDelete();
            $table->index('source_sales_actual_item_id', 'sales_actual_source_item_idx');
        });

        Schema::table('sales_actual_items', function (Blueprint $table) {
            $table->dropUnique('sales_actual_source_item_unique');
        });

        Schema::create('leftover_disposals', function (Blueprint $table) {
            $table->id();
            // Item Sales Actual yang returnya dibuang.
            $table->foreignId('source_sales_actual_item_id')->constrained('sales_actual_items')->cascadeOnDelete();
            $table->date('disposed_at');
            $table->decimal('qty', 15, 2);
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('disposed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leftover_disposals');

        Schema::table('sales_actual_items', function (Blueprint $table) {
            $table->unique('source_sales_actual_item_id', 'sales_actual_source_item_unique');
        });

        Schema::table('sales_actual_items', function (Blueprint $table) {
            $table->dropIndex('sales_actual_source_item_idx');
            $table->dropConstrainedForeignId('purchase_order_id');
        });
    }
};
