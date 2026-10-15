<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bagian C: riwayat tombol "Terbitkan ke Website". feed_version = sidik isi
 * menu saat diterbitkan (WebsiteMenuFeed), untuk penanda "ada perubahan yang
 * belum diterbitkan".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_publishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20); // terkirim | gagal
            $table->unsignedInteger('menu_count');
            $table->string('feed_version', 40);
            $table->string('error')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_publishes');
    }
};
