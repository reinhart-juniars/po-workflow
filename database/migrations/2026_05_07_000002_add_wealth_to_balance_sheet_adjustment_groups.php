<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE balance_sheet_adjustments MODIFY account_group ENUM('cash', 'receivable', 'inventory', 'fixed_asset', 'payable', 'equity', 'wealth') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('balance_sheet_adjustments')
                ->where('account_group', 'wealth')
                ->update(['account_group' => 'equity']);

            DB::statement("ALTER TABLE balance_sheet_adjustments MODIFY account_group ENUM('cash', 'receivable', 'inventory', 'fixed_asset', 'payable', 'equity') NOT NULL");
        }
    }
};
