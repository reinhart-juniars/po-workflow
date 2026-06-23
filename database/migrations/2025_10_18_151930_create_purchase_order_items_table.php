<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty'); // >=0

            $table->boolean('is_custom')->default(false);
            $table->string('custom_name')->nullable();
            
            $table->string('unit')->default('porsi');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_percent', 5, 2)->default(0); // boleh 0
            $table->decimal('subtotal', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            //     // (opsional) cek batasan nilai, lebih portable lintas DB
            // $table->check('qty >= 0');
            // $table->check('unit_price >= 0');
            // $table->check('discount_percent >= 0 AND discount_percent <= 100');
            // $table->check('subtotal >= 0');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
