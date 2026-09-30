<?php

use App\Filament\Pages\IngredientStockCount;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\IngredientStockCountService;
use App\Services\InventoryLedgerService;
use App\Services\InventoryShrinkageService;
use App\Services\ProductionCompletionService;
use App\Services\ProductionOrderService;
use App\Services\RequisitionService;
use App\Support\Settings\Settings;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Susut bahan, Barang Hilang, dan Barang Temuan (revisi Owner): selisih hitung
 * fisik terhadap kartu stok dilacak per bahan dengan indikator warna, dan
 * dilaporkan sebagai rincian HPP di Laba Rugi tanpa mengubah Laba.
 */

/**
 * SPK 15 Sep 2026 (80 porsi Gorengan): kebutuhan tepung 2 kg, minyak 1,2 l.
 * Stok awal tepung 3 kg & minyak 1,2 l (tanpa beli). Setelah produksi dapur
 * menghitung sisa tepung 0,7 kg -- padahal kartu stok 1 kg -> hilang 0,3 kg
 * (Rp 3.600). Minyak habis sesuai resep.
 *
 * @return array<string, mixed>
 */
function spkDenganBarangHilang(bool $tutup = true): array
{
    $d = siapkanProduksi();
    $order = app(ProductionOrderService::class)->generateFromSpk($d['spk']);
    $service = app(RequisitionService::class);
    $completion = app(ProductionCompletionService::class);

    $requisition = $service->build($order)['requisition'];
    $tepung = $requisition->lines->firstWhere('inventory_item_id', $d['tepung']->id);
    $minyak = $requisition->lines->firstWhere('inventory_item_id', $d['minyak']->id);
    $service->fillOpeningStock($tepung, 3);
    $service->fillOpeningStock($minyak, 1.2);
    $service->submit($requisition->fresh());
    $service->approve($requisition->fresh());
    bayarTunai($requisition->fresh());
    $service->check($requisition->fresh());

    $completion->recordActuals($tepung->fresh(), null, 0.7);
    $completion->recordActuals($minyak->fresh(), null, 0);

    if ($tutup) {
        $completion->complete($order->fresh());
    }

    return $d + ['order' => $order];
}

it('mewarnai susut per bahan sesuai batas pengaturan', function () {
    $d = spkDenganBarangHilang();
    $service = app(InventoryShrinkageService::class);
    $rows = $service->report(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))->keyBy('inventory_item_id');

    $tepung = $rows[$d['tepung']->id];
    $minyak = $rows[$d['minyak']->id];

    expect($tepung['recipe_qty'])->toBe(2.0)
        ->and($tepung['usage_qty'])->toBe(2.0)
        ->and($tepung['loss_qty'])->toBe(0.3)
        ->and($tepung['loss_value'])->toBe(3600.0)
        ->and($tepung['shrink_pct'])->toBe(15.0)
        ->and($tepung['level'])->toBe(InventoryShrinkageService::LEVEL_RED)
        // Kontrol positif: bahan yang habis sesuai resep tetap hijau.
        ->and($minyak['shrink_pct'])->toBe(0.0)
        ->and($minyak['level'])->toBe(InventoryShrinkageService::LEVEL_GREEN)
        // Nol tanpa hilang tidak boleh tampil "-0" (nol negatif hasil round(-0)).
        ->and((string) $minyak['loss_qty'])->toBe('0')
        // Yang merah diurutkan paling atas.
        ->and($rows->keys()->first())->toBe($d['tepung']->id);

    // Batas kuning dinaikkan ke 20% -> tepung jadi kuning.
    app(Settings::class)->set('shrinkage.yellow_max_pct', 20);
    $tepung = $service->report(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))->firstWhere('inventory_item_id', $d['tepung']->id);

    expect($tepung['level'])->toBe(InventoryShrinkageService::LEVEL_YELLOW);
});

it('melacak susut bahan sampai ke SPK dan menu yang memakainya', function () {
    $d = spkDenganBarangHilang();

    $detail = app(InventoryShrinkageService::class)->detail($d['tepung'], Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

    expect($detail['orders'])->toHaveCount(1);

    $order = $detail['orders'][0];
    expect($order['number'])->toBe($d['order']->number)
        ->and($order['loss_qty'])->toBe(0.3)
        ->and($order['shrink_qty'])->toBe(0.3)
        ->and($order['menus'])->toHaveCount(1)
        ->and($order['menus'][0]['label'])->toBe('Gorengan')
        ->and($order['menus'][0]['porsi'])->toBe(80.0)
        ->and($order['menus'][0]['share_pct'])->toBe(100.0)
        ->and($order['menus'][0]['shrink_qty'])->toBe(0.3);
});

it('menampilkan Barang Hilang sebagai rincian HPP tanpa mengubah Laba', function () {
    Role::findOrCreate('accounting', 'web');
    $accounting = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $accounting->assignRole('accounting');

    $d = spkDenganBarangHilang(tutup: false);
    $params = ['date_from' => '2026-09-01', 'date_to' => '2026-09-30'];

    $sebelum = $this->actingAs($accounting)->get(route('accountingapp.reports.profit-loss', $params))->assertOk()->viewData('profitLoss');

    app(ProductionCompletionService::class)->complete($d['order']->fresh());

    $response = $this->actingAs($accounting)->get(route('accountingapp.reports.profit-loss', $params))->assertOk();
    $sesudah = $response->viewData('profitLoss');

    expect($sebelum['barangHilang'])->toBe(0.0)
        ->and($sesudah['barangHilang'])->toBe(3600.0)
        ->and($sesudah['barangTemuan'])->toBe(0.0)
        // Rincian saja: HPP dan Laba tidak bergeser.
        ->and($sesudah['bahanBakuTerpakai'])->toBe($sebelum['bahanBakuTerpakai'])
        ->and($sesudah['labaRugi'])->toBe($sebelum['labaRugi']);

    $response->assertSee('termasuk Barang Hilang');

    // Laporan Final memakai rincian yang sama.
    $this->actingAs($accounting)->get(route('accountingapp.reports.final', $params))
        ->assertOk()
        ->assertSee('termasuk Barang Hilang');
});

it('mencatat hasil Opname Bahan sebagai Barang Hilang, Barang Temuan, atau Saldo Awal', function () {
    $d = siapkanProduksi();
    $ledger = app(InventoryLedgerService::class);
    $ledger->post($d['tepung']->id, InventoryMovement::TYPE_OPENING, 2, 'kg', '2026-09-10', 12000);

    $service = app(IngredientStockCountService::class);

    expect(fn () => $service->record(now()->addDay(), [$d['tepung']->id => 1]))->toThrow(InvalidArgumentException::class, 'masa depan')
        ->and(fn () => $service->record(Carbon::parse('2026-09-20'), [$d['tepung']->id => -1]))->toThrow(InvalidArgumentException::class, 'negatif')
        ->and(fn () => $service->record(Carbon::parse('2026-09-20'), [$d['bucket']->id => 1]))->toThrow(InvalidArgumentException::class, 'tidak dikenal')
        ->and(InventoryMovement::query()->count())->toBe(1);

    // Tepung: kartu stok 2, dihitung 1,5 -> hilang 0,5 (Rp 6.000).
    // Minyak: belum pernah tercatat -> hitungan 4 menjadi saldo awal, bukan temuan.
    $summary = $service->record(Carbon::parse('2026-09-20'), [$d['tepung']->id => 1.5, $d['minyak']->id => 4, 999999 => null]);

    expect($summary)->toMatchArray(['hilang' => 1, 'temuan' => 0, 'saldo_awal' => 1, 'sama' => 0, 'hilang_value' => 6000.0])
        ->and($ledger->balance($d['tepung']->id))->toBe(1.5)
        ->and($ledger->balance($d['minyak']->id))->toBe(4.0)
        ->and(InventoryMovement::query()->where('inventory_item_id', $d['minyak']->id)->sole()->type)->toBe(InventoryMovement::TYPE_OPENING);

    // Minyak dihitung lagi 4,5 -> temuan 0,5; tepung sama -> tidak ada gerakan.
    $summary = $service->record(Carbon::parse('2026-09-25'), [$d['tepung']->id => 1.5, $d['minyak']->id => 4.5]);

    expect($summary)->toMatchArray(['hilang' => 0, 'temuan' => 1, 'sama' => 1, 'temuan_value' => 10000.0]);

    $totals = app(InventoryShrinkageService::class)->lossAndFoundTotals(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
    expect($totals)->toBe(['hilang' => 6000.0, 'temuan' => 10000.0]);

    // Kartu stok menamai penyesuaian sesuai tandanya.
    $labels = InventoryMovement::query()->where('type', InventoryMovement::TYPE_ADJUSTMENT)->orderBy('id')->get()->map->typeLabel()->all();
    expect($labels)->toBe(['Barang Hilang', 'Barang Temuan']);
});

it('menyimpan Opname Bahan dari halaman panel untuk pemegang izin kelola inventory', function () {
    $d = siapkanProduksi();
    app(InventoryLedgerService::class)->post($d['tepung']->id, InventoryMovement::TYPE_OPENING, 2, 'kg', now()->subDay()->toDateString(), 12000);

    Role::findOrCreate('inventory', 'web');
    $gudang = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $gudang->assignRole('inventory');
    $this->actingAs($gudang);

    $this->get('/inventory/opname-bahan')->assertOk()->assertSee('Tepung Terigu');

    Livewire::test(IngredientStockCount::class)
        ->set('counts.'.$d['tepung']->id, '1.75')
        ->call('save')
        ->assertHasNoErrors();

    $adjustment = InventoryMovement::query()->where('type', InventoryMovement::TYPE_ADJUSTMENT)->sole();
    expect((float) $adjustment->qty)->toBe(-0.25)
        ->and($adjustment->created_by)->toBe($gudang->id)
        ->and($adjustment->notes)->toStartWith('Opname bahan');
});

it('menutup Opname Bahan bagi peran tanpa izin kelola inventory, tetapi membuka Susut Bahan', function () {
    Role::findOrCreate('production', 'web');
    $produksi = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $produksi->assignRole('production');

    // Produksi punya ledger.view tetapi tidak inventory.manage.
    $this->actingAs($produksi)->get('/inventory/opname-bahan')->assertForbidden();
    $this->actingAs($produksi)->get('/inventory/susut-bahan')->assertOk();
});

it('menutup Susut Bahan bagi peran di luar panel', function () {
    Role::findOrCreate('sales', 'web');
    $sales = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $sales->assignRole('sales');

    $this->actingAs($sales)->get('/inventory/susut-bahan')->assertForbidden();
});
