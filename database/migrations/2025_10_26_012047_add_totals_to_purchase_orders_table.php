<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $t) {
            if (!Schema::hasColumn('purchase_orders','total_qty')) {
                $t->unsignedInteger('total_qty')->default(0);
            }
            if (!Schema::hasColumn('purchase_orders','total_amount')) {
                $t->decimal('total_amount', 12, 2)->default(0);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $t) {
            if (Schema::hasColumn('purchase_orders','total_qty')) 
                $t->dropColumn('total_qty');
            if (Schema::hasColumn('purchase_orders','total_amount')) 
                $t->dropColumn('total_amount');
        });
    }
};
