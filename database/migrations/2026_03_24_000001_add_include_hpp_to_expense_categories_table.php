<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('expense_categories')) {
            return;
        }

        Schema::table('expense_categories', function (Blueprint $table) {
            if (! Schema::hasColumn('expense_categories', 'include_hpp')) {
                $table->boolean('include_hpp')->default(false)->after('expense_mode');
            }
        });

        DB::table('expense_categories')
            ->whereRaw('LOWER(name) = ?', ['bahan baku'])
            ->update(['include_hpp' => true]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('expense_categories') || ! Schema::hasColumn('expense_categories', 'include_hpp')) {
            return;
        }

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn('include_hpp');
        });
    }
};
