<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_actuals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_order_id')->nullable()->constrained('delivery_orders')->nullOnDelete();
            $table->date('sales_date')->index();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->enum('status', ['draft', 'submitted'])->default('draft')->index();
            $table->dateTime('submitted_at')->nullable()->index();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['delivery_order_id', 'customer_id'], 'sales_actual_delivery_customer_unique');
            $table->index(['customer_id', 'sales_date', 'status'], 'sales_actual_customer_date_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_actuals');
    }
};
