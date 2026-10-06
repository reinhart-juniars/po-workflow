<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_account_transfers', function (Blueprint $table) {
            $table->id();
            $table->date('transfer_date');
            $table->foreignId('from_cash_account_id')->constrained('cash_accounts')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('to_cash_account_id')->constrained('cash_accounts')->cascadeOnUpdate()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('transfer_date');
            $table->index('from_cash_account_id');
            $table->index('to_cash_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_account_transfers');
    }
};
