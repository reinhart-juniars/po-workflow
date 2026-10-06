<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_actual_items', function (Blueprint $table) {
            $table->decimal('qty_waste', 15, 2)->default(0)->after('qty_return');
        });
    }

    public function down(): void
    {
        Schema::table('sales_actual_items', function (Blueprint $table) {
            $table->dropColumn('qty_waste');
        });
    }
};
