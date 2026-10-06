<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_actual_items', function (Blueprint $table) {
            $table->decimal('raw_material_cost', 15, 2)->nullable()->after('unit_price');
            $table->decimal('overhead_cost', 15, 2)->nullable()->after('raw_material_cost');
        });

        DB::table('sales_actual_items')
            ->whereNotNull('purchase_order_item_id')
            ->whereNull('raw_material_cost')
            ->orderBy('id')
            ->chunkById(500, function ($items) {
                foreach ($items as $item) {
                    $poItem = DB::table('purchase_order_items')
                        ->where('id', $item->purchase_order_item_id)
                        ->first(['raw_material_cost', 'overhead_cost']);

                    if (! $poItem) {
                        continue;
                    }

                    DB::table('sales_actual_items')
                        ->where('id', $item->id)
                        ->update([
                            'raw_material_cost' => $poItem->raw_material_cost,
                            'overhead_cost' => $poItem->overhead_cost,
                        ]);
                }
            });

        DB::table('sales_actual_items')
            ->whereNotNull('product_id')
            ->whereNull('raw_material_cost')
            ->orderBy('id')
            ->chunkById(500, function ($items) {
                foreach ($items as $item) {
                    $product = DB::table('products')
                        ->where('id', $item->product_id)
                        ->first(['raw_material_cost', 'overhead_cost']);

                    if (! $product) {
                        continue;
                    }

                    DB::table('sales_actual_items')
                        ->where('id', $item->id)
                        ->update([
                            'raw_material_cost' => $product->raw_material_cost,
                            'overhead_cost' => $product->overhead_cost,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('sales_actual_items', function (Blueprint $table) {
            $table->dropColumn(['raw_material_cost', 'overhead_cost']);
        });
    }
};
