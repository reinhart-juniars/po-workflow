<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bahan pada resep yang belum punya padanan di master bahan.
 *
 * Dari 11.595 baris resep, 4.878 tidak punya bahan maupun sub-resep -- tetapi
 * hanya 365 nama unik, terkonsentrasi pada bahan dasar yang belum terdaftar
 * (garam muncul di 196 resep, gula 336 baris, bawang putih 271). Selama baris
 * ini belum diselesaikan, HPP berbasis resep belum bisa dipercaya.
 *
 * Dikelompokkan per nama, bukan per baris, supaya menjadi daftar kerja yang bisa
 * diselesaikan manusia: satu keputusan menautkan ratusan baris sekaligus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_mismatches', function (Blueprint $table) {
            $table->id();

            $table->string('raw_name');
            $table->string('raw_name_norm')->unique();

            $table->unsignedInteger('occurrence_count')->default(0)->comment('Jumlah baris resep yang memakai nama ini.');
            $table->unsignedInteger('recipe_count')->default(0)->comment('Jumlah resep berbeda yang memakai nama ini.');

            $table->string('sample_unit', 30)->nullable();
            $table->decimal('assumed_unit_price', 15, 4)->nullable();

            // open     -> belum diputuskan
            // linked   -> ditautkan ke bahan yang sudah ada
            // created  -> bahan baru dibuat untuk nama ini
            // ignored  -> sengaja diabaikan (mis. keterangan, bukan bahan)
            $table->string('status', 20)->default('open');

            $table->foreignId('resolved_inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();

            $table->timestamps();

            $table->index(['status', 'occurrence_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_mismatches');
    }
};
