<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $kemasanIds = DB::table('expense_categories')
                ->whereRaw('LOWER(name) = ?', ['kemasan'])
                ->pluck('id');

            if ($kemasanIds->isEmpty()) {
                return;
            }

            $existingPackaging = DB::table('expense_categories')
                ->whereRaw('LOWER(name) = ?', ['packaging'])
                ->first();

            if ($existingPackaging) {
                DB::table('cash_outs')
                    ->whereIn('expense_category_id', $kemasanIds)
                    ->update(['expense_category_id' => $existingPackaging->id]);

                DB::table('profit_loss_adjustments')
                    ->whereIn('expense_category_id', $kemasanIds)
                    ->update(['expense_category_id' => $existingPackaging->id]);

                DB::table('expense_categories')
                    ->whereIn('id', $kemasanIds)
                    ->delete();
            } else {
                DB::table('expense_categories')
                    ->whereIn('id', $kemasanIds)
                    ->update(['name' => 'Packaging']);
            }
        });
    }

    public function down(): void
    {
        DB::table('expense_categories')
            ->whereRaw('LOWER(name) = ?', ['packaging'])
            ->update(['name' => 'Kemasan']);
    }
};
