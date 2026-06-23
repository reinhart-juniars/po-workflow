<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $packagingIds = DB::table('expense_categories')
                ->whereRaw('LOWER(name) = ?', ['packaging'])
                ->pluck('id');

            if ($packagingIds->isEmpty()) {
                return;
            }

            $existingKemasan = DB::table('expense_categories')
                ->whereRaw('LOWER(name) = ?', ['kemasan'])
                ->first();

            if ($existingKemasan) {
                DB::table('cash_outs')
                    ->whereIn('expense_category_id', $packagingIds)
                    ->update(['expense_category_id' => $existingKemasan->id]);

                DB::table('profit_loss_adjustments')
                    ->whereIn('expense_category_id', $packagingIds)
                    ->update(['expense_category_id' => $existingKemasan->id]);

                DB::table('expense_categories')
                    ->whereIn('id', $packagingIds)
                    ->delete();
            } else {
                DB::table('expense_categories')
                    ->whereIn('id', $packagingIds)
                    ->update(['name' => 'Kemasan']);
            }
        });
    }

    public function down(): void
    {
        DB::table('expense_categories')
            ->whereRaw('LOWER(name) = ?', ['kemasan'])
            ->update(['name' => 'Packaging']);
    }
};
