<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan modul: satu baris per kunci, nilainya JSON supaya angka, boolean,
 * dan teks bisa disimpan tanpa kolom terpisah. Daftar kunci, tipe, dan nilai
 * bawaannya ada di App\Support\Settings\SettingRegistry -- tabel ini hanya
 * menyimpan yang pernah diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
