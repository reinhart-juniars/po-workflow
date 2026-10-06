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
        'sales_actual_id' => $f['draftB']->id,
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

it('hanya mengizinkan Sales Actual tanpa DO untuk customer asal retur', function () {
    $f = barangSisaFixture();
    actingAs($f['sales']);
    $leftovers = app(LeftoverStockService::class);

    $tanpaDoCustomerLain = SalesActual::query()->create([
        'customer_id' => $f['customerB']->id, 'sales_date' => now()->toDateString(), 'status' => 'draft',
    ]);

    expect(fn () => $leftovers->addToSalesActual($tanpaDoCustomerLain, $f['entry'], 1))
        ->toThrow(ValidationException::class, 'tidak punya PO');

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
