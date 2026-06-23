<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->enum('category', ['lapak', 'retail'])
                ->default('retail')
                ->after('active');
        });

        DB::table('customers')
            ->whereNull('category')
            ->update(['category' => 'retail']);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};

