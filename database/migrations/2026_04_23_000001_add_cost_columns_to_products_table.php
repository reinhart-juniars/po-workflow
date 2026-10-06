<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('raw_material_cost', 12, 2)->nullable();
            $table->decimal('overhead_cost', 12, 2)->nullable();
            $table->decimal('profit', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'raw_material_cost',
                'overhead_cost',
                'profit',
            ]);
        });
    }
};
