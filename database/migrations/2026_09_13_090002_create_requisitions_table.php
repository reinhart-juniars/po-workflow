<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Form Kebutuhan, Stok & Pembelian Barang per SPK Produksi.
 *
 * Menggantikan form kertas: satu baris per bahan berisi Kebutuhan (hasil
 * ledakan resep x kuantitas), Stok Awal (hitungan fisik), dan Beli
 * (= Kebutuhan - Stok Awal, boleh disunting). Setelah produksi, baris yang
 * sama menampung Pemakaian aktual dan Sisa.
 *
 * Alur persetujuannya bertingkat dan tidak bisa dilompati:
 *   draft (dibuat/diisi) -> approved (disetujui) -> checked (diperiksa saat
 *   barang dibeli). Tiap langkah menyimpan siapa dan kapan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisitions', function (Blueprint $table) {
            $table->id();

            $table->string('number', 40)->unique();

            // Satu form per SPK Produksi. Menyusun ulang form yang sudah ada
            // menyegarkan angka kebutuhannya tanpa membuang isian manusia.
            $table->foreignId('production_order_id')->unique()->constrained('production_orders')->cascadeOnDelete();

            $table->string('status', 20)->default('draft');

            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('requisition_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('requisition_id')->constrained('requisitions')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);

            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->string('name')->comment('Nama bahan saat form disusun.');

            // Seluruh kuantitas di baris ini memakai satuan harga bahan
            // (inventory_items.unit), supaya bisa langsung dikalikan harga dan
            // diposting ke ledger tanpa konversi lagi.
            $table->string('unit', 30);

            $table->decimal('required_qty', 15, 4)->default(0)->comment('Kebutuhan dari resep x kuantitas produksi.');
            $table->decimal('opening_stock_qty', 15, 4)->nullable()->comment('Stok Awal hasil hitungan fisik; diisi manusia.');
            $table->decimal('purchase_qty', 15, 4)->nullable()->comment('Beli = Kebutuhan - Stok Awal; boleh disunting.');
            $table->decimal('unit_price', 15, 4)->nullable()->comment('Harga satuan bahan saat form disusun.');

            $table->decimal('actual_used_qty', 15, 4)->nullable()->comment('Pemakaian aktual setelah produksi; kosong = pakai kebutuhan.');
            $table->decimal('remaining_qty', 15, 4)->nullable()->comment('Sisa stok hasil hitungan fisik setelah produksi.');

            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['requisition_id', 'inventory_item_id'], 'requisition_lines_item_unique');
        });

        // Pembelian bahan baku yang dilakukan untuk sebuah form kebutuhan.
        // Pembeliannya tetap lahir dari modul Pengeluaran; di sini hanya
        // ditautkan, supaya "Diperiksa saat barang dibeli" punya bukti.
        Schema::table('inventory_purchases', function (Blueprint $table) {
            $table->foreignId('requisition_id')
                ->nullable()
                ->after('inventory_item_id')
                ->constrained('requisitions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requisition_id');
        });

        Schema::dropIfExists('requisition_lines');
        Schema::dropIfExists('requisitions');
    }
};
