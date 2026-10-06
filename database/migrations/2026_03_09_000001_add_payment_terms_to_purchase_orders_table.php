<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->enum('payment_type', ['cash', 'receivable'])
                ->default('cash')
                ->after('shipping_cost');

            $table->unsignedInteger('receivable_days')
                ->nullable()
                ->after('payment_type');

            $table->date('due_date')
                ->nullable()
                ->after('receivable_days');

            $table->dateTime('cash_received_at')
                ->nullable()
                ->after('completed_at');

            $table->foreignId('cash_received_by')
                ->nullable()
                ->after('cash_received_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->enum('receivable_status', ['unpaid', 'partial', 'paid'])
                ->nullable()
                ->after('cash_received_by');

            $table->index('payment_type');
            $table->index('due_date');
            $table->index('cash_received_at');
            $table->index('receivable_status');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_received_by');
            $table->dropIndex(['payment_type']);
            $table->dropIndex(['due_date']);
            $table->dropIndex(['cash_received_at']);
            $table->dropIndex(['receivable_status']);
            $table->dropColumn([
                'payment_type',
                'receivable_days',
                'due_date',
                'cash_received_at',
                'receivable_status',
            ]);
        });
    }
};
