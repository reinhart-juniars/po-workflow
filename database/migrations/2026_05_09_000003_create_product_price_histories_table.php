<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('base_price', 12, 2);
            $table->decimal('raw_material_cost', 12, 2)->nullable();
            $table->decimal('overhead_cost', 12, 2)->nullable();
            $table->decimal('profit', 12, 2)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('effective_from')->index();
            $table->timestamps();

            $table->index(['product_id', 'effective_from']);
        });

        $now = now();

        DB::table('products')
            ->select('id', 'base_price', 'raw_material_cost', 'overhead_cost', 'profit', 'created_at')
            ->orderBy('id')
            ->chunk(500, function ($products) use ($now) {
                $rows = [];

                foreach ($products as $product) {
                    $rows[] = [
                        'product_id' => $product->id,
                        'base_price' => $product->base_price,
                        'raw_material_cost' => $product->raw_material_cost,
                        'overhead_cost' => $product->overhead_cost,
                        'profit' => $product->profit,
                        'changed_by' => null,
                        'reason' => 'Backfill: harga awal pada saat fitur price history diaktifkan.',
                        'effective_from' => $product->created_at ?? $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if (! empty($rows)) {
                    DB::table('product_price_histories')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_histories');
    }
};
