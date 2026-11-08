<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paritas V2 → V3 (8 Nov 2026): di V2, Biaya Marketing September 2026 sudah
 * di luar Laba Rugi (pengurang Kekayaan). Tanggal mulai "Mengurangi Kekayaan"
 * dimajukan ke 1 Sep supaya Laba Rugi, Neraca & Analisa HPP s/d Oktober sama
 * persis dengan angka V2 yang sudah dilaporkan ke owner.
 *
 * Hanya nilai tersimpan yang masih bawaan lama (2026-10-01) yang diubah;
 * tanggal lain yang sengaja diatur accounting dibiarkan.
 */
return new class extends Migration
{
    private const KEY = 'wealth_reduction.accounting_start';

    public function up(): void
    {
        DB::table('app_settings')
            ->where('key', self::KEY)
            ->where('value', json_encode('2026-10-01'))
            ->update(['value' => json_encode('2026-09-01'), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('app_settings')
            ->where('key', self::KEY)
            ->where('value', json_encode('2026-09-01'))
            ->update(['value' => json_encode('2026-10-01'), 'updated_at' => now()]);
    }
};
