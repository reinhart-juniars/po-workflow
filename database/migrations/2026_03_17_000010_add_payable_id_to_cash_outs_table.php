<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_outs') || Schema::hasColumn('cash_outs', 'payable_id')) {
            return;
        }

        Schema::table('cash_outs', function (Blueprint $table) {
            $table->foreignId('payable_id')
                ->nullable()
                ->after('cash_account_id')
                ->constrained('payables')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cash_outs') || ! Schema::hasColumn('cash_outs', 'payable_id')) {
            return;
        }

        Schema::table('cash_outs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payable_id');
        });
    }
};
