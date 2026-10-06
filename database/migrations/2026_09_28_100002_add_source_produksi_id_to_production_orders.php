<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak dokumen `produksi` Master Menu yang tidak terkait Pra SPK. Dokumen
 * semacam itu dipindah sebagai SPK Produksi histori tersendiri, dan kolom ini
 * yang membuat perpindahan ulang tidak menggandakannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('source_produksi_id')->nullable()->after('source_spk_id')
                ->comment('Jejak ke produksi.id Master Menu Revamp (dokumen tanpa SPK).');
            $table->unique('source_produksi_id', 'production_orders_source_produksi_unique');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropUnique('production_orders_source_produksi_unique');
            $table->dropColumn('source_produksi_id');
        });
    }
};
