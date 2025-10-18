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
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('recipient_name');
            $table->text('shipping_address');
            $table->foreignId('area_id')->constrained()->restrictOnDelete();
            $table->date('delivery_date');
            $table->time('delivery_time');
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->enum('status', ['pending','scheduled','in_production','ready_for_delivery','delivered'])->index();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
