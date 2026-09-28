<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi Katalog Foto Menu: tim marketing mencentang menu mana yang tampil di
 * website. Bawaannya tidak tampil -- menu baru tidak ikut terbit sebelum ada
 * yang memutuskannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('show_on_website')->default(false)->after('photo_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('show_on_website');
        });
    }
};
