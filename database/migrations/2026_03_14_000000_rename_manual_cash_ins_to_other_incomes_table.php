<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('manual_cash_ins') && ! Schema::hasTable('other_incomes')) {
            Schema::rename('manual_cash_ins', 'other_incomes');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('other_incomes') && ! Schema::hasTable('manual_cash_ins')) {
            Schema::rename('other_incomes', 'manual_cash_ins');
        }
    }
};
