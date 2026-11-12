<?php

use App\Models\Area;
use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use App\Models\User;
use App\Services\LeftoverStockService;
use App\Services\SalesActualService;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

/**
 * Akar kasus Rice Bowl Dori (12 Nov 2026): Penjualan Barang Sisa masuk draft V2
 * customer pembeli (April-Mei) karena draft terbaru dipakai berapa pun
 * tanggalnya. Sekarang hanya draft hari ini yang dipakai, Barang Sisa tidak
 * bisa dijual lewat Sales Actual bertanggal sebelum sisanya ada, dan draft V2
 * bisa dinonaktifkan.
 */
function draftBasiFixture(): array
{
    test()->travelTo(Carbon\Carbon::parse('2026-11-12 10:30'));
    Role::findOrCreate('sales', 'web');

    $sales = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $sales->assignRole('sales');
    $area = Area::query()->create(['name' => 'Area Lapak', 'code' => 'LPK']);
    $dapur = Customer::query()->create(['name' => 'Stok Siap Dapur', 'area_id' => $area->id, 'is_internal' => true]);
    $aldo = Customer::query()->create(['name' => 'ALDO LAPAK', 'area_id' => $area->id, 'is_lapak' => true]);
    $kas = CashAccount::query()->create(['name' => 'Kas Lapak', 'type' => 'cash', 'is_active' => true]);
    $dori = Product::query()->create([
        'name' => 'Rice Bowl Dori Cabe Garam', 'sku' => 'DORI', 'unit' => 'porsi', 'base_price' => 17500,
        'raw_material_cost' => 8000, 'overhead_cost' => 2000, 'active' => true,
    ]);

    $asal = SalesActual::query()->create([
        'customer_id' => $dapur->id, 'sales_date' => '2026-11-11', 'status' => 'submitted',
        'is_internal' => true, 'submitted_at' => '2026-11-11 20:00', 'submitted_by' => $sales->id,
    ]);
    $entry = SalesActualItem::query()->create([
        'sales_actual_id' => $asal->id, 'product_id' => $dori->id, 'item_name' => 'Rice Bowl Dori Cabe Garam',
        'unit' => 'porsi', 'qty_delivery' => 7, 'qty_actual' => 0, 'unit_price' => 17500,
        'raw_material_cost' => 8000, 'overhead_cost' => 2000,
    ]);

    // Draft V2 ALDO yang tidak pernah disubmit, berisi menu lama.
    $draftV2 = SalesActual::query()->create(['customer_id' => $aldo->id, 'sales_date' => '2026-04-30', 'status' => 'draft']);
    SalesActualItem::query()->create([
        'sales_actual_id' => $draftV2->id, 'item_name' => 'NASI CAMPUR TELUR 10K', 'unit' => 'porsi',
        'qty_delivery' => 12, 'qty_actual' => 12, 'unit_price' => 10000, 'raw_material_cost' => 4000, 'overhead_cost' => 1000,
    ]);

    return compact('sales', 'aldo', 'kas', 'entry', 'draftV2', 'dapur');
}

it('menjual Barang Sisa ke draft hari ini, bukan ke draft V2 customer itu', function () {
    $f = draftBasiFixture();
    actingAs($f['sales']);

    $jual = fn () => $this->post(route('salesapp.leftovers.sell', $f['entry']), [
        'customer_name' => 'ALDO LAPAK', 'payment' => 'cash:'.$f['kas']->id, 'qty' => 2, 'unit_price' => 17500,
    ])->assertSessionHasNoErrors();

    $jual();

    $sale = SalesActualItem::query()->where('source_sales_actual_item_id', $f['entry']->id)->sole();
    $draftHariIni = $sale->salesActual;

    expect($draftHariIni->id)->not->toBe($f['draftV2']->id)
        ->and($draftHariIni->sales_date->toDateString())->toBe('2026-11-12')
        ->and($draftHariIni->status)->toBe('draft')
        ->and(SalesActualItem::query()->where('sales_actual_id', $f['draftV2']->id)->count())->toBe(1);

    // Penjualan berikutnya hari itu masuk draft hari ini yang sama.
    $jual();

    expect(SalesActualItem::query()->where('source_sales_actual_item_id', $f['entry']->id)->pluck('sales_actual_id')->unique()->all())
        ->toBe([$draftHariIni->id])
        ->and(SalesActual::query()->where('customer_id', $f['aldo']->id)->count())->toBe(2);
});

it('menolak menjual Barang Sisa lewat Sales Actual bertanggal sebelum sisanya ada', function () {
    $f = draftBasiFixture();
    actingAs($f['sales']);

    // Lewat halaman edit draft V2: ditolak, tanpa baris baru.
    $this->post(route('salesapp.actuals.leftovers.store', $f['draftV2']), [
        'leftover_source' => 'entry:'.$f['entry']->id, 'leftover_qty' => 1, 'leftover_price' => 17500,
        'payment' => 'cash:'.$f['kas']->id,
    ])->assertSessionHasErrors(['leftover' => 'Barang Sisa tanggal 11/11/2026 tidak bisa dijual lewat Sales Actual tanggal 30/04/2026. Jual lewat Sales Actual hari ini.']);

    expect(SalesActualItem::query()->where('source_sales_actual_item_id', $f['entry']->id)->exists())->toBeFalse();

    // Kontrol positif: draft bertanggal sama dengan sisanya (11 Nov) boleh.
    $kemarin = SalesActual::query()->create(['customer_id' => $f['aldo']->id, 'sales_date' => '2026-11-11', 'status' => 'draft']);
    $sale = app(LeftoverStockService::class)->addToSalesActual($kemarin, $f['entry'], 1, 17500, $f['kas']->id);

    expect($sale->sales_actual_id)->toBe($kemarin->id);
});

it('menonaktifkan draft V2 tanpa menyentuh draft November, Sales Actual tersubmit, atau draft berisi Barang Sisa V3', function () {
    $f = draftBasiFixture();

    $internalV2 = SalesActual::query()->create(['customer_id' => $f['dapur']->id, 'sales_date' => '2026-10-31', 'status' => 'draft', 'is_internal' => true]);
    $november = SalesActual::query()->create(['customer_id' => $f['aldo']->id, 'sales_date' => '2026-11-01', 'status' => 'draft']);
    $submittedV2 = SalesActual::query()->create(['customer_id' => $f['aldo']->id, 'sales_date' => '2026-05-01', 'status' => 'submitted', 'submitted_at' => '2026-05-01 20:00']);

    // Draft V2 yang terlanjur menampung Barang Sisa November (sebelum patch ini).
    $tertahan = SalesActual::query()->create(['customer_id' => $f['aldo']->id, 'sales_date' => '2026-05-10', 'status' => 'draft']);
    $salahTempat = SalesActualItem::query()->create([
        'sales_actual_id' => $tertahan->id, 'item_name' => 'Rice Bowl Dori Cabe Garam', 'unit' => 'porsi',
        'qty_delivery' => 1, 'qty_actual' => 1, 'unit_price' => 17500, 'raw_material_cost' => 8000, 'overhead_cost' => 2000,
        'is_carry_forward' => true, 'source_sales_actual_item_id' => $f['entry']->id, 'cash_account_id' => $f['kas']->id,
    ]);

    // Uji-jalan tidak mengubah apa pun.
    $this->artisan('sales-actual:nonaktifkan-draft-v2')
        ->expectsOutputToContain('Uji-jalan: 2 draft')
        ->expectsOutputToContain('DILEWATI')
        ->assertSuccessful();
    expect($f['draftV2']->fresh()->status)->toBe('draft');

    // Gagal (exit 1) karena ada yang dilewati, tetapi yang aman tetap dinonaktifkan.
    $this->artisan('sales-actual:nonaktifkan-draft-v2', ['--execute' => true])->assertFailed();

    expect($f['draftV2']->fresh()->status)->toBe('archived')
        ->and($internalV2->fresh()->status)->toBe('archived')
        ->and($november->fresh()->status)->toBe('draft')
        ->and($submittedV2->fresh()->status)->toBe('submitted')
        ->and($tertahan->fresh()->status)->toBe('draft')
        ->and(AuditLog::query()->where('action', 'sales_actual_archived')->pluck('entity_id')->sort()->values()->all())
        ->toBe(collect([$f['draftV2']->id, $internalV2->id])->sort()->values()->all());

    // Setelah dipindah, sisa draft V2 ikut nonaktif.
    $this->artisan('sales-actual:pindah-barang-sisa', ['items' => [$salahTempat->id], '--execute' => true])->assertSuccessful();
    $this->artisan('sales-actual:nonaktifkan-draft-v2', ['--execute' => true])->assertSuccessful();

    expect($tertahan->fresh()->status)->toBe('archived')
        ->and($salahTempat->fresh()->salesActual->sales_date->toDateString())->toBe('2026-11-12');
});

it('mengunci draft V2 yang nonaktif: tidak bisa disubmit, menampung Barang Sisa, atau masuk daftar draft', function () {
    $f = draftBasiFixture();
    actingAs($f['sales']);
    $this->artisan('sales-actual:nonaktifkan-draft-v2', ['--execute' => true])->assertSuccessful();
    $arsip = $f['draftV2']->fresh();

    expect(fn () => app(SalesActualService::class)->submit($arsip))->toThrow(ValidationException::class)
        ->and(fn () => app(LeftoverStockService::class)->addToSalesActual($arsip, $f['entry'], 1, 17500, $f['kas']->id))->toThrow(ValidationException::class)
        ->and($arsip->fresh()->status)->toBe('archived');

    $this->get(route('salesapp.dashboard', ['status' => 'draft', 'date_from' => '2026-04-01', 'date_to' => '2026-11-30']))
        ->assertOk()
        ->assertDontSee('ALDO LAPAK');

    // Kontrol positif: tetap bisa dilihat lewat filter Nonaktif, berlabel Nonaktif.
    $this->get(route('salesapp.dashboard', ['status' => 'archived', 'date_from' => '2026-04-01', 'date_to' => '2026-11-30']))
        ->assertOk()
        ->assertSee('ALDO LAPAK')
        ->assertSee('Nonaktif');
    $this->get(route('salesapp.actuals.edit', $arsip))
        ->assertOk()
        ->assertSee('Draft V2 yang tidak pernah disubmit');
});
