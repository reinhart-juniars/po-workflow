<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Supplier untuk bahan baku & kemasan, beserta daftar bahan yang
 * dipasok tiap supplier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('contact_person')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            // Mis. "BCA 1234567890 a.n. Budi" -- dipakai saat melunasi hutang.
            $table->string('bank_account')->nullable();
            // Termin bayar kredit; jatuh tempo pembelian kredit yang kosong
            // diisi tanggal transaksi + termin ini.
            $table->unsignedSmallInteger('payment_term_days')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('inventory_item_supplier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['supplier_id', 'inventory_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_item_supplier');
        Schema::dropIfExists('suppliers');
    }
};
