<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger kuantitas stok per bahan.
 *
 * Inventory yang sudah berjalan mencatat nilai rupiah per bucket (qty selalu
 * 1, unit_cost = total) dan tidak tahu kuantitas apa pun. Ledger ini berjalan
 * berdampingan: setiap gerakan stok sebuah bahan dicatat sebagai satu baris
 * bertanda, dalam satuan harga bahan itu, sehingga saldo = jumlah qty.
 *
 * Saldo awal tiap bahan datang dari kolom Stok Awal pada form kebutuhan
 * pertama yang menyebutnya -- bukan dari sesi opname khusus -- karena itulah
 * hitungan fisik yang memang sudah dilakukan dapur setiap kali menyusun form.
 *
 * Pemakaian produksi yang dicatat di sini adalah yang dibandingkan dengan HPP
 * residual (Saldo Awal + Beli - Opname) selama masa uji paralel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->date('moved_at');

            // opening    -> saldo awal dari hitungan fisik (form kebutuhan pertama)
            // purchase   -> barang masuk dari pembelian
            // usage      -> keluar untuk produksi (resep x qty, atau aktual)
            // adjustment -> koreksi ke hasil hitungan fisik (sisa stok)
            $table->string('type', 20);

            // Bertanda: masuk positif, keluar negatif. Satuannya satuan harga
            // bahan, supaya saldo bisa dijumlahkan tanpa konversi.
            $table->decimal('qty', 15, 4);
            $table->string('unit', 30);

            $table->decimal('unit_price', 15, 4)->nullable();
            $table->decimal('total_value', 15, 2)->nullable()->comment('qty x unit_price, bertanda sama dengan qty.');

            $table->foreignId('production_order_id')->nullable()->constrained('production_orders')->nullOnDelete();
            $table->foreignId('requisition_line_id')->nullable()->constrained('requisition_lines')->nullOnDelete();
            $table->foreignId('inventory_purchase_id')->nullable()->constrained('inventory_purchases')->nullOnDelete();

            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['inventory_item_id', 'moved_at']);
            $table->index(['type', 'moved_at']);
            $table->index('production_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
