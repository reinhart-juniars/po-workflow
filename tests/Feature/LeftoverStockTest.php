<?php

use App\Models\Area;
use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\LeftoverDisposal;
use App\Models\OtherIncome;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use App\Models\User;
use App\Services\LeftoverStockService;
use App\Services\SalesActualService;
use App\Support\Settings\Settings;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

/**
 * Barang Sisa: retur penjualan menjadi stok barang jadi yang boleh dijual ke
 * customer mana pun (revisi Owner), bukan lagi otomatis dibawa ke draft
 * customer yang sama. Yang dijaga: stok tidak bisa dijual/dibuang dua kali,
 * dan cara bayar Penjualan Barang Sisa mengikuti PO customer pembeli.
 */

/**
 * Customer A punya Sales Actual yang sudah disubmit dengan retur 3 Nasi
 * Goreng (HPP 5.000). Customer B punya DO hari ini dengan PO tunai ke akun
 * kas B, dan draft Sales Actual-nya sudah dibuat dari DO.
 *
 * @return array<string, mixed>
 */
function barangSisaFixture(): array
{
    Role::findOrCreate('sales', 'web');

    $sales = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $sales->assignRole('sales');

    $area = Area::query()->create(['name' => 'Area Sisa', 'code' => 'SIS']);
    $customerA = Customer::query()->create(['name' => 'Customer Asal', 'area_id' => $area->id]);
    $customerB = Customer::query()->create(['name' => 'Customer Pembeli', 'area_id' => $area->id]);
    $product = Product::query()->create([
        'name' => 'Nasi Goreng', 'sku' => 'NASGOR', 'unit' => 'porsi', 'base_price' => 15000,
        'raw_material_cost' => 5000, 'overhead_cost' => 2000, 'active' => true,
    ]);

    $asal = SalesActual::query()->create([
        'customer_id' => $customerA->id,
        'sales_date' => now()->subDay()->toDateString(),
        'status' => 'submitted',
        'submitted_at' => now()->subDay(),
        'submitted_by' => $sales->id,
        'notes' => 'Retur 3',
    ]);
    // 10 dikirim, 7 terjual -> retur 3 (dihitung hook saving).
    $entry = SalesActualItem::query()->create([
        'sales_actual_id' => $asal->id,
        'product_id' => $product->id,
        'item_name' => 'NASI GORENG',
        'unit' => 'porsi',
        'qty_delivery' => 10,
        'qty_actual' => 7,
        'unit_price' => 15000,
        'raw_material_cost' => 5000,
        'overhead_cost' => 2000,
    ]);

    $cashB = CashAccount::query()->create(['name' => 'Kas Customer B', 'type' => 'cash', 'is_active' => true]);
    $poB = PurchaseOrder::query()->create([
        'po_number' => 'PO-SISA-B', 'customer_id' => $customerB->id, 'recipient_name' => 'B',
        'shipping_address' => 'Jl. B', 'area_id' => $area->id, 'delivery_date' => now()->toDateString(),
        'delivery_time' => '09:00:00', 'payment_type' => 'cash', 'cash_account_id' => $cashB->id,
        'status' => 'completed', 'completed_at' => now(), 'created_by' => $sales->id, 'updated_by' => $sales->id,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $poB->id, 'product_id' => $product->id, 'qty' => 4, 'unit' => 'porsi',
        'unit_price' => 15000, 'subtotal' => 60000,
    ]);
    $doB = DeliveryOrder::query()->create([
        'do_code' => 'DO-SISA-B', 'area_id' => $area->id, 'scheduled_at' => now(),
        'driver_user_id' => $sales->id, 'status' => 'delivered',
    ]);
    $doB->purchaseOrders()->attach($poB);
    $draftB = app(SalesActualService::class)->createDraftFromDeliveryOrder($doB)->first();

    return compact('sales', 'customerA', 'customerB', 'product', 'asal', 'entry', 'cashB', 'poB', 'draftB');
}

it('memasukkan retur ke Barang Sisa lalu menjualnya ke customer lain dengan cara bayar customer pembeli', function () {
    $f = barangSisaFixture();
    actingAs($f['sales']);

    $this->get(route('salesapp.leftovers.index'))
        ->assertOk()
        ->assertSee('NASI GORENG')
        ->assertViewHas('totalValue', 15000.0); // 3 x HPP 5.000

    $this->post(route('salesapp.leftovers.sell', $f['entry']), [
        'customer_name' => 'customer pembeli', // diketik, huruf kecil pun cocok
        'payment' => 'po',
        'qty' => 2,
        'unit_price' => 12000,
    ])->assertRedirect(route('salesapp.actuals.edit', $f['draftB']));

    $sale = SalesActualItem::query()->where('source_sales_actual_item_id', $f['entry']->id)->sole();

    expect($sale->sales_actual_id)->toBe($f['draftB']->id)
        ->and($sale->is_carry_forward)->toBeTrue()
        ->and((float) $sale->qty_delivery)->toBe(2.0)
        ->and((float) $sale->unit_price)->toBe(12000.0)
        ->and((float) $sale->raw_material_cost)->toBe(5000.0)
        // Cara bayar mengikuti PO customer pembeli, bukan customer asal retur.
        ->and($sale->purchase_order_id)->toBe($f['poB']->id)
        ->and(app(LeftoverStockService::class)->availableQty($f['entry']))->toBe(1.0);

    // Halaman edit Sales Actual B menampilkan asal Barang Sisa.
    $this->get(route('salesapp.actuals.edit', $f['draftB']))
        ->assertOk()
        ->assertSee('Barang Sisa dari retur Customer Asal');

    app(SalesActualService::class)->submit($f['draftB']->fresh());
    app(SalesActualService::class)->postDailyClosing(now(), now());

    // PO B 4 x 15.000 + Barang Sisa 2 x 12.000, semuanya ke akun kas B.
    $income = OtherIncome::query()->where('source_type', OtherIncome::SOURCE_SALES_DAILY_CLOSING)->sole();
    expect($income->cash_account_id)->toBe($f['cashB']->id)
        ->and((float) $income->amount)->toBe(84000.0);

    expect(AuditLog::query()->where('action', 'sales_actual_leftover_added')->exists())->toBeTrue();
});

it('menolak menjual Barang Sisa melebihi sisanya', function () {
    $f = barangSisaFixture();
    actingAs($f['sales']);

    // Kontrol positif: 2 dari 3 boleh.
    $this->post(route('salesapp.actuals.leftovers.store', $f['draftB']), [
        'leftover_entry_id' => $f['entry']->id,
        'leftover_qty' => 2,
    ])->assertSessionHasNoErrors();

    // Draft yang belum disubmit pun sudah mengunci stoknya: sisa 1, minta 2 ditolak.
    $this->post(route('salesapp.actuals.leftovers.store', $f['draftB']), [
        'leftover_entry_id' => $f['entry']->id,
        'leftover_qty' => 2,
    ])->assertSessionHasErrors('leftover_qty');

    expect(SalesActualItem::query()->where('source_sales_actual_item_id', $f['entry']->id)->sum('qty_delivery'))->toEqual(2)
        ->and(app(LeftoverStockService::class)->availableQty($f['entry']))->toBe(1.0);
});

it('mengembalikan qty ke Barang Sisa saat penjualannya dilepas, tetapi tidak setelah disubmit', function () {
    $f = barangSisaFixture();
    actingAs($f['sales']);
    $leftovers = app(LeftoverStockService::class);

    $sale = $leftovers->addToSalesActual($f['draftB'], $f['entry'], 3);
    expect($leftovers->availableQty($f['entry']))->toBe(0.0);

    $this->delete(route('salesapp.actuals.leftovers.destroy', [$f['draftB'], $sale]))
        ->assertRedirect(route('salesapp.actuals.edit', $f['draftB']));

    expect(SalesActualItem::query()->find($sale->id))->toBeNull()
        ->and($leftovers->availableQty($f['entry']))->toBe(3.0);

    // Setelah disubmit, penjualan terkunci.
    $sale = $leftovers->addToSalesActual($f['draftB'], $f['entry'], 1);
    app(SalesActualService::class)->submit($f['draftB']->fresh());

    $this->delete(route('salesapp.actuals.leftovers.destroy', [$f['draftB'], $sale]))
        ->assertSessionHasErrors('leftover');

    expect(SalesActualItem::query()->find($sale->id))->not->toBeNull();
});

it('meminta cara bayar Tunai untuk Sales Actual tanpa DO customer lain, dan tidak memakai PO lama customer', function () {
    $f = barangSisaFixture();
    actingAs($f['sales']);
    $leftovers = app(LeftoverStockService::class);

    // Customer B punya PO (di DO lain), tapi Sales Actual ini tanpa DO: "ikut PO" ditolak,
    // PO lama tidak dipakai supaya ongkirnya tidak tertagih dua kali.
    $tanpaDoCustomerLain = SalesActual::query()->create([
        'customer_id' => $f['customerB']->id, 'sales_date' => now()->toDateString(), 'status' => 'draft',
    ]);

    expect(fn () => $leftovers->addToSalesActual($tanpaDoCustomerLain, $f['entry'], 1))
        ->toThrow(ValidationException::class, 'Pilih cara bayar Tunai');

    // Akun kas nonaktif ditolak.
    $kasMati = CashAccount::query()->create(['name' => 'Kas Lama', 'type' => 'cash', 'is_active' => false]);
    expect(fn () => $leftovers->addToSalesActual($tanpaDoCustomerLain, $f['entry'], 1, null, $kasMati->id))
        ->toThrow(ValidationException::class, 'tidak aktif');

    // Tunai ke akun kas aktif: tanpa PO.
    $saleB = $leftovers->addToSalesActual($tanpaDoCustomerLain, $f['entry'], 1, null, $f['cashB']->id);
    expect($saleB->purchase_order_id)->toBeNull()
        ->and($saleB->cash_account_id)->toBe($f['cashB']->id);
    $leftovers->removeFromSalesActual($saleB);

    // Kontrol positif: customer asal tanpa DO boleh (cara bayar diwarisi dari PO asal).
    $tanpaDoCustomerAsal = SalesActual::query()->create([
        'customer_id' => $f['customerA']->id, 'sales_date' => now()->toDateString(), 'status' => 'draft',
    ]);
    $sale = $leftovers->addToSalesActual($tanpaDoCustomerAsal, $f['entry'], 1);

    expect($sale->purchase_order_id)->toBeNull()
        ->and($leftovers->availableQty($f['entry']))->toBe(2.0);
});

it('membuang Barang Sisa dengan alasan, mengurangi stok, dan mencatatnya di laporan waste', function () {
    $f = barangSisaFixture();
    actingAs($f['sales']);

    $this->post(route('salesapp.leftovers.dispose', $f['entry']), [
        'qty' => 1, 'disposed_at' => now()->toDateString(), 'reason' => '',
    ])->assertSessionHasErrors('reason');

    $this->post(route('salesapp.leftovers.dispose', $f['entry']), [
        'qty' => 5, 'disposed_at' => now()->toDateString(), 'reason' => 'Basi',
    ])->assertSessionHasErrors('dispose_qty');

    expect(LeftoverDisposal::query()->count())->toBe(0);

    // Kontrol positif.
    $this->post(route('salesapp.leftovers.dispose', $f['entry']), [
        'qty' => 1, 'disposed_at' => now()->toDateString(), 'reason' => 'Basi',
    ])->assertRedirect(route('salesapp.leftovers.index'))->assertSessionHasNoErrors();

    expect(app(LeftoverStockService::class)->availableQty($f['entry']))->toBe(2.0);

    $this->get(route('salesapp.reports.waste', ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]))
        ->assertOk()
        ->assertViewHas('totalWasteQty', 1.0)
        ->assertViewHas('totalWasteCost', 7000.0)      // 1 x (5.000 + 2.000)
        ->assertViewHas('totalWasteSelling', 15000.0);
});

it('hanya membuka Barang Sisa untuk peran sales, owner, dan superadmin', function () {
    $f = barangSisaFixture();
    Role::findOrCreate('delivery', 'web');
    $kurir = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $kurir->assignRole('delivery');

    $this->actingAs($kurir)->get(route('salesapp.leftovers.index'))->assertForbidden();
    $this->actingAs($kurir)->post(route('salesapp.leftovers.dispose', $f['entry']), [
        'qty' => 1, 'disposed_at' => now()->toDateString(), 'reason' => 'x',
    ])->assertForbidden();

    expect(LeftoverDisposal::query()->count())->toBe(0);

    // Kontrol positif.
    $this->actingAs($f['sales'])->get(route('salesapp.leftovers.index'))->assertOk();
});

it('menjual Barang Sisa ke customer yang diketik dari Master Customer, hanya menampilkan namanya', function () {
    $f = barangSisaFixture();
    actingAs($f['sales']);
    Customer::query()->create(['name' => 'Customer Tanpa Draft', 'area_id' => $f['customerA']->area_id]);
    Customer::query()->create(['name' => Customer::INTERNAL_READY_STOCK, 'area_id' => $f['customerA']->area_id, 'is_internal' => true]);

    // Pilihan customer = nama Master Customer saja, tanpa tanggal/DO; customer internal tidak ikut.
    $this->get(route('salesapp.leftovers.index'))
        ->assertOk()
        ->assertSee('<option value="Customer Pembeli">', false)
        ->assertSee('<option value="Customer Tanpa Draft">', false)
        ->assertDontSee('<option value="'.Customer::INTERNAL_READY_STOCK.'">', false)
        ->assertDontSee('DO-SISA-B');

    // Nama di luar Master Customer ditolak.
    $this->post(route('salesapp.leftovers.sell', $f['entry']), [
        'customer_name' => 'Customer Karangan', 'payment' => 'po', 'qty' => 1,
    ])->assertSessionHasErrors(['customer_name' => 'Customer "Customer Karangan" tidak ada di Master Customer. Pilih nama dari daftar.']);

    // Customer internal bukan pembeli.
    $this->post(route('salesapp.leftovers.sell', $f['entry']), [
        'customer_name' => Customer::INTERNAL_READY_STOCK, 'payment' => 'po', 'qty' => 1,
    ])->assertSessionHasErrors('customer_name');

    // Customer tanpa draft dengan cara bayar "ikut PO": tidak ada PO yang bisa diikuti,
    // dan tidak tertinggal draft kosong.
    $this->post(route('salesapp.leftovers.sell', $f['entry']), [
        'customer_name' => 'Customer Tanpa Draft', 'payment' => 'po', 'qty' => 1,
    ])->assertSessionHasErrors('payment');

    // Cara bayar wajib dan formatnya dijaga.
    $this->post(route('salesapp.leftovers.sell', $f['entry']), [
        'customer_name' => 'Customer Pembeli', 'qty' => 1,
    ])->assertSessionHasErrors('payment');
    $this->post(route('salesapp.leftovers.sell', $f['entry']), [
        'customer_name' => 'Customer Pembeli', 'payment' => 'cash:abc', 'qty' => 1,
    ])->assertSessionHasErrors('payment');

    expect(SalesActualItem::query()->where('source_sales_actual_item_id', $f['entry']->id)->count())->toBe(0)
        ->and(SalesActual::query()->where('status', 'draft')->count())->toBe(1); // hanya draft B

    // Kontrol positif: nama diketik dengan spasi berlebih tetap cocok ke draft customer itu.
    $this->post(route('salesapp.leftovers.sell', $f['entry']), [
        'customer_name' => '  Customer Pembeli ', 'payment' => 'po', 'qty' => 1, 'unit_price' => 12000,
    ])->assertSessionHasNoErrors()->assertRedirect(route('salesapp.actuals.edit', $f['draftB']));

    $sale = SalesActualItem::query()->where('source_sales_actual_item_id', $f['entry']->id)->sole();
    expect($sale->sales_actual_id)->toBe($f['draftB']->id)
        ->and($sale->purchase_order_id)->toBe($f['poB']->id);
});

it('mencatat Barang Sisa batal dibuat: stok dan persediaan berkurang tanpa dihitung waste', function () {
    $f = barangSisaFixture();
    actingAs($f['sales']);
    $leftovers = app(LeftoverStockService::class);
    // Barang Sisa hanya dinilai sejak tanggal mulai akuntansinya.
    app(Settings::class)->set('leftover.accounting_start', now()->subMonth()->toDateString());

    expect($leftovers->valueAt(now()))->toBe(15000.0); // 3 x HPP 5.000

    // Melebihi sisa ditolak.
    $this->post(route('salesapp.leftovers.cancel', $f['entry']), [
        'qty' => 4, 'disposed_at' => now()->toDateString(),
    ])->assertSessionHasErrors('dispose_qty');

    expect(LeftoverDisposal::query()->count())->toBe(0);

    // Kontrol positif: keterangan boleh kosong.
    $this->post(route('salesapp.leftovers.cancel', $f['entry']), [
        'qty' => 2, 'disposed_at' => now()->toDateString(),
    ])->assertRedirect(route('salesapp.leftovers.index'))->assertSessionHasNoErrors();

    $batal = LeftoverDisposal::query()->sole();
    expect($batal->kind)->toBe(LeftoverDisposal::KIND_CANCEL)
        ->and($batal->isCancel())->toBeTrue()
        ->and($leftovers->availableQty($f['entry']))->toBe(1.0)
        ->and($leftovers->valueAt(now()))->toBe(5000.0)
        // Bukan waste: tidak masuk Barang Sisa Dibuang di HPP.
        ->and($leftovers->wasteValueBetween(now()->startOfMonth(), now()))->toBe(0.0);

    // Bandingkan: dibuang tetap dihitung waste.
    $leftovers->dispose($f['entry'], 1, now(), 'Basi');
    expect($leftovers->wasteValueBetween(now()->startOfMonth(), now()))->toBe(5000.0)
        ->and($leftovers->availableQty($f['entry']))->toBe(0.0);

    // Laporan waste Sales hanya berisi yang dibuang.
    $this->get(route('salesapp.reports.waste', ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]))
        ->assertOk()
        ->assertViewHas('totalWasteQty', 1.0);

    $this->get(route('salesapp.leftovers.index'))->assertOk()->assertSee('Batal dibuat');

    expect(AuditLog::query()->where('action', 'leftover_cancelled')->count())->toBe(1);
});

it('menjual Barang Sisa ke customer lapak tanpa DO hari ini: dibuatkan draft, bayar Tunai ke akun kas pilihan', function () {
    $f = barangSisaFixture();
    actingAs($f['sales']);
    $area = $f['customerA']->area_id;

    // SHINTA: customer lapak yang pernah punya PO (dengan ongkir), hari ini tanpa DO/draft.
    $shinta = Customer::query()->create(['name' => 'SHINTA', 'area_id' => $area, 'is_lapak' => true]);
    PurchaseOrder::query()->create([
        'po_number' => 'PO-SHINTA-1', 'customer_id' => $shinta->id, 'recipient_name' => 'Shinta',
        'shipping_address' => 'Lapak', 'area_id' => $area, 'delivery_date' => now()->subDay()->toDateString(),
        'delivery_time' => '07:00:00', 'payment_type' => 'cash', 'cash_account_id' => $f['cashB']->id, 'shipping_cost' => 5000,
        'status' => 'completed', 'completed_at' => now(), 'created_by' => $f['sales']->id, 'updated_by' => $f['sales']->id,
    ]);
    $kasLapak = CashAccount::query()->create(['name' => 'Kas Lapak', 'type' => 'cash', 'is_active' => true]);

    // Form menawarkan akun kas aktif sebagai cara bayar Tunai.
    $this->get(route('salesapp.leftovers.index'))->assertOk()->assertSee('Tunai · Kas Lapak');

    // "Ikut PO" ditolak (tidak ada DO hari ini) dan tidak meninggalkan draft kosong.
    $this->post(route('salesapp.leftovers.sell', $f['entry']), [
        'customer_name' => 'shinta', 'payment' => 'po', 'qty' => 2, 'unit_price' => 10000,
    ])->assertSessionHasErrors('payment');
    // Qty berlebih juga tidak meninggalkan draft.
    $this->post(route('salesapp.leftovers.sell', $f['entry']), [
        'customer_name' => 'shinta', 'payment' => 'cash:'.$kasLapak->id, 'qty' => 9, 'unit_price' => 10000,
    ])->assertSessionHasErrors('leftover_qty');
    expect(SalesActual::query()->where('customer_id', $shinta->id)->exists())->toBeFalse();

    // Kontrol positif.
    $response = $this->post(route('salesapp.leftovers.sell', $f['entry']), [
        'customer_name' => 'shinta', 'payment' => 'cash:'.$kasLapak->id, 'qty' => 2, 'unit_price' => 10000,
    ])->assertSessionHasNoErrors();

    $draft = SalesActual::query()->where('customer_id', $shinta->id)->sole();
    $response->assertRedirect(route('salesapp.actuals.edit', $draft));

    expect($draft->status)->toBe('draft')
        ->and($draft->delivery_order_id)->toBeNull()
        ->and($draft->is_internal)->toBeFalse()
        ->and($draft->sales_date->toDateString())->toBe(now()->toDateString());

    $sale = SalesActualItem::query()->where('sales_actual_id', $draft->id)->sole();
    expect($sale->purchase_order_id)->toBeNull()
        ->and($sale->cash_account_id)->toBe($kasLapak->id);

    // Penjualan berikutnya ke SHINTA masuk draft yang sama, lewat panel di halaman edit.
    $this->get(route('salesapp.actuals.edit', $draft))
        ->assertOk()
        ->assertSee('SHINTA')
        ->assertSee('Tunai Kas Lapak')
        // Draft tanpa DO: bawaan cara bayar = Tunai, bukan ikut PO.
        ->assertSee('<option value="cash:'.$f['cashB']->id.'" selected', false);
    $this->post(route('salesapp.actuals.leftovers.store', $draft), [
        'leftover_source' => 'entry:'.$f['entry']->id, 'leftover_qty' => 1, 'leftover_price' => 10000,
        'payment' => 'cash:'.$kasLapak->id,
    ])->assertSessionHasNoErrors();
    expect(SalesActual::query()->where('customer_id', $shinta->id)->count())->toBe(1);

    // Submit + closing: 3 x 10.000 masuk Kas Lapak, tanpa ongkir PO lama SHINTA.
    app(SalesActualService::class)->submit($draft->fresh());
    app(SalesActualService::class)->postDailyClosing(now(), now());

    $incomes = OtherIncome::query()->where('source_type', OtherIncome::SOURCE_SALES_DAILY_CLOSING)->get();
    expect($incomes)->toHaveCount(1)
        ->and($incomes->first()->cash_account_id)->toBe($kasLapak->id)
        ->and((float) $incomes->first()->amount)->toBe(30000.0)
        ->and(OtherIncome::query()->where('cash_account_id', $f['cashB']->id)->exists())->toBeFalse();

    expect(AuditLog::query()->where('action', 'sales_actual_draft_created')->where('entity_id', $draft->id)->exists())->toBeTrue();
});
