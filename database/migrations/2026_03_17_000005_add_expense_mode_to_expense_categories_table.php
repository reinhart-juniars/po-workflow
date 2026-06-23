<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('expense_categories')) {
            return;
        }

        Schema::table('expense_categories', function (Blueprint $table) {
            if (! Schema::hasColumn('expense_categories', 'expense_mode')) {
                $table->enum('expense_mode', ['direct_expense', 'inventory_purchase'])
                    ->default('direct_expense')
                    ->after('description');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('expense_categories') || ! Schema::hasColumn('expense_categories', 'expense_mode')) {
            return;
        }

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn('expense_mode');
        });
    }
};
