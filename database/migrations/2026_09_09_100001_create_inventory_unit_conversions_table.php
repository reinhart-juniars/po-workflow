<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aturan konversi satuan per bahan.
 *
 * Registri satuan hanya bisa mengonversi di dalam besaran yang sama (gr <-> kg,
 * ml <-> liter). Padahal di data resep yang paling sering terjadi justru
 * lintas-besaran: resep menulis "300 gr ayam" sementara harga ayam tersimpan
 * per "pcs". Pembandingnya berbeda-beda tiap bahan -- satu pcs ayam bukan satu
 * pcs telur -- jadi tidak bisa ditaruh di tabel satuan dan harus jadi data yang
 * bisa diisi pengguna sendiri.
 *
 * Satu baris berarti: 1 from_unit = factor to_unit, dan berlaku dua arah.
 * Baris tanpa inventory_item_id adalah aturan umum yang dipakai sebagai
 * cadangan bila bahannya belum punya aturan sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_unit_conversions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inventory_item_id')
                ->nullable()
                ->constrained('inventory_items')
                ->cascadeOnDelete()
                ->comment('Kosong berarti aturan umum untuk semua bahan.');

            $table->string('from_unit', 40);
            $table->string('to_unit', 40);

            // 6 desimal supaya pembanding kecil seperti 1 butir = 0,0625 kg
            // tetap tersimpan utuh.
            $table->decimal('factor', 18, 6)->comment('1 from_unit = factor to_unit.');

            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Satu pasangan satuan hanya boleh punya satu aturan per bahan.
            // Catatan: pada aturan umum (inventory_item_id NULL) MySQL menganggap
            // NULL selalu berbeda, jadi keunikannya dijaga tambahan di aplikasi.
            $table->unique(['inventory_item_id', 'from_unit', 'to_unit'], 'inv_unit_conv_unique');
            $table->index(['from_unit', 'to_unit'], 'inv_unit_conv_pair_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_unit_conversions');
    }
};
