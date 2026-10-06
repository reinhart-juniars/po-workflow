<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_actual_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_actual_id')->constrained('sales_actuals')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained('purchase_order_items')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('item_name');
            $table->string('unit')->nullable();
            $table->decimal('qty_delivery', 15, 2)->default(0);
            $table->decimal('qty_actual', 15, 2)->default(0);
            $table->decimal('qty_return', 15, 2)->default(0);
            $table->decimal('qty_cancel', 15, 2)->default(0);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('subtotal_actual', 15, 2)->default(0);
            $table->boolean('is_carry_forward')->default(false)->index();
            $table->foreignId('source_sales_actual_item_id')->nullable()->constrained('sales_actual_items')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['sales_actual_id', 'purchase_order_item_id'], 'sales_actual_po_item_unique');
            $table->unique('source_sales_actual_item_id', 'sales_actual_source_item_unique');
            $table->index(['product_id', 'item_name'], 'sales_actual_item_product_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_actual_items');
    }
};
