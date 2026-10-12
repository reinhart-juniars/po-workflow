<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asal HPP (Bahan Baku) & OHC produk: diketik admin ('manual') atau
 * diturunkan dari resep di aplikasi Menu ('resep'). Revisi 7 Okt 2026:
 * produk bertaut resep yang hitungannya bersih mengikuti resep dan tidak
 * bisa diubah dari Admin › Master Menu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('cost_source', 10)->default('manual')->after('profit');
            $table->timestamp('cost_synced_at')->nullable()->after('cost_source');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['cost_source', 'cost_synced_at']);
        });
    }
};
