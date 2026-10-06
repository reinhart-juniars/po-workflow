<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_daily_closings', function (Blueprint $table) {
            $table->date('cash_in_date')->nullable()->after('closing_date');
        });

        DB::statement('UPDATE sales_daily_closings SET cash_in_date = closing_date WHERE cash_in_date IS NULL');
    }

    public function down(): void
    {
        Schema::table('sales_daily_closings', function (Blueprint $table) {
            $table->dropColumn('cash_in_date');
        });
    }
};
