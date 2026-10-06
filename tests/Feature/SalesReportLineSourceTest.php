<?php

use App\Models\Area;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use App\Models\User;
use App\Services\SalesReportService;
use Illuminate\Http\Request;

/**
 * Laporan Penjualan membaca item sebagai array (tanpa model) supaya rentang
 * panjang tetap cepat. Yang dijaga di sini: PO asal tetap ditemukan lewat
 * rantai Barang Sisa / carry forward (item tanpa PO item), total dan kolom
 * menu/harga tetap benar, dan menu 3S tetap masuk seksi 3S.
 */
it('menemukan PO asal lewat rantai Barang Sisa dan menjumlahkan baris dengan benar', function () {
    $user = User::factory()->create();
    $area = Area::query()->create(['name' => 'Area Lap', 'code' => 'LAP']);
    $asal = Customer::query()->create(['name' => 'Customer Asal', 'area_id' => $area->id, 'is_lapak' => false]);
    $pembeli = Customer::query()->create(['name' => 'Customer Pembeli', 'area_id' => $area->id, 'is_lapak' => false]);
    $nasgor = Product::query()->create(['name' => 'Nasi Goreng', 'sku' => 'NASGOR', 'unit' => 'porsi', 'base_price' => 15000, 'active' => true, 'is_3s' => true]);
    $esteh = Product::query()->create(['name' => 'Es Teh', 'sku' => 'ESTEH', 'unit' => 'cup', 'base_price' => 5000, 'active' => true]);

    $po = PurchaseOrder::query()->create([
        'po_number' => 'PO-ASAL-01', 'customer_id' => $asal->id, 'recipient_name' => 'Bu Asal', 'shipping_address' => 'Jl. A',
        'area_id' => $area->id, 'delivery_date' => '2026-09-10', 'delivery_time' => '09:00:00', 'payment_type' => 'cash',
        'status' => 'completed', 'shipping_cost' => 7000, 'created_by' => $user->id, 'updated_by' => $user->id,
    ]);
    $poi = PurchaseOrderItem::query()->create(['purchase_order_id' => $po->id, 'product_id' => $nasgor->id, 'qty' => 10, 'unit' => 'porsi', 'unit_price' => 15000, 'subtotal' => 150000]);

    $submitted = fn (Customer $c, string $date) => SalesActual::query()->create([
        'customer_id' => $c->id, 'sales_date' => $date, 'status' => 'submitted', 'submitted_at' => $date.' 18:00:00', 'submitted_by' => $user->id,
    ]);

    // 10 dikirim, 7 laku -> retur 3 (punya PO item).
    $saAsal = $submitted($asal, '2026-09-10');
    $entry = SalesActualItem::query()->create([
        'sales_actual_id' => $saAsal->id, 'purchase_order_item_id' => $poi->id, 'product_id' => $nasgor->id,
        'item_name' => 'NASI GORENG', 'unit' => 'porsi', 'qty_delivery' => 10, 'qty_actual' => 7, 'unit_price' => 15000,
    ]);
    // Tingkat 1: 3 porsi retur dibawa, 2 laku (tanpa PO item).
    $saHari2 = $submitted($asal, '2026-09-11');
    $tingkat1 = SalesActualItem::query()->create([
        'sales_actual_id' => $saHari2->id, 'source_sales_actual_item_id' => $entry->id, 'is_carry_forward' => true, 'product_id' => $nasgor->id,
        'item_name' => 'NASI GORENG', 'unit' => 'porsi', 'qty_delivery' => 3, 'qty_actual' => 2, 'unit_price' => 15000,
    ]);
    // Tingkat 2: sisa 1 porsi dijual ke customer lain Rp 12.000, plus es teh biasa.
    $saPembeli = $submitted($pembeli, '2026-09-12');
    SalesActualItem::query()->create([
        'sales_actual_id' => $saPembeli->id, 'source_sales_actual_item_id' => $tingkat1->id, 'is_carry_forward' => true, 'product_id' => $nasgor->id,
        'item_name' => 'NASI GORENG', 'unit' => 'porsi', 'qty_delivery' => 1, 'qty_actual' => 1, 'unit_price' => 12000,
    ]);
    SalesActualItem::query()->create([
        'sales_actual_id' => $saPembeli->id, 'product_id' => $esteh->id, 'item_name' => '', 'unit' => 'cup',
        'qty_delivery' => 4, 'qty_actual' => 4, 'unit_price' => 5000,
    ]);

    $report = app(SalesReportService::class)->buildReportData(Request::create('/', 'GET', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30']));
    $rows = collect($report['salesGroups'])->flatMap(fn ($g) => $g['rows'])->keyBy('customer_label');

    $baris = $rows['Customer Pembeli'];

    expect($report['salesGrandTotals']['grand_total'])->toBe(105000.0 + 30000.0 + 12000.0 + 20000.0)
        ->and($report['salesGrandTotals']['total_orders'])->toBe(3)
        // PO asal ditemukan dua tingkat ke atas, ongkir ikut PO itu.
        ->and($baris['order_meta_lines'])->toBe(['PO-ASAL-01', 'Penerima: Bu Asal'])
        ->and($baris['shipping_cost'])->toBe(7000.0)
        ->and($baris['total_amount'])->toBe(32000.0)
        ->and($baris['total_qty'])->toBe(5)
        // Nama item kosong jatuh ke nama produk.
        ->and($baris['item_qty_map'])->toBe(['NASI GORENG' => 1.0, 'ES TEH' => 4.0])
        ->and($baris['price_amount_map'])->toBe(['price_12000_00' => 12000.0, 'price_5000_00' => 20000.0])
        ->and($baris['section_key'])->toBe('tiga_s')
        // Kolom menu diurutkan menurut seringnya muncul.
        ->and($report['itemColumns'])->toBe(['NASI GORENG', 'ES TEH'])
        ->and($report['salesGrandTotals']['item_totals'])->toBe(['NASI GORENG' => 10, 'ES TEH' => 4]);
});
