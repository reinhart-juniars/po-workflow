<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profit_loss_adjustments', function (Blueprint $table) {
            $table->id();
            $table->date('adjustment_date');
            $table->enum('statement_group', ['revenue', 'cogs', 'operating_expense', 'other_income']);
            $table->string('label');
            $table->decimal('amount', 15, 2);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['statement_group', 'adjustment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profit_loss_adjustments');
    }
};
