<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            'Bahan Baku' => ExpenseCategory::MODE_INVENTORY_PURCHASE,
            'Kemasan' => ExpenseCategory::MODE_INVENTORY_PURCHASE,
            'Gas' => ExpenseCategory::MODE_DIRECT_EXPENSE,
            'Transport' => ExpenseCategory::MODE_DIRECT_EXPENSE,
            'Gaji' => ExpenseCategory::MODE_DIRECT_EXPENSE,
            'Operasional' => ExpenseCategory::MODE_DIRECT_EXPENSE,
            'Maintenance' => ExpenseCategory::MODE_DIRECT_EXPENSE,
            'Lain-lain' => ExpenseCategory::MODE_DIRECT_EXPENSE,
        ];

        $hppNames = ['Bahan Baku', 'Kemasan'];

        foreach ($items as $name => $expenseMode) {
            ExpenseCategory::firstOrCreate(['name' => $name], [
                'expense_mode' => $expenseMode,
                'include_hpp' => in_array($name, $hppNames, true),
                'is_active' => true,
            ]);
        }
    }
}
