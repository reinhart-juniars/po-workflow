<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemeriksa barang masuk (revisi 31 Okt 2026).
 *
 * Gudang dipegang satu orang, jadi Form Kebutuhan diajukan, disetujui, dan
 * diperiksa oleh akun yang sama. Pemeriksa fisik barang datang adalah orang
 * lain yang tidak memegang sistem; namanya dipilih oleh user inventory saat
 * Periksa. Daftar namanya digabung ke master Pelaksana (bukan tabel baru):
 * satu orang bisa menjadi pelaksana dapur, pemeriksa, atau keduanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_workers', function (Blueprint $table) {
            $table->boolean('is_executor')->default(true)->after('name')
                ->comment('Muncul di pilihan pelaksana lembar kerja.');
            $table->boolean('is_inspector')->default(false)->after('is_executor')
                ->comment('Muncul di pilihan pemeriksa barang masuk.');
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->string('inspector_name')->nullable()->after('checked_at')
                ->comment('Pemeriksa fisik barang masuk; checked_by = user yang mencatat.');
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropColumn('inspector_name');
        });

        Schema::table('production_workers', function (Blueprint $table) {
            $table->dropColumn(['is_executor', 'is_inspector']);
        });
    }
};
