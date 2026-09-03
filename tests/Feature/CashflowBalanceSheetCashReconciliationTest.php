<?php

use App\Models\Area;
use App\Models\BalanceSheetAdjustment;
use App\Models\CashAccount;
use App\Models\CashOut;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\ExpenseLocation;
use App\Models\IncomeCategory;
use App\Models\OpeningBalance;
use App\Models\OtherIncome;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\BalanceSheetService;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    Role::findOrCreate('accounting', 'web');
});

/**
 * Saldo akhir Cashflow dan total Kas di Neraca harus dihitung dari populasi transaksi
 * yang sama. Keduanya dulu memakai predikat PO yang berbeda (Cashflow menyaring
 * status = 'completed', Neraca tidak), sehingga bisa berbeda tanpa ketahuan.
 */
function reconciliationScenario(string $poStatus = 'completed'): array
{
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('accounting');

    $cashAccount = CashAccount::query()->create([
        'name' => 'BCA Rekonsiliasi',
        'type' => 'bank',
        'is_active' => true,
    ]);

    OpeningBalance::query()->create([
        'balance_date' => '2026-02-01',
        'type' => 'cash',
        'reference_id' => $cashAccount->id,
        'amount' => 1_000_000,
        'description' => 'Saldo awal kas',
    ]);

    $area = Area::query()->create(['name' => 'Area Rekonsiliasi', 'code' => 'ARK']);
    $customer = Customer::query()->create(['name' => 'Customer Rekonsiliasi', 'area_id' => $area->id]);

    // PO piutang: total 500.000 termasuk ongkir 50.000. Porsi principal 450.000 masuk
    // sebagai "Penerimaan PO", ongkirnya dibukukan terpisah sebagai OtherIncome.
    $po = PurchaseOrder::query()->create([
        'po_number' => 'PO-REKON-001',
        'customer_id' => $customer->id,
        'recipient_name' => 'Customer Rekonsiliasi',
        'shipping_address' => 'Jl. Rekonsiliasi',
        'area_id' => $area->id,
        'delivery_date' => '2026-02-10',
        'delivery_time' => '09:00:00',
        'payment_type' => 'receivable',
        'cash_account_id' => $cashAccount->id,
        'cash_received_at' => '2026-02-10 10:00:00',
        'status' => $poStatus,
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'total_qty' => 1,
        'total_amount' => 500_000,
        'shipping_cost' => 50_000,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'qty' => 1,
        'unit_price' => 450_000,
        'subtotal' => 450_000,
    ]);

    // Menyimpan item memicu PurchaseOrder::recalcTotals() yang hanya menjumlah subtotal
    // item, jadi total_amount ditulis ulang seperti yang dilakukan AdminAppController:
    // (sum item - diskon) + ongkir.
    $po->refresh();
    $po->total_amount = 500_000;
    $po->save();

    $shippingCategory = IncomeCategory::query()->create([
        'name' => OtherIncome::CATEGORY_OTHER_SALES,
        'is_active' => true,
    ]);

    OtherIncome::query()->create([
        'income_date' => '2026-02-10',
        'income_category_id' => $shippingCategory->id,
        'cash_account_id' => $cashAccount->id,
        'source_type' => OtherIncome::SOURCE_PURCHASE_ORDER_SHIPPING,
        'source_id' => $po->id,
        'amount' => 50_000,
        'description' => 'Ongkir Pelunasan PO PO-REKON-001',
    ]);

    $expenseCategory = ExpenseCategory::query()->create([
        'name' => 'Operasional',
        'expense_mode' => ExpenseCategory::MODE_DIRECT_EXPENSE,
        'is_active' => true,
    ]);

    $expenseLocation = ExpenseLocation::query()->create([
        'name' => 'Pusat',
        'type' => 'center',
        'is_active' => true,
    ]);

    CashOut::query()->create([
        'expense_category_id' => $expenseCategory->id,
        'expense_location_id' => $expenseLocation->id,
        'cash_account_id' => $cashAccount->id,
        'amount' => 200_000,
        'expense_date' => '2026-02-15',
        'description' => 'Biaya operasional',
    ]);

    return [$user, $cashAccount];
}

function cashflowEndingBalance(User $user): float
{
    actingAs($user);

    $response = get(route('accountingapp.reports.cashflow', [
        'date_from' => '2026-02-01',
        'date_to' => '2026-02-28',
    ]))->assertOk();

    return round((float) $response->viewData('cashflowSummary')['ending_balance'], 2);
}

function balanceSheetCashTotal(): float
{
    $report = app(BalanceSheetService::class)->buildReport(Carbon::parse('2026-02-28'));

    return round((float) collect($report['assetGroups'])->firstWhere('title', 'Kas')['total'], 2);
}

function balanceSheetWealthAt(string $date): float
{
    $report = app(BalanceSheetService::class)->buildReport(Carbon::parse($date));

    return round((float) $report['wealthAmount'], 2);
}

it('reports the same cash balance in cashflow and balance sheet', function () {
    [$user] = reconciliationScenario();

    // 1.000.000 saldo awal + 450.000 principal PO + 50.000 ongkir - 200.000 pengeluaran.
    $expected = 1_300_000.0;

    expect(cashflowEndingBalance($user))->toBe($expected)
        ->and(balanceSheetCashTotal())->toBe($expected);
});

it('counts a purchase order whose cash is in but status is not yet completed in both reports', function () {
    // Regresi: Cashflow dulu menyaring status = 'completed' sementara Neraca tidak,
    // jadi PO seperti ini bikin kedua laporan berbeda.
    [$user] = reconciliationScenario('ready_for_delivery');

    $expected = 1_300_000.0;

    expect(cashflowEndingBalance($user))->toBe($expected)
        ->and(balanceSheetCashTotal())->toBe($expected);
});

it('ignores a purchase order that has not been paid in either report', function () {
    // Kontrol positif untuk dua test di atas: kalau kasnya memang belum masuk,
    // angkanya harus benar-benar bergerak turun, bukan kebetulan selalu sama.
    [$user] = reconciliationScenario();

    PurchaseOrder::query()->where('po_number', 'PO-REKON-001')->update([
        'cash_received_at' => null,
    ]);

    $expected = 850_000.0;

    expect(cashflowEndingBalance($user))->toBe($expected)
        ->and(balanceSheetCashTotal())->toBe($expected);
});

it('applies balance sheet cash adjustments to the balance sheet only', function () {
    // Adjustment neraca sengaja tidak dibaca laporan Cashflow. Selisih antara kedua
    // laporan karena itu harus persis sebesar net adjustment kas, bukan angka lain.
    [$user] = reconciliationScenario();

    BalanceSheetAdjustment::query()->create([
        'adjustment_date' => '2026-02-20',
        'account_group' => BalanceSheetAdjustment::GROUP_CASH,
        'label' => 'Koreksi Kas',
        'amount' => -300_000,
        'notes' => 'Koreksi manual',
    ]);

    expect(cashflowEndingBalance($user))->toBe(1_300_000.0)
        ->and(balanceSheetCashTotal())->toBe(1_000_000.0);
});

it('shifts wealth one for one with a cash adjustment and neutralises it with a reversal', function () {
    // Asumsi yang dipakai saat mengalibrasi "Penyesuaian Kekayaan Mei": adjustment kas
    // menggeser Kekayaan persis sebesar nominalnya, dan pembalik bertanggal setelahnya
    // membuat periode berikutnya kembali netral.
    reconciliationScenario();

    $wealthInAdjustedPeriod = balanceSheetWealthAt('2026-02-28');
    $wealthAfterReversal = balanceSheetWealthAt('2026-03-31');

    BalanceSheetAdjustment::query()->create([
        'adjustment_date' => '2026-02-20',
        'account_group' => BalanceSheetAdjustment::GROUP_CASH,
        'label' => 'Penyesuaian Kekayaan',
        'amount' => -300_000,
        'notes' => 'Kalibrasi ke target',
    ]);

    BalanceSheetAdjustment::query()->create([
        'adjustment_date' => '2026-03-01',
        'account_group' => BalanceSheetAdjustment::GROUP_CASH,
        'label' => 'Pembalik Penyesuaian Kekayaan',
        'amount' => 300_000,
        'notes' => 'Pembalik kalibrasi',
    ]);

    expect(balanceSheetWealthAt('2026-02-28'))->toBe($wealthInAdjustedPeriod - 300_000.0)
        ->and(balanceSheetWealthAt('2026-03-31'))->toBe($wealthAfterReversal);
});
