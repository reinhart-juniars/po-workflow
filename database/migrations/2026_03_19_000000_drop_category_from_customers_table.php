<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'category')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'category')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->enum('category', ['lapak', 'retail'])
                ->default('retail')
                ->after('active');
        });
    }
};
