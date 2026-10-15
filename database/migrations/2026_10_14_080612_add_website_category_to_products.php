<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bagian C: kelompok menu di halaman website (Menu Ayam, Frozen Food, ...).
 * Kuncinya didefinisikan di config/website.php. Kosong = menu belum bisa
 * terbit walau sudah dicentang, karena website tidak tahu letaknya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('website_category', 40)->nullable()->after('show_on_website');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('website_category');
        });
    }
};
