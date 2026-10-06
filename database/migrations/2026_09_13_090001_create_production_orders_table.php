<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPK Produksi: surat perintah kerja dapur untuk satu waktu produksi.
 *
 * Bukan tabel `spks` yang sudah ada. `Spk` po-workflow adalah slot jadwal
 * (03/07/11) yang mengikat beberapa PO, sedangkan yang ini adalah daftar menu
 * yang harus dimasak berikut kuantitasnya -- objek yang di Master Menu Revamp
 * bernama "spk". Keduanya sengaja tidak disatukan: mengubah 478 baris `spks`
 * beserta alur PO yang memakainya adalah pekerjaan di luar PRD, dan mengganti
 * namanya membingungkan klien yang memakai kedua istilah itu sehari-hari.
 *
 * Barisnya lahir dari item PO lewat produk -> resep (recipes.product_id), atau
 * ditambah manual. Sumber pesanannya tetap PO yang sudah ada; tidak ada modul
 * Order kedua.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();

            $table->string('number', 40)->unique();
            $table->string('title')->nullable();

            // Slot jadwal po-workflow yang menjadi asal SPK Produksi ini, bila
            // di-generate dari sana. NULL untuk SPK yang disusun manual atau
            // yang dipindahkan dari Master Menu.
            $table->foreignId('spk_id')->nullable()->constrained('spks')->nullOnDelete();

            $table->date('production_date');
            $table->string('production_time', 10)->nullable()->comment('Jam mulai produksi, mis. 08.30');

            // draft      -> masih disusun
            // planned    -> baris menu final; form kebutuhan bisa disusun
            // completed  -> produksi selesai; pemakaian bahan sudah diposting ke ledger
            // cancelled  -> dibatalkan
            $table->string('status', 20)->default('draft');

            $table->text('notes')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedBigInteger('source_spk_id')->nullable()->comment('Jejak ke spk.id Master Menu Revamp.');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['production_date', 'status']);
            $table->unique('source_spk_id', 'production_orders_source_spk_unique');
        });

        Schema::create('production_order_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);

            // menu   -> menunjuk resep; kebutuhan bahannya dihitung
            // manual -> teks bebas (mis. "siapkan es batu"); tidak dihitung
            $table->string('kind', 10)->default('menu');

            $table->foreignId('recipe_id')->nullable()->constrained('recipes')->nullOnDelete();

            // po          -> lahir dari item PO; disegarkan/dihapus mengikuti PO
            // manual      -> ditambah dapur; tidak pernah disentuh penyegaran
            // master_menu -> histori dari Master Menu Revamp
            $table->string('source', 20)->default('manual');

            // Item PO yang melahirkan baris ini, supaya perubahan PO bisa
            // ditelusuri dan baris yang sama tidak dibuat dua kali. Menjadi NULL
            // bila item PO-nya dihapus; kolom source-lah yang menandai bahwa
            // baris itu dulunya dari PO, sehingga tidak menyamar jadi baris manual.
            $table->foreignId('purchase_order_item_id')->nullable()->constrained('purchase_order_items')->nullOnDelete();

            $table->string('label')->nullable()->comment('Nama menu apa adanya; tetap terbaca meski resepnya dihapus.');
            $table->decimal('qty', 15, 4)->default(0);
            $table->string('unit', 30)->nullable();
            $table->string('remark')->nullable();

            $table->timestamps();

            $table->index(['production_order_id', 'sort_order']);
            $table->index('recipe_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_lines');
        Schema::dropIfExists('production_orders');
    }
};
