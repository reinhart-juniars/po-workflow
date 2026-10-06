<?php

use App\Models\Area;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use App\Models\User;
use App\Services\BalanceSheetService;
use App\Services\LeftoverStockService;
use App\Services\SalesActualService;
use App\Support\Settings\Settings;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * Barang Sisa di akuntansi: retur yang belum terjual pada akhir bulan adalah
 * persediaan (dinilai HPP menu). Persediaan akhir mengurangi HPP bulan itu,
 * persediaan awal menambah HPP bulan berikutnya, dan Neraca tetap seimbang.
 */

/**
 * 30 Sep 2026: customer menerima 10 porsi, 7 terjual (Rp 15.000), retur 3
 * (HPP 5.000) -> Barang Sisa Rp 15.000 pada akhir September.
 *
 * @return array{accounting: User, customer: Customer, entry: SalesActualItem}
 */
function barangSisaAkhirBulan(): array
{
    Role::findOrCreate('accounting', 'web');
    $accounting = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $accounting->assignRole('accounting');

    app(Settings::class)->set('leftover.accounting_start', '2026-09-01');

    $area = Area::query()->create(['name' => 'Area Akuntansi Sisa', 'code' => 'AAS']);
    $customer = Customer::query()->create(['name' => 'Customer Sisa', 'area_id' => $area->id]);
    $product = Product::query()->create([
        'name' => 'Nasi Goreng', 'sku' => 'NG', 'unit' => 'porsi', 'base_price' => 15000,
        'raw_material_cost' => 5000, 'active' => true,
    ]);

    $september = SalesActual::query()->create([
        'customer_id' => $customer->id, 'sales_date' => '2026-09-30', 'status' => 'submitted',
        'submitted_at' => '2026-09-30 15:00:00', 'submitted_by' => $accounting->id, 'notes' => 'Retur 3',
    ]);
    $entry = SalesActualItem::query()->create([
        'sales_actual_id' => $september->id, 'product_id' => $product->id, 'item_name' => 'NASI GORENG',
        'unit' => 'porsi', 'qty_delivery' => 10, 'qty_actual' => 7, 'unit_price' => 15000, 'raw_material_cost' => 5000,
    ]);

    return compact('accounting', 'customer', 'entry');
}

afterEach(fn () => Carbon::setTestNow());

it('menilai Barang Sisa akhir bulan sebagai persediaan yang pindah ke HPP bulan terjualnya', function () {
    $f = barangSisaAkhirBulan();
    $leftovers = app(LeftoverStockService::class);

    expect($leftovers->valueAt(Carbon::parse('2026-09-29')))->toBe(0.0)
        ->and($leftovers->valueAt(Carbon::parse('2026-09-30')))->toBe(15000.0);

    // 1 Okt: ketiganya dijual ke customer yang sama @ 12.000.
    Carbon::setTestNow('2026-10-01 10:00:00');
    $this->actingAs($f['accounting']);
    $oktober = SalesActual::query()->create(['customer_id' => $f['customer']->id, 'sales_date' => '2026-10-01', 'status' => 'draft']);
    $leftovers->addToSalesActual($oktober, $f['entry'], 3, 12000);

    // Masih draft: tetap persediaan.
    expect($leftovers->valueAt(Carbon::parse('2026-10-01')))->toBe(15000.0);

    app(SalesActualService::class)->submit($oktober->fresh());

    expect($leftovers->valueAt(Carbon::parse('2026-09-30')))->toBe(15000.0)   // histori tidak bergeser
        ->and($leftovers->valueAt(Carbon::parse('2026-10-01')))->toBe(0.0);

    $sep = $this->get(route('accountingapp.reports.profit-loss', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30']))
        ->assertOk()->assertSee('Barang Sisa Akhir')->viewData('profitLoss');
    $okt = $this->get(route('accountingapp.reports.profit-loss', ['date_from' => '2026-10-01', 'date_to' => '2026-10-31']))
        ->assertOk()->assertSee('Barang Sisa Awal')->viewData('profitLoss');

    expect($sep['barangSisaAwal'])->toBe(0.0)
        ->and($sep['barangSisaAkhir'])->toBe(15000.0)
        ->and($sep['bahanBakuTerpakai'])->toBe(-15000.0)     // tanpa bahan baku lain di tes ini
        ->and($sep['labaRugi'])->toBe(105000.0 + 15000.0)    // 7 x 15.000, HPP dikurangi persediaan
        ->and($okt['barangSisaAwal'])->toBe(15000.0)
        ->and($okt['barangSisaAkhir'])->toBe(0.0)
        ->and($okt['bahanBakuTerpakai'])->toBe(15000.0)
        ->and($okt['labaRugi'])->toBe(36000.0 - 15000.0);    // 3 x 12.000 - HPP Barang Sisa

    // Kontrol: total dua bulan = total penjualan (tidak ada nilai yang hilang atau dobel).
    expect($sep['labaRugi'] + $okt['labaRugi'])->toBe(141000.0);
});

it('memasukkan Barang Sisa ke Persediaan Neraca dan Laba dengan nilai yang sama, tanpa menggeser Penyesuaian Neraca', function () {
    barangSisaAkhirBulan();
    $service = app(BalanceSheetService::class);
    $reportDate = Carbon::parse('2026-09-30');

    $penyesuaian = fn (array $report) => (float) ($report['wealthRows']->firstWhere('label', 'Penyesuaian Neraca')['amount'] ?? 0);
    $persediaan = fn (array $report) => (float) $report['assetGroups']->firstWhere('title', 'Persediaan')['total'];
    $laba = fn (array $report) => (float) $report['wealthRows']->whereIn('label', ['Laba Ditahan', 'Laba Berjalan'])->sum('amount');

    $dengan = $service->buildReport($reportDate);

    // Kontrol: tanggal mulai digeser setelah retur -> Barang Sisa tidak dihitung.
    app(Settings::class)->set('leftover.accounting_start', '2026-10-01');
    $tanpa = $service->buildReport($reportDate);

    expect($dengan['assetGroups']->firstWhere('title', 'Persediaan')['rows']->pluck('label'))->toContain('Barang Sisa - Persediaan Akhir')
        ->and($persediaan($dengan) - $persediaan($tanpa))->toBe(15000.0)
        ->and(round($laba($dengan) - $laba($tanpa), 2))->toBe(15000.0)
        ->and($penyesuaian($dengan))->toBe($penyesuaian($tanpa))
        ->and($dengan['totalAssets'])->toBe($dengan['totalLiabilitiesAndEquity']);
});

it('melaporkan Barang Sisa yang dibuang sebagai rincian HPP di bulan dibuangnya', function () {
    $f = barangSisaAkhirBulan();
    $this->actingAs($f['accounting']);

    Carbon::setTestNow('2026-10-02 09:00:00');
    app(LeftoverStockService::class)->dispose($f['entry'], 1, Carbon::parse('2026-10-02'), 'Basi');

    $okt = $this->get(route('accountingapp.reports.profit-loss', ['date_from' => '2026-10-01', 'date_to' => '2026-10-31']))
        ->assertOk()->assertSee('termasuk Barang Sisa Dibuang')->viewData('profitLoss');

    expect($okt['barangSisaDibuang'])->toBe(5000.0)
        ->and($okt['barangSisaAwal'])->toBe(15000.0)
        ->and($okt['barangSisaAkhir'])->toBe(10000.0)
        // Rincian tidak dijumlah dua kali: HPP = awal - akhir saja.
        ->and($okt['bahanBakuTerpakai'])->toBe(5000.0);
});
