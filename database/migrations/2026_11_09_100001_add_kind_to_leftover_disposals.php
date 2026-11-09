<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barang Sisa "Batal": porsi yang tercatat sebagai Barang Sisa tapi ternyata
 * tidak jadi dibuat. Sama seperti dibuang, stoknya berkurang; bedanya tidak
 * dihitung sebagai waste (bahan bakunya tidak terpakai). Baris lama = waste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leftover_disposals', function (Blueprint $table) {
            $table->string('kind', 20)->default('waste')->after('leftover_component_id');
        });
    }

    public function down(): void
    {
        Schema::table('leftover_disposals', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
