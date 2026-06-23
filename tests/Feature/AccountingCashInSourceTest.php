<?php

use App\Models\Area;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\IncomeCategory;
use App\Models\OtherIncome;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    Role::findOrCreate('owner', 'web');
});

it('does not mark cash po as cash received when production is completed', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('owner');

    $area = Area::query()->create([
        'name' => 'Area Cash PO',
        'code' => 'ACP',
    ]);

    $customer = Customer::query()->create([
        'name' => 'Customer Cash PO',
        'area_id' => $area->id,
    ]);

    $cashAccount = CashAccount::query()->create([
        'name' => 'Kas Operasional',
        'type' => 'cash',
        'is_active' => true,
    ]);

    $po = PurchaseOrder::query()->create([
        'po_number' => 'PO-CASH-PROD-001',
        'customer_id' => $customer->id,
        'recipient_name' => 'Customer Cash PO',
        'shipping_address' => 'Jl. Cash PO',
        'area_id' => $area->id,
        'delivery_date' => now()->toDateString(),
        'delivery_time' => '09:00:00',
        'payment_type' => 'cash',
        'cash_account_id' => $cashAccount->id,
        'status' => 'in_progress',
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'total_qty' => 1,
        'total_amount' => 120000,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'qty' => 1,
        'unit_price' => 120000,
        'subtotal' => 120000,
    ]);

    actingAs($user);

    post(route('productionapp.orders.complete', $po))
        ->assertRedirect(route('productionapp.dashboard'));

    expect($po->fresh()->status)->toBe('completed')
        ->and($po->fresh()->cash_received_at)->toBeNull()
        ->and($po->fresh()->cash_received_by)->toBeNull();
});

it('counts only receivable settlements and submitted sales actual cash in on accounting dashboard', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('owner');

    $area = Area::query()->create([
        'name' => 'Area Accounting Cash In',
        'code' => 'ACI',
    ]);

    $customer = Customer::query()->create([
        'name' => 'Customer Accounting',
        'area_id' => $area->id,
    ]);

    $cashAccount = CashAccount::query()->create([
        'name' => 'Kas Accounting',
        'type' => 'cash',
        'is_active' => true,
    ]);

    $incomeCategory = IncomeCategory::query()->create([
        'name' => 'Sales Actual',
        'description' => 'Kategori test sales actual',
        'is_active' => true,
    ]);

    PurchaseOrder::query()->create([
        'po_number' => 'PO-CASH-LEGACY-001',
        'customer_id' => $customer->id,
        'recipient_name' => 'Customer Accounting',
        'shipping_address' => 'Jl. Cash Legacy',
        'area_id' => $area->id,
        'delivery_date' => '2026-04-21',
        'delivery_time' => '08:00:00',
        'payment_type' => 'cash',
        'cash_account_id' => $cashAccount->id,
        'cash_received_at' => '2026-04-21 10:00:00',
        'cash_received_by' => $user->id,
        'status' => 'completed',
        'completed_at' => '2026-04-21 09:00:00',
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'total_qty' => 1,
        'total_amount' => 120000,
    ]);

    PurchaseOrder::query()->create([
        'po_number' => 'PO-RECEIVABLE-PAID-001',
        'customer_id' => $customer->id,
        'recipient_name' => 'Customer Accounting',
        'shipping_address' => 'Jl. Receivable',
        'area_id' => $area->id,
        'delivery_date' => '2026-04-21',
        'delivery_time' => '11:00:00',
        'payment_type' => 'receivable',
        'cash_account_id' => $cashAccount->id,
        'cash_received_at' => '2026-04-21 15:00:00',
        'cash_received_by' => $user->id,
        'receivable_status' => 'paid',
        'status' => 'completed',
        'completed_at' => '2026-04-20 09:00:00',
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'total_qty' => 1,
        'total_amount' => 80000,
    ]);

    DB::table('other_incomes')->insert([
        'income_date' => '2026-04-21',
        'income_category_id' => $incomeCategory->id,
        'cash_account_id' => $cashAccount->id,
        'amount' => 30000,
        'description' => 'Sales Actual #1',
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    actingAs($user);

    get(route('accountingapp.dashboard', [
        'date_from' => '2026-04-21',
        'date_to' => '2026-04-21',
    ]))
        ->assertOk()
        ->assertViewHas('totalCashIn', 80000)
        ->assertViewHas('otherIncomeTotal', 30000)
        ->assertViewHas('totalCashInAll', 110000);

    get(route('accountingapp.reports.cashflow', [
        'date_from' => '2026-04-21',
        'date_to' => '2026-04-21',
        'chart_granularity' => 'day',
    ]))
        ->assertOk()
        ->assertSeeText('Detail 2 item')
        ->assertViewHas('cashflowPeriods', function (array $periods) {
            $incomeBreakdown = collect($periods)->flatMap(fn (array $period) => $period['income_breakdown'] ?? []);

            return $incomeBreakdown->contains(fn (array $row) => $row['label'] === 'Pelunasan'
                && ($row['reference'] ?? null) === 'PO-RECEIVABLE-PAID-001'
                && (float) $row['amount'] === 80000.0);
        });
});
