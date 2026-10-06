<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('expense_categories', 'expense_mode')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `expense_categories` MODIFY `expense_mode` ENUM('direct_expense', 'inventory_purchase', 'fixed_asset') NOT NULL DEFAULT 'direct_expense'");

            return;
        }

        // SQLite/lainnya: enum diwujudkan sebagai check constraint. Lepas ke string
        // biasa supaya nilai 'fixed_asset' diterima (validasi nilai ada di aplikasi).
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->string('expense_mode')->default('direct_expense')->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('expense_categories', 'expense_mode')) {
            return;
        }

        DB::table('expense_categories')
            ->where('expense_mode', 'fixed_asset')
            ->update(['expense_mode' => 'direct_expense']);

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `expense_categories` MODIFY `expense_mode` ENUM('direct_expense', 'inventory_purchase') NOT NULL DEFAULT 'direct_expense'");

            return;
        }

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->string('expense_mode')->default('direct_expense')->change();
        });
    }
};
