<?php

use App\Models\Area;
use App\Models\CashAccount;
use App\Models\CashOut;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\ExpenseLocation;
use App\Models\IncomeCategory;
use App\Models\OtherIncome;
use App\Models\PurchaseOrder;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    Role::findOrCreate('accounting', 'web');
});

it('uses submitted sales actual as profit loss revenue instead of completed purchase orders', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('accounting');

    $area = Area::query()->create([
        'name' => 'Area Profit Loss',
        'code' => 'APL',
    ]);

    $customer = Customer::query()->create([
        'name' => 'Customer Profit Loss',
        'area_id' => $area->id,
    ]);

    PurchaseOrder::query()->create([
        'po_number' => 'PO-PROFIT-LOSS-001',
        'customer_id' => $customer->id,
        'recipient_name' => 'Customer Profit Loss',
        'shipping_address' => 'Jl. Profit Loss',
        'area_id' => $area->id,
        'delivery_date' => '2026-04-22',
        'delivery_time' => '09:00:00',
        'payment_type' => 'cash',
        'status' => 'completed',
        'completed_at' => '2026-04-22 10:00:00',
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'total_qty' => 1,
        'total_amount' => 120000,
    ]);

    $salesActual = SalesActual::query()->create([
        'sales_date' => '2026-04-22',
        'customer_id' => $customer->id,
        'status' => 'submitted',
        'submitted_at' => '2026-04-22 14:00:00',
        'submitted_by' => $user->id,
    ]);

    SalesActualItem::query()->create([
        'sales_actual_id' => $salesActual->id,
        'item_name' => 'Paket Actual',
        'unit' => 'box',
        'qty_delivery' => 5,
        'qty_actual' => 3,
        'unit_price' => 15000,
    ]);

    $salesActualCategory = IncomeCategory::query()->create([
        'name' => 'Sales Actual',
        'is_active' => true,
    ]);

    $otherCategory = IncomeCategory::query()->create([
        'name' => 'Lain-lain',
        'is_active' => true,
    ]);

    OtherIncome::query()->create([
        'income_date' => '2026-04-22',
        'income_category_id' => $salesActualCategory->id,
        'cash_account_id' => CashAccount::query()->create([
            'name' => 'Kas Profit Loss',
            'type' => 'cash',
            'is_active' => true,
        ])->id,
        'amount' => 45000,
        'description' => 'Sales Actual #1',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    OtherIncome::query()->create([
        'income_date' => '2026-04-22',
        'income_category_id' => $otherCategory->id,
        'cash_account_id' => CashAccount::query()->first()->id,
        'amount' => 7000,
        'description' => 'Pendapatan lain',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    OtherIncome::query()->create([
        'income_date' => '2026-04-22',
        'income_category_id' => $salesActualCategory->id,
        'cash_account_id' => CashAccount::query()->first()->id,
        'amount' => 11000,
        'description' => 'Penjualan lain manual',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $adjustmentCategory = IncomeCategory::query()->create([
        'name' => 'Adjustment',
        'is_active' => true,
    ]);

    OtherIncome::query()->create([
        'income_date' => '2026-04-22',
        'income_category_id' => $adjustmentCategory->id,
        'cash_account_id' => CashAccount::query()->first()->id,
        'amount' => 67050024,
        'description' => 'Adjustment histori kas',
        'is_adjustment' => true,
        'adjustment_note' => 'Tidak masuk laba rugi',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $expenseAdjustmentCategory = ExpenseCategory::query()->create([
        'name' => 'Adjustment',
        'expense_mode' => ExpenseCategory::MODE_DIRECT_EXPENSE,
        'is_active' => true,
    ]);

    CashOut::query()->create([
        'expense_date' => '2026-04-22',
        'expense_category_id' => $expenseAdjustmentCategory->id,
        'expense_location_id' => ExpenseLocation::query()->create([
            'name' => 'Pusat',
            'type' => 'center',
            'is_active' => true,
        ])->id,
        'cash_account_id' => CashAccount::query()->first()->id,
        'amount' => 9000,
        'description' => 'Adjustment histori biaya',
        'is_adjustment' => true,
        'adjustment_note' => 'Tidak masuk laba rugi',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    actingAs($user);

    get(route('accountingapp.reports.profit-loss', [
        'date_from' => '2026-04-22',
        'date_to' => '2026-04-22',
    ]))
        ->assertOk()
        ->assertViewHas('statement', function (array $statement) {
            return (float) $statement['salesRevenue'] === 45000.0
                && (float) $statement['salesActualRevenueTotal'] === 45000.0
                && (int) $statement['salesActualCount'] === 1
                && (float) $statement['otherIncomeTotal'] === 18000.0
                && (float) $statement['operatingExpenseTotal'] === 0.0
                && (float) $statement['netProfit'] === 63000.0
                && $statement['revenueRows']->pluck('label')->all() === ['Sales Actual'];
        })
        ->assertDontSeeText('Adjustment histori kas')
        ->assertDontSeeText('Adjustment histori biaya')
        ->assertDontSeeText('Rp 67.050.024')
        ->assertDontSeeText('Penjualan dari PO selesai');

    get(route('accountingapp.reports.balance-sheet', [
        'report_date' => '2026-04-30',
    ]))
        ->assertOk()
        ->assertViewHas('equityRows', function ($equityRows) {
            return (float) $equityRows->firstWhere('label', 'Laba Berjalan')['amount'] === 63000.0;
        });
});
