<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_orders')) {
            return;
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_orders', 'due_date')) {
                $table->date('due_date')
                    ->nullable()
                    ->after('receivable_days');
            }

            if (! Schema::hasColumn('purchase_orders', 'cash_received_at')) {
                $table->dateTime('cash_received_at')
                    ->nullable()
                    ->after('completed_at');
            }

            if (! Schema::hasColumn('purchase_orders', 'cash_received_by')) {
                $table->unsignedBigInteger('cash_received_by')
                    ->nullable()
                    ->after('cash_received_at');
            }

            if (! Schema::hasColumn('purchase_orders', 'receivable_status')) {
                $table->enum('receivable_status', ['unpaid', 'partial', 'paid'])
                    ->nullable()
                    ->after('cash_received_by');
            }
        });

        if (
            Schema::hasColumn('purchase_orders', 'cash_received_by') &&
            Schema::hasTable('users')
        ) {
            try {
                Schema::table('purchase_orders', function (Blueprint $table) {
                    $table->foreign('cash_received_by')
                        ->references('id')
                        ->on('users')
                        ->nullOnDelete();
                });
            } catch (\Throwable $e) {
                // FK might already exist.
            }
        }

        if (Schema::hasColumn('purchase_orders', 'payment_type')) {
            DB::statement("
                UPDATE purchase_orders
                SET payment_type = 'cash'
                WHERE payment_type IS NULL
                   OR payment_type NOT IN ('cash', 'receivable', 'tunai', 'piutang')
            ");

            DB::table('purchase_orders')
                ->where('payment_type', 'tunai')
                ->update(['payment_type' => 'cash']);

            DB::table('purchase_orders')
                ->where('payment_type', 'piutang')
                ->update(['payment_type' => 'receivable']);
        }

        if (Schema::hasColumn('purchase_orders', 'payment_due_date') && Schema::hasColumn('purchase_orders', 'due_date')) {
            DB::statement("
                UPDATE purchase_orders
                SET due_date = payment_due_date
                WHERE due_date IS NULL
                  AND payment_due_date IS NOT NULL
            ");
        }

        if (Schema::hasColumn('purchase_orders', 'receivable_paid_at') && Schema::hasColumn('purchase_orders', 'cash_received_at')) {
            DB::statement("
                UPDATE purchase_orders
                SET cash_received_at = receivable_paid_at
                WHERE cash_received_at IS NULL
                  AND receivable_paid_at IS NOT NULL
            ");
        }

        if (
            Schema::hasColumn('purchase_orders', 'status') &&
            Schema::hasColumn('purchase_orders', 'payment_type') &&
            Schema::hasColumn('purchase_orders', 'completed_at') &&
            Schema::hasColumn('purchase_orders', 'cash_received_at')
        ) {
            DB::statement("
                UPDATE purchase_orders
                SET cash_received_at = completed_at
                WHERE status = 'completed'
                  AND payment_type = 'cash'
                  AND completed_at IS NOT NULL
                  AND cash_received_at IS NULL
            ");
        }

        if (
            Schema::hasColumn('purchase_orders', 'cash_received_by') &&
            Schema::hasColumn('purchase_orders', 'updated_by')
        ) {
            DB::statement("
                UPDATE purchase_orders
                SET cash_received_by = updated_by
                WHERE cash_received_at IS NOT NULL
                  AND cash_received_by IS NULL
                  AND updated_by IS NOT NULL
            ");
        }

        if (Schema::hasColumn('purchase_orders', 'payment_type') && Schema::hasColumn('purchase_orders', 'receivable_status')) {
            DB::statement("
                UPDATE purchase_orders
                SET receivable_status = 'paid'
                WHERE payment_type = 'receivable'
                  AND cash_received_at IS NOT NULL
            ");

            DB::statement("
                UPDATE purchase_orders
                SET receivable_status = 'unpaid'
                WHERE payment_type = 'receivable'
                  AND status = 'completed'
                  AND receivable_status IS NULL
            ");

            DB::statement("
                UPDATE purchase_orders
                SET receivable_status = NULL
                WHERE payment_type = 'cash'
            ");
        }

        if (Schema::hasColumn('purchase_orders', 'payment_type') && DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE purchase_orders
                MODIFY payment_type ENUM('cash', 'receivable') NOT NULL DEFAULT 'cash'
            ");
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders', 'payment_due_date')) {
                $table->dropColumn('payment_due_date');
            }

            if (Schema::hasColumn('purchase_orders', 'receivable_paid_at')) {
                $table->dropColumn('receivable_paid_at');
            }
        });
    }

    public function down(): void
    {
        // Keep no-op to avoid destructive rollback on live data.
    }
};
