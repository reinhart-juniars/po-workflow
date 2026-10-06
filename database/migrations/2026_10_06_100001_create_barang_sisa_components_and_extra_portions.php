<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adendum revisi Owner (Okt 2026).
 *
 * 1. Rincian Barang Sisa: sebagian porsi retur dipecah user menjadi komponen
 *    yang diketik bebas (nasi, telur, ...) dengan nilai yang juga diketik
 *    sendiri. Porsi yang dirinci keluar dari stok entri (leftover_breakdowns),
 *    komponennya menjadi stok sendiri (leftover_components) yang bisa dijual
 *    atau dibuang. Penjualan/pembuangan komponen menunjuk komponennya lewat
 *    leftover_component_id, di samping entri asalnya.
 *
 * 2. Porsi Tambahan: customer meminta lebih dari yang dikirim (mis. ganti
 *    menu); baris tambahan di Sales Actual ditagih dengan harga PO dan
 *    ditandai is_extra_portion. Cara bayarnya lewat purchase_order_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leftover_breakdowns', function (Blueprint $table) {
            $table->id();
            // Entri Barang Sisa (item Sales Actual bersisa retur) yang dirinci.
            $table->foreignId('source_sales_actual_item_id')->constrained('sales_actual_items')->cascadeOnDelete();
            $table->date('broken_at');
            $table->decimal('portion_qty', 15, 2);
            // Snapshot HPP porsi yang dirinci; selisih dengan total nilai komponen = waste.
            $table->decimal('portion_value', 15, 2);
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('broken_at');
        });

        Schema::create('leftover_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leftover_breakdown_id')->constrained('leftover_breakdowns')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('unit', 30);
            $table->decimal('qty', 15, 2);
            // Nilai HPP total komponen (diketik user); per satuan = value / qty.
            $table->decimal('value', 15, 2);
            $table->timestamps();
        });

        Schema::table('leftover_disposals', function (Blueprint $table) {
            $table->foreignId('leftover_component_id')
                ->nullable()
                ->after('source_sales_actual_item_id')
                ->constrained('leftover_components')
                ->restrictOnDelete();
        });

        Schema::table('sales_actual_items', function (Blueprint $table) {
            $table->foreignId('leftover_component_id')
                ->nullable()
                ->after('source_sales_actual_item_id')
                ->constrained('leftover_components')
                ->restrictOnDelete();
            $table->boolean('is_extra_portion')->default(false)->after('is_carry_forward');
        });
    }

    public function down(): void
    {
        Schema::table('sales_actual_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leftover_component_id');
            $table->dropColumn('is_extra_portion');
        });

        Schema::table('leftover_disposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leftover_component_id');
        });

        Schema::dropIfExists('leftover_components');
        Schema::dropIfExists('leftover_breakdowns');
    }
};
