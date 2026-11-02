<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hari kerja per baris Lembar Kerja + tugas umum harian dapur.
 *
 * - production_tasks.days_before: dikerjakan berapa hari sebelum tanggal SPK
 *   Produksi (H). Bawaan 1 = H-1, kebiasaan dapur selama ini; persiapan
 *   seperti potong dan buat sambal boleh dimajukan ke H-2 / H-3.
 * - daily_chores: tugas yang bukan milik menu mana pun (cek stok gudang,
 *   buang sampah, bersihkan alat) dan muncul di setiap Rekap Lembar Kerja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_tasks', function (Blueprint $table) {
            $table->unsignedTinyInteger('days_before')->default(1)->after('quantity_text');
        });

        Schema::create('daily_chores', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('worker_name')->nullable();
            $table->string('task');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_chores');

        Schema::table('production_tasks', function (Blueprint $table) {
            $table->dropColumn('days_before');
        });
    }
};
