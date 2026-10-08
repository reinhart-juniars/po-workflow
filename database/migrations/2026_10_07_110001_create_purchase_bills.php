<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tagihan Pembelian (revisi 7 Okt 2026): gudang belanja lalu menagih ke
 * accounting. Satu tagihan = satu nota belanja (per Form Kebutuhan, atau
 * belanja lepas). Pembelian bahan baku menunjuk tagihannya; hutangnya satu
 * per tagihan (payable_id), lahir saat barang diterima supaya Neraca tetap
 * seimbang sebelum accounting membayar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_bills', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('status', 20)->default('draft')->index();
            $table->date('bill_date');
            $table->foreignId('requisition_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_name')->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->string('receipt_path')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('payable_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_out_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_account_id')->nullable()->constrained()->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->date('paid_on')->nullable();
            $table->text('return_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::table('inventory_purchases', function (Blueprint $table) {
            $table->foreignId('purchase_bill_id')->nullable()->after('requisition_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_bill_id');
        });

        Schema::dropIfExists('purchase_bills');
    }
};
