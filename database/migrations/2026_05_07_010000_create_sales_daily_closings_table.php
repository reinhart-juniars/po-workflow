<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_daily_closings', function (Blueprint $table) {
            $table->id();
            $table->date('closing_date')->unique();
            $table->decimal('gross_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('posted_at')->nullable()->index();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('sales_actuals', function (Blueprint $table) {
            $table->foreignId('sales_daily_closing_id')
                ->nullable()
                ->after('submitted_by')
                ->constrained('sales_daily_closings')
                ->nullOnDelete();
            $table->index(['sales_date', 'status', 'sales_daily_closing_id'], 'sales_actual_closing_lookup_idx');
        });

        $this->backfillExistingSalesActualCashIns();
    }

    public function down(): void
    {
        Schema::table('sales_actuals', function (Blueprint $table) {
            $table->dropIndex('sales_actual_closing_lookup_idx');
            $table->dropConstrainedForeignId('sales_daily_closing_id');
        });

        Schema::dropIfExists('sales_daily_closings');
    }

    private function backfillExistingSalesActualCashIns(): void
    {
        if (! Schema::hasTable('other_incomes') || ! Schema::hasColumn('other_incomes', 'source_type')) {
            return;
        }

        $salesActualIds = DB::table('other_incomes')
            ->where('source_type', \App\Models\SalesActual::class)
            ->whereNotNull('source_id')
            ->pluck('source_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($salesActualIds->isEmpty()) {
            return;
        }

        DB::table('sales_actuals')
            ->whereIn('id', $salesActualIds)
            ->whereNotNull('sales_date')
            ->get(['id', 'sales_date'])
            ->groupBy('sales_date')
            ->each(function ($salesActuals, string $salesDate) {
                $ids = collect($salesActuals)->pluck('id')->map(fn ($id) => (int) $id)->values();

                if ($ids->isEmpty()) {
                    return;
                }

                $grossAmount = round((float) DB::table('sales_actual_items')
                    ->whereIn('sales_actual_id', $ids)
                    ->sum('subtotal_actual'), 2);

                $netAmount = round((float) DB::table('other_incomes')
                    ->where('source_type', \App\Models\SalesActual::class)
                    ->whereIn('source_id', $ids)
                    ->sum('amount'), 2);

                $closingId = DB::table('sales_daily_closings')->insertGetId([
                    'closing_date' => $salesDate,
                    'gross_amount' => $grossAmount,
                    'discount_amount' => max(0, round($grossAmount - $netAmount, 2)),
                    'net_amount' => $netAmount,
                    'notes' => 'Backfill dari cash in Sales Actual sebelum fitur closing harian.',
                    'posted_at' => now(),
                    'posted_by' => null,
                    'created_by' => null,
                    'updated_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('sales_actuals')
                    ->whereIn('id', $ids)
                    ->whereNull('sales_daily_closing_id')
                    ->update([
                        'sales_daily_closing_id' => $closingId,
                        'updated_at' => now(),
                    ]);
            });
    }
};
