<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();

            $table->date('balance_date');
            $table->enum('type', ['cash', 'receivable', 'payable']);
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->decimal('amount', 15, 2);
            $table->text('description')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['type', 'balance_date']);
            $table->index('reference_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balances');
    }
};
