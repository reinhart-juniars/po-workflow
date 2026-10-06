<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->date('transaction_date');
            $table->decimal('qty', 15, 2);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('total_value', 15, 2);
            $table->enum('payment_type', ['cash', 'payable'])->default('cash');
            $table->foreignId('cash_out_id')->nullable()->constrained('cash_outs')->nullOnDelete();
            $table->foreignId('payable_id')->nullable()->constrained('payables')->nullOnDelete();
            $table->string('supplier_name')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('transaction_date');
            $table->index('payment_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_purchases');
    }
};
