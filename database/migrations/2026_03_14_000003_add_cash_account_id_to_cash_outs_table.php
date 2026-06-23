<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_outs') || Schema::hasColumn('cash_outs', 'cash_account_id')) {
            return;
        }

        Schema::table('cash_outs', function (Blueprint $table) {
            $table->foreignId('cash_account_id')
                ->nullable()
                ->after('expense_location_id')
                ->constrained('cash_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cash_outs') || ! Schema::hasColumn('cash_outs', 'cash_account_id')) {
            return;
        }

        Schema::table('cash_outs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_account_id');
        });
    }
};
