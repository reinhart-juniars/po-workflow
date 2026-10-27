<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bagian C: isi menu persis seperti saat "Terbitkan ke Website" ditekan.
 * Website dibangun dari snapshot terakhir ini, bukan dari data yang sedang
 * disunting -- jadi website hanya berubah setelah marketing melihat pratinjau
 * dan menerbitkannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_publishes', function (Blueprint $table) {
            $table->longText('snapshot')->nullable()->after('feed_version');
        });
    }

    public function down(): void
    {
        Schema::table('website_publishes', function (Blueprint $table) {
            $table->dropColumn('snapshot');
        });
    }
};
