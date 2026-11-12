<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Status "archived" (label: Nonaktif) untuk draft Sales Actual V2 yang tidak
 * pernah disubmit. Dinonaktifkan lewat sales-actual:nonaktifkan-draft-v2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_actuals', function (Blueprint $table) {
            $table->enum('status', ['draft', 'submitted', 'archived'])->default('draft')->change();
        });
    }

    public function down(): void
    {
        DB::table('sales_actuals')->where('status', 'archived')->update(['status' => 'draft']);

        Schema::table('sales_actuals', function (Blueprint $table) {
            $table->enum('status', ['draft', 'submitted'])->default('draft')->change();
        });
    }
};
