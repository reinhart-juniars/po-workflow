<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'is_lapak')) {
                $table->boolean('is_lapak')->default(false)->after('active');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'is_lapak')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('is_lapak');
        });
    }
};
