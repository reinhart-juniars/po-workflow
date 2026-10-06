<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profit_loss_adjustments', function (Blueprint $table) {
            if (! Schema::hasColumn('profit_loss_adjustments', 'expense_category_id')) {
                $table->foreignId('expense_category_id')
                    ->nullable()
                    ->after('statement_group')
                    ->constrained('expense_categories')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('profit_loss_adjustments') || ! Schema::hasColumn('profit_loss_adjustments', 'expense_category_id')) {
            return;
        }

        Schema::table('profit_loss_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_category_id');
        });
    }
};
