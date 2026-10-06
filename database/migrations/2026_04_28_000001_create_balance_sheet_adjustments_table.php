<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_sheet_adjustments', function (Blueprint $table) {
            $table->id();
            $table->date('adjustment_date');
            $table->enum('account_group', ['cash', 'receivable', 'inventory', 'fixed_asset', 'payable', 'equity', 'wealth']);
            $table->string('label');
            $table->decimal('amount', 15, 2);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['account_group', 'adjustment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_sheet_adjustments');
    }
};
