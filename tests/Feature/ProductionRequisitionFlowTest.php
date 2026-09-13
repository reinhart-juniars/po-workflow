<?php

use App\Models\Area;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Requisition;
use App\Models\Spk;
use App\Models\User;
use App\Services\InventoryLedgerService;
use App\Services\InventoryUsageService;
use App\Services\ProductionCompletionService;
use App\Services\ProductionOrderService;
use App\Services\ProductionUsageService;
use App\Services\RequisitionService;

/**
 * Alur Phase 3 dari ujung ke ujung: PO -> SPK Produksi -> Form Kebutuhan ->
 * persetujuan bertingkat -> ledger -> pemakaian bahan untuk HPP.
 *
 * Yang dijaga: tiap langkah menulis ke ledger tepat sekali dan hanya pada
 * urutan yang benar. Ledger yang terisi dua kali, atau terisi dari form yang
 * belum diperiksa, menghasilkan HPP yang salah tanpa satu pun peringatan --
 * dan angka itu yang akan menggantikan residual opname di Laba Rugi.
 */
function siapkanProduksi(): array
{
    $bucket = InventoryItem::query()->create([
        'name' => 'Bahan Baku', 'unit' => 'All',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true,
    ]);

    $tepung = InventoryItem::query()->create([
        'parent_id' => $bucket->id, 'name' => 'Tepung Terigu', 'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'unit_price' => 12000, 'is_active' => true,
    ]);

    $minyak = InventoryItem::query()->create([
        'parent_id' => $bucket->id, 'name' => 'Minyak Goreng', 'unit' => 'liter',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'unit_price' => 20000, 'is_active' => true,
    ]);

    $product = Product::query()->create(['name' => 'Gorengan 10K', 'unit' => 'porsi', 'base_price' => 10000, 'active' => true]);
    $tanpaResep = Product::query()->create(['name' => 'Es Teh', 'unit' => 'cup', 'base_price' => 5000, 'active' => true]);

    // Resep untuk 10 porsi: 250 gr tepung + 150 ml minyak.
    $recipe = Recipe::query()->create([
        'name' => 'Gorengan', 'jenis' => Recipe::JENIS_UTAMA, 'product_id' => $product->id,
        'yield_qty' => 10, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25,
    ]);
    RecipeItem::query()->create(['recipe_id' => $recipe->id, 'inventory_item_id' => $tepung->id, 'raw_name' => 'tepung', 'qty' => 250, 'unit' => 'gr']);
    RecipeItem::query()->create(['recipe_id' => $recipe->id, 'inventory_item_id' => $minyak->id, 'raw_name' => 'minyak', 'qty' => 150, 'unit' => 'ml']);

    $area = Area::query()->create(['name' => 'Area Produksi', 'code' => 'APR']);
    $customer = Customer::query()->create(['name' => 'Pelanggan Produksi', 'area_id' => $area->id, 'is_lapak' => false]);

    $pj = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $spk = Spk::query()->create([
        'scheduled_at' => '2026-09-15 07:00:00', 'slot_type' => 'fixed_07',
        'status' => 'draft', 'responsible_user_id' => $pj->id,
    ]);

    $pos = [];

    foreach ([30, 50] as $qty) {
        $po = PurchaseOrder::query()->create([
            'customer_id' => $customer->id, 'recipient_name' => 'Pelanggan', 'shipping_address' => 'Jl.',
            'area_id' => $area->id, 'delivery_date' => '2026-09-15', 'delivery_time' => '09:00:00',
            'payment_type' => 'cash', 'status' => 'pending',
            'created_by' => $pj->id, 'updated_by' => $pj->id,
        ]);
        PurchaseOrderItem::query()->create(['purchase_order_id' => $po->id, 'product_id' => $product->id, 'qty' => $qty, 'unit' => 'porsi']);
        $spk->purchaseOrders()->attach($po->id);
        $pos[] = $po;
    }

    // Item yang produknya belum punya resep.
    PurchaseOrderItem::query()->create(['purchase_order_id' => $pos[0]->id, 'product_id' => $tanpaResep->id, 'qty' => 20, 'unit' => 'cup']);

    return compact('bucket', 'tepung', 'minyak', 'recipe', 'spk', 'pos', 'product', 'tanpaResep');
}

it('menyusun spk produksi dari po lewat produk ke resep', function () {
    $d = siapkanProduksi();

    $order = app(ProductionOrderService::class)->generateFromSpk($d['spk']);

    expect($order->production_date->toDateString())->toBe('2026-09-15')
        ->and($order->lines)->toHaveCount(3);

    $menu = $order->lines->where('kind', ProductionOrderLine::KIND_MENU);
    $manual = $order->lines->where('kind', ProductionOrderLine::KIND_MANUAL)->first();

    // Dua item PO produk yang sama tetap dua baris (jejak ke item PO), total 80 porsi.
    expect($menu)->toHaveCount(2)
        ->and((float) $menu->sum('qty'))->toBe(80.0)
        ->and($menu->pluck('recipe_id')->unique()->all())->toBe([$d['recipe']->id])
        // Produk tanpa resep tetap terlihat dapur, ditandai, dan tidak dihitung.
        ->and($manual->label)->toBe('ES TEH')
        ->and($manual->remark)->toContain('belum ada resep');
});

it('menyegarkan baris dari po tanpa menggandakan dan tanpa menghapus baris manual', function () {
    $d = siapkanProduksi();
    $service = app(ProductionOrderService::class);

    $order = $service->generateFromSpk($d['spk']);
    $order->lines()->create(['kind' => ProductionOrderLine::KIND_MANUAL, 'label' => 'siapkan es batu', 'qty' => 2, 'unit' => 'pack', 'sort_order' => 99]);

    // PO berubah: qty naik, satu item dihapus.
    PurchaseOrderItem::query()->where('product_id', $d['product']->id)->where('qty', 30)->update(['qty' => 40]);
    PurchaseOrderItem::query()->where('product_id', $d['tanpaResep']->id)->delete();

    $again = $service->generateFromSpk($d['spk']);

    expect($again->id)->toBe($order->id)
        ->and($again->lines)->toHaveCount(3)
        ->and((float) $again->lines->where('kind', ProductionOrderLine::KIND_MENU)->sum('qty'))->toBe(90.0)
        ->and($again->lines->where('label', 'siapkan es batu'))->toHaveCount(1)
        ->and($again->lines->where('label', 'ES TEH'))->toHaveCount(0);
});

it('menjumlahkan kebutuhan bahan seluruh spk dalam satuan harga bahan', function () {
    $d = siapkanProduksi();
    $order = app(ProductionOrderService::class)->generateFromSpk($d['spk']);

    $req = app(ProductionOrderService::class)->requirements($order);
    $rows = $req['rows']->keyBy('inventory_item_id');

    // 80 porsi = 8x resep: 2.000 gr = 2 kg tepung, 1.200 ml = 1,2 liter minyak.
    expect($rows[$d['tepung']->id]['qty'])->toBe(2.0)
        ->and($rows[$d['tepung']->id]['unit'])->toBe('kg')
        ->and($rows[$d['minyak']->id]['qty'])->toBe(1.2)
        ->and($req['total_cost'])->toBe(48000.0)
        ->and($req['issues'])->toBe([]);
});

it('menyusun form kebutuhan dan menjaga isian manusia saat disegarkan', function () {
    $d = siapkanProduksi();
    $order = app(ProductionOrderService::class)->generateFromSpk($d['spk']);
    $service = app(RequisitionService::class);

    $requisition = $service->build($order)['requisition'];
    $tepung = $requisition->lines->firstWhere('inventory_item_id', $d['tepung']->id);

    // Beli awal = Kebutuhan - 0.
    expect($requisition->status)->toBe(Requisition::STATUS_DRAFT)
        ->and((float) $tepung->required_qty)->toBe(2.0)
        ->and((float) $tepung->purchase_qty)->toBe(2.0);

    // Dapur menghitung stok: 0,5 kg -> Beli 1,5 kg. Lalu dibulatkan ke 2 kg.
    $service->fillOpeningStock($tepung, 0.5);
    expect((float) $tepung->fresh()->purchase_qty)->toBe(1.5);

    $service->overridePurchaseQty($tepung->fresh(), 2);

    // PO berubah, form disegarkan: kebutuhan ikut, isian tangan tidak hilang.
    PurchaseOrderItem::query()->where('product_id', $d['product']->id)->where('qty', 30)->update(['qty' => 40]);
    app(ProductionOrderService::class)->generateFromSpk($d['spk']);
    $again = $service->build($order->fresh())['requisition'];
    $tepung = $again->lines->firstWhere('inventory_item_id', $d['tepung']->id);

    expect((float) $tepung->required_qty)->toBe(2.25)
        ->and((float) $tepung->opening_stock_qty)->toBe(0.5)
        // Beli yang sudah disunting tangan tidak ditimpa rumus.
        ->and((float) $tepung->purchase_qty)->toBe(2.0);

    // Baris yang belum disentuh tangan mengikuti rumus lagi.
    $minyak = $again->lines->firstWhere('inventory_item_id', $d['minyak']->id);
    expect((float) $minyak->purchase_qty)->toBe(1.35);
});

it('tidak menyetujui form yang stok awalnya belum diisi', function () {
    $d = siapkanProduksi();
    $order = app(ProductionOrderService::class)->generateFromSpk($d['spk']);
    $service = app(RequisitionService::class);
    $requisition = $service->build($order)['requisition'];

    expect(fn () => $service->approve($requisition))->toThrow(RuntimeException::class, 'Stok Awal');

    foreach ($requisition->lines as $line) {
        $service->fillOpeningStock($line, 0);
    }

    // Kontrol positif: setelah seluruh stok diisi, form bisa disetujui.
    $service->approve($requisition->fresh());

    expect($requisition->fresh()->status)->toBe(Requisition::STATUS_APPROVED)
        // Setelah disetujui, isian dikunci.
        ->and(fn () => $service->fillOpeningStock($requisition->lines->first()->fresh(), 1))
        ->toThrow(RuntimeException::class);
});

it('memposting saldo awal dan pembelian ke ledger hanya saat diperiksa, sekali saja', function () {
    $d = siapkanProduksi();
    $order = app(ProductionOrderService::class)->generateFromSpk($d['spk']);
    $service = app(RequisitionService::class);
    $ledger = app(InventoryLedgerService::class);
    $requisition = $service->build($order)['requisition'];

    $tepung = $requisition->lines->firstWhere('inventory_item_id', $d['tepung']->id);
    $minyak = $requisition->lines->firstWhere('inventory_item_id', $d['minyak']->id);
    $service->fillOpeningStock($tepung, 0.5);   // beli 1,5
    $service->fillOpeningStock($minyak, 3);     // beli 0 -- stok cukup

    // Belum diperiksa: tidak boleh ada apa pun di ledger.
    expect(fn () => $service->check($requisition->fresh()))->toThrow(RuntimeException::class);
    expect(InventoryMovement::query()->count())->toBe(0);

    $service->approve($requisition->fresh());
    $service->check($requisition->fresh());

    // Tepung: opening 0,5 + purchase 1,5 = 2 kg. Minyak: opening 3, tanpa purchase.
    expect($ledger->balance($d['tepung']->id))->toBe(2.0)
        ->and($ledger->balance($d['minyak']->id))->toBe(3.0)
        ->and(InventoryMovement::query()->ofType(InventoryMovement::TYPE_OPENING)->count())->toBe(2)
        ->and(InventoryMovement::query()->ofType(InventoryMovement::TYPE_PURCHASE)->count())->toBe(1);

    // Memeriksa dua kali ditolak; ledger tidak bertambah.
    expect(fn () => $service->check($requisition->fresh()))->toThrow(RuntimeException::class);
    expect(InventoryMovement::query()->count())->toBe(3);
});

it('tidak memposting saldo awal lagi untuk bahan yang sudah punya ledger', function () {
    $d = siapkanProduksi();
    $ledger = app(InventoryLedgerService::class);

    // Tepung sudah pernah tercatat: saldo 5 kg dari form sebelumnya.
    $ledger->post($d['tepung']->id, InventoryMovement::TYPE_OPENING, 5, 'kg', '2026-09-01', 12000);

    $order = app(ProductionOrderService::class)->generateFromSpk($d['spk']);
    $service = app(RequisitionService::class);
    $requisition = $service->build($order)['requisition'];

    foreach ($requisition->lines as $line) {
        $service->fillOpeningStock($line, 4);
    }

    $service->approve($requisition->fresh());
    $service->check($requisition->fresh());

    // Stok Awal 4 kg di form kedua bukan saldo pembuka; kalau diposting lagi,
    // saldo tepung menjadi 9 kg dari udara.
    expect(InventoryMovement::query()->where('inventory_item_id', $d['tepung']->id)->ofType(InventoryMovement::TYPE_OPENING)->count())->toBe(1)
        ->and($ledger->balance($d['tepung']->id))->toBe(5.0)
        // Kontrol positif: minyak yang belum pernah ada memang mendapat saldo awal.
        ->and($ledger->balance($d['minyak']->id))->toBe(4.0);
});

it('memposting pemakaian dan penyesuaian saat spk ditutup, lalu menolak penutupan kedua', function () {
    $d = siapkanProduksi();
    $order = app(ProductionOrderService::class)->generateFromSpk($d['spk']);
    $service = app(RequisitionService::class);
    $ledger = app(InventoryLedgerService::class);
    $completion = app(ProductionCompletionService::class);

    $requisition = $service->build($order)['requisition'];
    $tepung = $requisition->lines->firstWhere('inventory_item_id', $d['tepung']->id);
    $minyak = $requisition->lines->firstWhere('inventory_item_id', $d['minyak']->id);
    $service->fillOpeningStock($tepung, 1);   // beli 1 -> saldo 2
    $service->fillOpeningStock($minyak, 2);   // beli 0 -> saldo 2

    // Sebelum diperiksa, penutupan ditolak dan ledger tetap kosong.
    expect(fn () => $completion->complete($order->fresh()))->toThrow(RuntimeException::class);

    $service->approve($requisition->fresh());
    $service->check($requisition->fresh());

    // Dapur mencatat: tepung dipakai 2,2 kg (lebih dari resep), sisa fisik 0.
    // Minyak tidak dicatat -> pakai kebutuhan resep 1,2 liter.
    $completion->recordActuals($tepung->fresh(), 2.2, 0);

    $hasil = $completion->complete($order->fresh());

    // Pemakaian: 2,2 kg x 12.000 + 1,2 l x 20.000 = 26.400 + 24.000 = 50.400.
    expect($hasil['usage_value'])->toBe(50400.0)
        ->and($hasil['lines'])->toBe(2)
        // Saldo tepung setelah pemakaian = 2 - 2,2 = -0,2; sisa fisik 0 ->
        // penyesuaian +0,2 kg (Rp 2.400), dicatat terpisah dari pemakaian.
        ->and($hasil['adjustment_value'])->toBe(2400.0)
        ->and($ledger->balance($d['tepung']->id))->toBe(0.0)
        ->and($ledger->balance($d['minyak']->id))->toBe(0.8)
        ->and($order->fresh()->status)->toBe(ProductionOrder::STATUS_COMPLETED);

    $sebelum = InventoryMovement::query()->count();

    expect(fn () => $completion->complete($order->fresh()))->toThrow(RuntimeException::class, 'dua kali');
    expect(InventoryMovement::query()->count())->toBe($sebelum);
});

it('membawa pemakaian resep ke laporan pemakaian bahan sebagai pembanding residual', function () {
    $d = siapkanProduksi();
    $order = app(ProductionOrderService::class)->generateFromSpk($d['spk']);
    $service = app(RequisitionService::class);

    $requisition = $service->build($order)['requisition'];
    foreach ($requisition->lines as $line) {
        $service->fillOpeningStock($line, 0);
    }
    $service->approve($requisition->fresh());
    $service->check($requisition->fresh());
    app(ProductionCompletionService::class)->complete($order->fresh());

    $from = now()->parse('2026-09-01');
    $to = now()->parse('2026-09-30');

    // Resep: 2 kg x 12.000 + 1,2 l x 20.000 = 48.000.
    $summary = app(InventoryUsageService::class)->calculateForItem($d['bucket']->id, $from, $to);

    expect($summary['usage_recipe'])->toBe(48000.0)
        // Angka residual tidak tersentuh: tidak ada opname/pembelian nilai di periode ini.
        ->and($summary['usage'])->toBe(0.0);

    $banding = app(ProductionUsageService::class)->compareBucket($d['bucket'], $from, $to);

    expect($banding['recipe_usage'])->toBe(48000.0)
        ->and($banding['recipe_adjustment'])->toBe(0.0)
        ->and($banding['residual'])->toBe(0.0)
        ->and($banding['variance'])->toBe(-48000.0);
});
