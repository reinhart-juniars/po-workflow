<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('raw_material_cost', 12, 2)->nullable()->after('unit_price');
            $table->decimal('overhead_cost', 12, 2)->nullable()->after('raw_material_cost');
        });

        DB::table('products')
            ->select('id', 'raw_material_cost', 'overhead_cost')
            ->orderBy('id')
            ->chunk(500, function ($products) {
                foreach ($products as $product) {
                    DB::table('purchase_order_items')
                        ->where('product_id', $product->id)
                        ->whereNull('raw_material_cost')
                        ->update([
                            'raw_material_cost' => $product->raw_material_cost,
                            'overhead_cost' => $product->overhead_cost,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn(['raw_material_cost', 'overhead_cost']);
        });
    }
};
