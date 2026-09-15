<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penerimaan barang di Form Kebutuhan.
 *
 * Sebelumnya tahap Disetujui hanya menyimpan jumlah Diterima; barang yang
 * ditolak tidak berjejak, harga yang dipakai kartu stok adalah harga master,
 * dan nota belanja harus diinput ulang oleh accounting. Kini per baris
 * dicatat jumlah ditolak (+ alasan & perlakuannya) dan harga beli aktual, dan
 * form membawa cara pembayarannya supaya saat Periksa pembelian bahan baku
 * (beserta kas keluar / hutang) dibuat otomatis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_lines', function (Blueprint $table) {
            $table->decimal('rejected_qty', 18, 4)->nullable()->after('received_qty');
            $table->string('rejected_reason')->nullable()->after('rejected_qty');
            // retur = dikembalikan / tidak dibayar; dibayar = tetap dibayar, masuk kerugian barang rusak
            $table->string('rejected_treatment', 20)->nullable()->after('rejected_reason');
            // Harga beli aktual per satuan (dari nota); NULL = memakai harga master (unit_price).
            $table->decimal('purchase_price', 18, 4)->nullable()->after('unit_price');
            $table->foreignId('inventory_purchase_id')->nullable()->after('purchase_price')->constrained('inventory_purchases')->nullOnDelete();
            $table->foreignId('damaged_purchase_id')->nullable()->after('inventory_purchase_id')->constrained('inventory_purchases')->nullOnDelete();
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->string('payment_type', 10)->nullable()->after('notes');
            $table->foreignId('expense_category_id')->nullable()->after('payment_type')->constrained('expense_categories')->nullOnDelete();
            $table->foreignId('cash_account_id')->nullable()->after('expense_category_id')->constrained('cash_accounts')->nullOnDelete();
            $table->string('supplier_name')->nullable()->after('cash_account_id');
            $table->date('due_date')->nullable()->after('supplier_name');
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_category_id');
            $table->dropConstrainedForeignId('cash_account_id');
            $table->dropColumn(['payment_type', 'supplier_name', 'due_date']);
        });

        Schema::table('requisition_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_purchase_id');
            $table->dropConstrainedForeignId('damaged_purchase_id');
            $table->dropColumn(['rejected_qty', 'rejected_reason', 'rejected_treatment', 'purchase_price']);
        });
    }
};
