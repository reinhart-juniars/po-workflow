<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * inventory_purchases.payment_type dibuat ENUM('cash','payable') di Maret;
 * Tagihan Pembelian (9 Okt 2026) menambah nilai 'bill' tanpa melebarkan
 * kolomnya. Di MySQL produksi tiap "Periksa" Form Kebutuhan gagal dengan
 * "Data truncated for column 'payment_type'", sementara test (SQLite) tetap
 * hijau karena di sana kolomnya sudah varchar tanpa CHECK.
 *
 * Dijadikan VARCHAR(20) supaya skema MySQL sama dengan skema test; nilai
 * yang sah dijaga validasi aplikasi (Rule::in) dan konstanta model.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE inventory_purchases MODIFY payment_type VARCHAR(20) NOT NULL DEFAULT 'cash'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (DB::table('inventory_purchases')->whereNotIn('payment_type', ['cash', 'payable'])->exists()) {
            throw new \RuntimeException('Masih ada pembelian bertipe Tagihan (bill); kolom tidak bisa dikembalikan ke ENUM(cash, payable).');
        }

        DB::statement("ALTER TABLE inventory_purchases MODIFY payment_type ENUM('cash','payable') NOT NULL DEFAULT 'cash'");
    }
};
