<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_inventories', function (Blueprint $table) {
            $table->id();
            $table->date('balance_date');
            $table->string('item_name');
            $table->decimal('qty', 15, 2);
            $table->string('unit', 50);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('total_value', 15, 2);
            $table->text('notes')->nullable();
            $table->boolean('is_adjustment')->default(false);
            $table->text('adjustment_note')->nullable();
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('balance_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_inventories');
    }
};
