<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Masukan pengguna: supplier tidak ada yang memakai email, yang ada link toko
 * (Tokopedia/Shopee/Instagram) atau website. Panjang 500 karena link toko
 * marketplace sering membawa parameter yang panjang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('website', 500)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('website');
        });
    }
};
