<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('other_incomes', function (Blueprint $table) {
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->index(['source_type', 'source_id'], 'other_incomes_source_idx');
        });

        if (! Schema::hasTable('sales_actuals')) {
            return;
        }

        $salesActualCategoryIds = DB::table('income_categories')
            ->where('name', 'Sales Actual')
            ->pluck('id');

        if ($salesActualCategoryIds->isEmpty()) {
            return;
        }

        DB::table('other_incomes')
            ->whereIn('income_category_id', $salesActualCategoryIds)
            ->whereNull('source_type')
            ->orderBy('id')
            ->get(['id', 'description'])
            ->each(function ($income) {
                if (preg_match('/^Sales Actual #(\d+)(?:\b|$)/i', trim((string) $income->description), $matches) !== 1) {
                    return;
                }

                $salesActualId = (int) $matches[1];

                if (! DB::table('sales_actuals')->where('id', $salesActualId)->exists()) {
                    return;
                }

                DB::table('other_incomes')
                    ->where('id', $income->id)
                    ->update([
                        'source_type' => \App\Models\SalesActual::class,
                        'source_id' => $salesActualId,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('other_incomes', function (Blueprint $table) {
            $table->dropIndex('other_incomes_source_idx');
            $table->dropColumn(['source_type', 'source_id']);
        });
    }
};
