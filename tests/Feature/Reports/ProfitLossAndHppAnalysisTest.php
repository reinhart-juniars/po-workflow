<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/*
 * Laba Rugi / Laporan Final harus sama dengan Laba Berjalan di neraca (ongkir ikut dihitung),
 * dan Analisa HPP: OHC Real = Pengeluaran Laba Rugi, selisih Profit % dibatasi 4%.
 *
 * Diport dari V2 (8 Nov 2026) supaya KPI owner di V3 sama persis dengan V2.
 */

function reportUser(string $role): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

/**
 * Satu bulan transaksi:
 * - Sales actual Rp 1.000.000 (10 pcs, HPP resep Rp $rawCostPerUnit / pcs)
 * - Ongkir (Penjualan Lain-Lain) Rp $shipping            -> masuk laba
 * - Kembaran "Sales Actual" dari closing Rp 1.000.000    -> tidak boleh dobel
 * - Pemasukan kategori Adjustment Rp 70.000              -> tidak masuk laba
 * - Gaji Rp 200.000 (biaya)                              -> masuk laba & OHC Real
 * - Investasi Lain Rp 300.000 (aktiva tetap)            -> tidak masuk laba & OHC Real
 */
function seedReportMonth(string $month, int $rawCostPerUnit, int $shipping = 50_000, int $sales = 1_000_000, int $salary = 200_000): void
{
    $now = now();
    $date = "{$month}-15";

    $areaId = DB::table('areas')->insertGetId(['name' => "Area {$month}", 'code' => "A{$month}", 'created_at' => $now, 'updated_at' => $now]);
    $customerId = DB::table('customers')->insertGetId(['area_id' => $areaId, 'name' => "Customer {$month}", 'created_at' => $now, 'updated_at' => $now]);
    $productId = DB::table('products')->insertGetId(['name' => "Nasi {$month}", 'raw_material_cost' => $rawCostPerUnit, 'overhead_cost' => 0, 'created_at' => $now, 'updated_at' => $now]);

    $salesActualId = DB::table('sales_actuals')->insertGetId([
        'customer_id' => $customerId,
        'sales_date' => $date,
        'status' => 'submitted',
        'submitted_at' => "{$date} 10:00:00",
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('sales_actual_items')->insert([
        'sales_actual_id' => $salesActualId,
        'product_id' => $productId,
        'item_name' => "Nasi {$month}",
        'qty_actual' => 10,
        'subtotal_actual' => $sales,
        'raw_material_cost' => $rawCostPerUnit,
        'overhead_cost' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $cashAccountId = DB::table('cash_accounts')->insertGetId(['name' => "Kas {$month}", 'type' => 'cash', 'created_at' => $now, 'updated_at' => $now]);
    $incomeCategory = fn (string $name) => DB::table('income_categories')->where('name', $name)->value('id')
        ?? DB::table('income_categories')->insertGetId(['name' => $name, 'created_at' => $now, 'updated_at' => $now]);

    DB::table('other_incomes')->insert([
        ['income_date' => $date, 'income_category_id' => $incomeCategory('Penjualan Lain-Lain'), 'cash_account_id' => $cashAccountId, 'amount' => $shipping, 'source_type' => null, 'source_id' => null, 'description' => 'Ongkir', 'created_at' => $now, 'updated_at' => $now],
        ['income_date' => $date, 'income_category_id' => $incomeCategory('Sales Actual'), 'cash_account_id' => $cashAccountId, 'amount' => $sales, 'source_type' => \App\Models\SalesActual::class, 'source_id' => $salesActualId, 'description' => "Sales Actual #{$salesActualId}", 'created_at' => $now, 'updated_at' => $now],
        ['income_date' => $date, 'income_category_id' => $incomeCategory('Adjustment'), 'cash_account_id' => $cashAccountId, 'amount' => 70_000, 'source_type' => null, 'source_id' => null, 'description' => 'Koreksi saldo', 'created_at' => $now, 'updated_at' => $now],
    ]);

    $locationId = DB::table('expense_locations')->insertGetId(['name' => "Lokasi {$month}", 'created_at' => $now, 'updated_at' => $now]);
    $expenseCategory = fn (string $name, string $mode) => DB::table('expense_categories')->where('name', $name)->value('id')
        ?? DB::table('expense_categories')->insertGetId(['name' => $name, 'expense_mode' => $mode, 'include_hpp' => false, 'created_at' => $now, 'updated_at' => $now]);

    DB::table('cash_outs')->insert([
        ['expense_category_id' => $expenseCategory('Gaji', 'direct_expense'), 'expense_location_id' => $locationId, 'cash_account_id' => $cashAccountId, 'amount' => $salary, 'expense_date' => $date, 'created_at' => $now, 'updated_at' => $now],
        ['expense_category_id' => $expenseCategory('Investasi Lain', 'fixed_asset'), 'expense_location_id' => $locationId, 'cash_account_id' => $cashAccountId, 'amount' => 300_000, 'expense_date' => $date, 'created_at' => $now, 'updated_at' => $now],
    ]);
}

it('counts shipping income in profit and loss so it matches the balance sheet current profit', function () {
    seedReportMonth('2026-09', rawCostPerUnit: 10_000);
    $accounting = reportUser('accounting');
    $range = ['date_from' => '2026-09-01', 'date_to' => '2026-09-30'];

    // 1.000.000 penjualan + 50.000 ongkir - 200.000 gaji (aktiva tetap, adjustment & kembaran closing tidak ikut)
    $expectedProfit = 850_000.0;

    $profitLoss = $this->actingAs($accounting)
        ->get(route('accountingapp.reports.profit-loss', $range))
        ->assertOk()
        ->assertSee('Penjualan Lain-Lain')
        ->viewData('profitLoss');

    expect($profitLoss['totalPenjualan'])->toEqual(1_000_000.0)
        ->and($profitLoss['pendapatanLain'])->toEqual(50_000.0)
        ->and($profitLoss['totalPengeluaran'])->toEqual(200_000.0)
        ->and($profitLoss['labaRugi'])->toEqual($expectedProfit)
        // T-akun tetap balance: kiri (penjualan + pendapatan lain) = kanan (BB + pengeluaran + laba)
        ->and($profitLoss['totalPendapatan'])->toEqual($profitLoss['total']);

    $final = $this->actingAs($accounting)
        ->get(route('accountingapp.reports.final', $range))
        ->assertOk();

    $currentProfit = collect($final->viewData('balanceSheet')['wealthRows'])->firstWhere('label', 'Laba Berjalan')['amount'];

    expect($final->viewData('profitLoss')['labaRugi'])->toEqual($expectedProfit)
        ->and((float) $currentProfit)->toEqual($expectedProfit);
});

it('uses profit-and-loss expenses as real overhead and caps the hitungan gap at 4 percent', function () {
    // Juli: Hitungan 90% vs Real 85% -> selisih 5% -> dipotong ke 4%
    // (bukan September: bulan itu punya target manual, lihat test di bawah)
    seedReportMonth('2026-07', rawCostPerUnit: 10_000);
    // Agustus (kontrol): Hitungan 87% vs Real 85% -> selisih 2% -> dibiarkan apa adanya
    seedReportMonth('2026-08', rawCostPerUnit: 13_000);
    $owner = reportUser('owner');

    $response = $this->actingAs($owner)
        ->get(route('ownerapp.hpp-analysis', ['date_from' => '2026-07-01', 'date_to' => '2026-08-31']))
        ->assertOk()
        // Owner: angka yang dipotong tampil apa adanya di Profit %, tanpa label penyesuaian
        ->assertSee('89,00%')
        ->assertDontSee('disesuaikan');

    $rows = collect($response->viewData('periodRows'))->keyBy('period');
    $august = $rows['2026-08'];
    $july = $rows['2026-07'];

    // OHC Real hanya Gaji; Investasi Lain (aktiva tetap) tidak ikut
    expect($july['ohc_real'])->toEqual(200_000.0)
        ->and($july['profit_real_percent'])->toEqual(85.0);

    // Dipotong: cost Hitungan naik 100.000 -> 110.000, Profit % H = 85 + 4
    expect($july['is_adjusted'])->toBeTrue()
        ->and($july['original_gap_percent'])->toEqual(5.0)
        ->and($july['hpp_jual'])->toEqual(110_000.0)
        ->and($july['profit_percent'])->toEqual(89.0);

    // Kontrol: dalam toleransi -> snapshot asli, tanpa tanda
    expect($august['is_adjusted'])->toBeFalse()
        ->and($august['original_gap_percent'])->toEqual(2.0)
        ->and($august['hpp_jual'])->toEqual(130_000.0)
        ->and($august['profit_percent'])->toEqual(87.0);
});

it('shows the profit-and-loss sales figure on the owner dashboard instead of the completed purchase order total', function () {
    seedReportMonth('2026-09', rawCostPerUnit: 10_000);
    $owner = reportUser('owner');

    // PO selesai senilai 1.075.000 (termasuk ongkir 75.000): dulu dipakai dashboard, sekarang tidak
    $customer = DB::table('customers')->first();
    DB::table('purchase_orders')->insert([
        'po_number' => 'PO-TEST-0001',
        'customer_id' => $customer->id,
        'area_id' => $customer->area_id,
        'recipient_name' => 'Penerima',
        'shipping_address' => 'Alamat',
        'delivery_date' => '2026-09-15',
        'delivery_time' => '10:00:00',
        'created_by' => $owner->id,
        'status' => 'completed',
        'completed_at' => '2026-09-15 12:00:00',
        'payment_type' => 'cash',
        'shipping_cost' => 75_000,
        'total_amount' => 1_075_000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($owner)
        ->get(route('ownerapp.dashboard', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30']))
        ->assertOk();

    $salesSeries = collect($response->viewData('salesVsExpenseChart')['series'])->firstWhere('name', 'Penjualan');

    // = baris PENJUALAN Laba Rugi (sales actual), bukan total PO; pendapatan lain (ongkir) terpisah
    expect($response->viewData('totalSales'))->toEqual(1_000_000.0)
        ->and($response->viewData('totalShipping'))->toEqual(50_000.0)
        ->and((float) array_sum($salesSeries['values']))->toEqual(1_000_000.0)
        // Kontrol: jumlah transaksi tetap menghitung PO selesai
        ->and($response->viewData('transactionCount'))->toBe(1);
});

it('recognises receivable purchase order shipping when it is paid so wealth rolls forward by profit', function () {
    $accounting = reportUser('accounting');
    $now = now();

    $areaId = DB::table('areas')->insertGetId(['name' => 'Area Ongkir', 'code' => 'AO', 'created_at' => $now, 'updated_at' => $now]);
    $customerId = DB::table('customers')->insertGetId(['area_id' => $areaId, 'name' => 'Customer Piutang', 'created_at' => $now, 'updated_at' => $now]);
    $cashAccountId = DB::table('cash_accounts')->insertGetId(['name' => 'Bank', 'type' => 'bank', 'created_at' => $now, 'updated_at' => $now]);
    $shippingCategoryId = DB::table('income_categories')->insertGetId(['name' => 'Penjualan Lain-Lain', 'created_at' => $now, 'updated_at' => $now]);

    // PO piutang Rp 530.000 (barang 500.000 + ongkir 30.000), selesai Agustus, dilunasi September
    $poId = DB::table('purchase_orders')->insertGetId([
        'po_number' => 'PO-ONGKIR-1', 'customer_id' => $customerId, 'area_id' => $areaId,
        'recipient_name' => 'Penerima', 'shipping_address' => 'Alamat',
        'delivery_date' => '2026-08-20', 'delivery_time' => '10:00:00', 'created_by' => $accounting->id,
        'status' => 'completed', 'completed_at' => '2026-08-20 12:00:00',
        'payment_type' => 'receivable', 'shipping_cost' => 30_000, 'total_amount' => 530_000,
        'cash_received_at' => '2026-09-05 09:00:00', 'cash_account_id' => $cashAccountId,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    // Pelunasan membuat "Ongkir Pelunasan PO" (seperti syncShippingIncomeForReceivablePo)
    DB::table('other_incomes')->insert([
        'income_date' => '2026-09-05', 'income_category_id' => $shippingCategoryId, 'cash_account_id' => $cashAccountId,
        'amount' => 30_000, 'source_type' => \App\Models\PurchaseOrder::class, 'source_id' => $poId,
        'description' => 'Ongkir Pelunasan PO PO-ONGKIR-1', 'created_at' => $now, 'updated_at' => $now,
    ]);

    $report = fn (string $from, string $to) => $this->actingAs($accounting)
        ->get(route('accountingapp.reports.final', ['date_from' => $from, 'date_to' => $to]))
        ->assertOk();

    $august = $report('2026-08-01', '2026-08-31');
    $september = $report('2026-09-01', '2026-09-30');

    // Ongkir diakui saat dibayar (September), bukan saat PO selesai (Agustus)
    expect($august->viewData('profitLoss')['pendapatanLain'])->toEqual(0.0)
        ->and($september->viewData('profitLoss')['pendapatanLain'])->toEqual(30_000.0);

    // Piutang Agustus hanya porsi barang; ongkir belum dibayar bukan aset
    $receivableAugust = collect($august->viewData('balanceSheet')['assetGroups'])->firstWhere('title', 'Piutang Usaha')['total'];
    expect((float) $receivableAugust)->toEqual(500_000.0);

    // Kekayaan September = Kekayaan Agustus + Laba September
    $wealthAugust = (float) $august->viewData('balanceSheet')['wealthAmount'];
    $wealthSeptember = (float) $september->viewData('balanceSheet')['wealthAmount'];

    expect($wealthSeptember)->toEqual($wealthAugust + (float) $september->viewData('profitLoss')['labaRugi']);

    // Kontrol: Laba Rugi = Laba Berjalan neraca di kedua bulan
    foreach ([$august, $september] as $response) {
        $currentProfit = collect($response->viewData('balanceSheet')['wealthRows'])->firstWhere('label', 'Laba Berjalan')['amount'];
        expect((float) $currentProfit)->toEqual((float) $response->viewData('profitLoss')['labaRugi']);
    }
});

it('uses fixed september hitungan costs so profit percent follows them, without the 4 percent cap', function () {
    // September: penjualan 200 jt, gaji 190 jt -> Real ~5%
    seedReportMonth('2026-09', rawCostPerUnit: 1_000_000, sales: 200_000_000, salary: 190_000_000);
    // Agustus (kontrol, tanpa override): tetap snapshot + aturan 4%
    seedReportMonth('2026-08', rawCostPerUnit: 13_000);
    $owner = reportUser('owner');

    $rows = collect($this->actingAs($owner)
        ->get(route('ownerapp.hpp-analysis', ['date_from' => '2026-08-01', 'date_to' => '2026-09-30']))
        ->assertOk()
        ->viewData('periodRows'))->keyBy('period');

    $september = $rows['2026-09'];

    // BB & OHC Hitungan tetap; Profit = 200 jt - 107.433.030 - 45.010.660 = 47.556.310 (23,78%)
    expect($september['hpp_jual'])->toEqual(107_433_030.0)
        ->and($september['ohc_jual'])->toEqual(45_010_660.0)
        ->and($september['profit_jual'])->toEqual(47_556_310.0)
        // Selisih H-R ~19 poin, tapi tidak dipotong ke Real + 4%
        ->and($september['profit_percent'])->toEqual(23.78)
        // Real tidak ikut diubah: OHC Real = gaji, Profit Real dari Laba Rugi
        ->and($september['ohc_real'])->toEqual(190_000_000.0)
        ->and($september['profit_real'])->toEqual(10_050_000.0);

    // Kontrol: bulan tanpa override tetap snapshot asli
    expect($rows['2026-08']['hpp_jual'])->toEqual(130_000.0)
        ->and($rows['2026-08']['profit_percent'])->toEqual(87.0);
});

it('keeps wealth-reducing expenses such as marketing out of profit and loss but takes them off wealth', function () {
    seedReportMonth('2026-08', rawCostPerUnit: 10_000);
    seedReportMonth('2026-09', rawCostPerUnit: 10_000);
    // Pemasukan "Adjustment" dari seed sengaja jatuh ke Penyesuaian Neraca; dibuang supaya neraca bersih
    DB::table('other_incomes')->where('description', 'Koreksi saldo')->delete();
    $accounting = reportUser('accounting');

    // Kategori dibuat lewat master kategori dengan mode baru
    $this->actingAs($accounting)
        ->post(route('accountingapp.categories.store'), [
            'name' => 'Biaya Marketing',
            'expense_mode' => 'wealth_reduction',
            'is_active' => '1',
        ])
        ->assertSessionHasNoErrors();

    $marketingId = DB::table('expense_categories')->where('name', 'Biaya Marketing')->value('id');
    expect(DB::table('expense_categories')->where('id', $marketingId)->value('expense_mode'))->toBe('wealth_reduction');

    DB::table('cash_outs')->insert([
        'expense_category_id' => $marketingId,
        'expense_location_id' => DB::table('expense_locations')->value('id'),
        'cash_account_id' => DB::table('cash_accounts')->value('id'),
        'amount' => 96_810,
        'expense_date' => '2026-09-20',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $report = fn (string $from, string $to) => $this->actingAs($accounting)
        ->get(route('accountingapp.reports.final', ['date_from' => $from, 'date_to' => $to]))
        ->assertOk();

    $august = $report('2026-08-01', '2026-08-31');
    $september = $report('2026-09-01', '2026-09-30');
    $profitLoss = $september->viewData('profitLoss');
    $wealthRows = collect($september->viewData('balanceSheet')['wealthRows']);

    // Laba Rugi: biaya marketing tidak ikut (laba sama seperti tanpa transaksi itu)
    expect($profitLoss['totalPengeluaran'])->toEqual(200_000.0)
        ->and($profitLoss['labaRugi'])->toEqual(850_000.0)
        ->and(collect($profitLoss['pengeluaranRows'])->pluck('label'))->not->toContain('Biaya Marketing');

    // Neraca: baris pengurang sendiri, bukan "Penyesuaian Neraca"
    expect($wealthRows->firstWhere('label', 'Biaya Marketing')['amount'])->toEqual(-96_810.0)
        ->and($wealthRows->firstWhere('label', 'Penyesuaian Neraca'))->toBeNull();

    // Kekayaan September = Agustus + Laba September - Biaya Marketing
    $wealthAugust = (float) $august->viewData('balanceSheet')['wealthAmount'];
    $wealthSeptember = (float) $september->viewData('balanceSheet')['wealthAmount'];

    expect($wealthSeptember)->toEqual(round($wealthAugust + 850_000 - 96_810, 2))
        // Kontrol: Agustus tidak tersentuh, Laba Berjalan = Laba Rugi
        ->and(collect($august->viewData('balanceSheet')['wealthRows'])->firstWhere('label', 'Biaya Marketing'))->toBeNull()
        ->and((float) $wealthRows->firstWhere('label', 'Laba Berjalan')['amount'])->toEqual(850_000.0);

    // Mode yang tidak dikenal tetap ditolak
    $this->actingAs($accounting)
        ->post(route('accountingapp.categories.store'), ['name' => 'Ngawur', 'expense_mode' => 'bebas'])
        ->assertSessionHasErrors('expense_mode');
});
