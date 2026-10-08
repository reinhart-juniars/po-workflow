<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Mode kategori "Mengurangi Kekayaan (di luar Laba Rugi)", mis. Biaya Marketing.
 * Hanya mengubah daftar ENUM; tidak ada data yang dipindah.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('expense_categories', 'expense_mode')) {
            return;
        }

        // SQLite/lainnya sudah memakai string biasa (lihat 2026_06_02_000001), cukup MySQL.
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `expense_categories` MODIFY `expense_mode` ENUM('direct_expense', 'inventory_purchase', 'fixed_asset', 'wealth_reduction') NOT NULL DEFAULT 'direct_expense'");
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('expense_categories', 'expense_mode')) {
            return;
        }

        DB::table('expense_categories')
            ->where('expense_mode', 'wealth_reduction')
            ->update(['expense_mode' => 'direct_expense']);

        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `expense_categories` MODIFY `expense_mode` ENUM('direct_expense', 'inventory_purchase', 'fixed_asset') NOT NULL DEFAULT 'direct_expense'");
        }
    }
};
