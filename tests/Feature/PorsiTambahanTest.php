<?php

use App\Models\Area;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\OtherIncome;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesActualItem;
use App\Models\User;
use App\Services\LeftoverStockService;
use App\Services\ProductionOrderService;
use App\Services\SalesActualService;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

/**
 * Adendum revisi Owner: PO 10 Nasi Goreng + 5 Bakmi, besoknya customer minta
 * 13 + 2. Boleh: 3 Bakmi yang tidak diambil menjadi Barang Sisa, 3 Nasi
 * Goreng tambahan ditagih dengan harga PO.
 */

/** @return array<string, mixed> */
function gantiMenuFixture(): array
{
    Role::findOrCreate('sales', 'web');

    $sales = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $sales->assignRole('sales');

    $area = Area::query()->create(['name' => 'Area Ganti', 'code' => 'GNT']);
    $customer = Customer::query()->create(['name' => 'Customer Ganti Menu', 'area_id' => $area->id]);
    $nasi = Product::query()->create(['name' => 'Nasi Goreng', 'sku' => 'NG-GNT', 'unit' => 'porsi', 'base_price' => 18000, 'raw_material_cost' => 5000, 'overhead_cost' => 2000, 'active' => true]);
    $bakmi = Product::query()->create(['name' => 'Bakmi Goreng', 'sku' => 'BM-GNT', 'unit' => 'porsi', 'base_price' => 16000, 'raw_material_cost' => 6000, 'overhead_cost' => 2000, 'active' => true]);

    $cash = CashAccount::query()->create(['name' => 'Kas Ganti Menu', 'type' => 'cash', 'is_active' => true]);
    $po = PurchaseOrder::query()->create([
        'po_number' => 'PO-GANTI', 'customer_id' => $customer->id, 'recipient_name' => 'Ganti', 'shipping_address' => 'Jl. Ganti',
        'area_id' => $area->id, 'delivery_date' => now()->toDateString(), 'delivery_time' => '09:00:00',
        'payment_type' => 'cash', 'cash_account_id' => $cash->id, 'status' => 'completed', 'completed_at' => now(),
        'created_by' => $sales->id, 'updated_by' => $sales->id,
    ]);
    // Harga di PO 15.000 (bukan harga master 18.000): Porsi Tambahan harus ikut harga PO.
    PurchaseOrderItem::query()->create(['purchase_order_id' => $po->id, 'product_id' => $nasi->id, 'qty' => 10, 'unit' => 'porsi', 'unit_price' => 15000, 'subtotal' => 150000, 'raw_material_cost' => 5000, 'overhead_cost' => 2000]);
    PurchaseOrderItem::query()->create(['purchase_order_id' => $po->id, 'product_id' => $bakmi->id, 'qty' => 5, 'unit' => 'porsi', 'unit_price' => 14000, 'subtotal' => 70000, 'raw_material_cost' => 6000, 'overhead_cost' => 2000]);

    $do = DeliveryOrder::query()->create(['do_code' => 'DO-GANTI', 'area_id' => $area->id, 'scheduled_at' => now(), 'driver_user_id' => $sales->id, 'status' => 'delivered']);
    $do->purchaseOrders()->attach($po);
    $draft = app(SalesActualService::class)->createDraftFromDeliveryOrder($do)->first();

    return compact('sales', 'customer', 'nasi', 'bakmi', 'cash', 'po', 'draft');
}

it('mencatat ganti menu 10+5 menjadi 13+2: kelebihan jadi Barang Sisa, tambahan ditagih harga PO', function () {
    $f = gantiMenuFixture();
    actingAs($f['sales']);
    $nasiLine = $f['draft']->items->firstWhere('product_id', $f['nasi']->id);
    $bakmiLine = $f['draft']->items->firstWhere('product_id', $f['bakmi']->id);

    $this->post(route('salesapp.actuals.extra-portions.store', $f['draft']), [
        'extra_line_id' => $nasiLine->id,
        'extra_qty' => 3,
    ])->assertRedirect(route('salesapp.actuals.edit', $f['draft']));

    $extra = SalesActualItem::query()->where('is_extra_portion', true)->sole();
    expect($extra->product_id)->toBe($f['nasi']->id)
        ->and((float) $extra->qty_delivery)->toBe(3.0)
        ->and((float) $extra->unit_price)->toBe(15000.0)
        ->and((float) $extra->raw_material_cost)->toBe(5000.0)
        ->and($extra->purchase_order_id)->toBe($f['po']->id)
        ->and($extra->purchase_order_item_id)->toBeNull()
        ->and($extra->is_carry_forward)->toBeFalse();

    $this->get(route('salesapp.actuals.edit', $f['draft']))
        ->assertOk()
        ->assertSee('Porsi Tambahan')
        ->assertSee('Hapus Porsi Tambahan');

    // Bakmi diambil 2 dari 5: retur 3.
    app(SalesActualService::class)->updateActualItems($f['draft'], [
        $nasiLine->id => ['qty_actual' => 10],
        $bakmiLine->id => ['qty_actual' => 2],
        $extra->id => ['qty_actual' => 3],
    ], 'Customer ganti 3 bakmi jadi nasi goreng');
    app(SalesActualService::class)->submit($f['draft']->fresh());

    $bakmiEntry = app(LeftoverStockService::class)->available()->firstWhere('product_id', $f['bakmi']->id);
    expect($bakmiEntry['available_qty'])->toBe(3.0)
        ->and($bakmiEntry['value'])->toBe(18000.0);

    app(SalesActualService::class)->postDailyClosing(now(), now());
    $income = OtherIncome::query()->where('source_type', OtherIncome::SOURCE_SALES_DAILY_CLOSING)->sole();
    // 13 x 15.000 + 2 x 14.000.
    expect($income->cash_account_id)->toBe($f['cash']->id)
        ->and((float) $income->amount)->toBe(223000.0);
});

it('menggabungkan Porsi Tambahan menu yang sama dan bisa menghapusnya selama draft', function () {
    $f = gantiMenuFixture();
    actingAs($f['sales']);
    $nasiLine = $f['draft']->items->firstWhere('product_id', $f['nasi']->id);

    foreach ([2, 1] as $qty) {
        $this->post(route('salesapp.actuals.extra-portions.store', $f['draft']), ['extra_line_id' => $nasiLine->id, 'extra_qty' => $qty]);
    }

    $extra = SalesActualItem::query()->where('is_extra_portion', true)->sole();
    expect((float) $extra->qty_delivery)->toBe(3.0)
        ->and((float) $extra->qty_actual)->toBe(3.0);

    $this->delete(route('salesapp.actuals.extra-portions.destroy', [$f['draft'], $extra]))
        ->assertRedirect(route('salesapp.actuals.edit', $f['draft']));
    expect(SalesActualItem::query()->where('is_extra_portion', true)->exists())->toBeFalse();

    // Baris biasa dari PO tidak bisa dihapus lewat jalur ini.
    $this->delete(route('salesapp.actuals.extra-portions.destroy', [$f['draft'], $nasiLine]))
        ->assertSessionHasErrors('extra');
    expect(SalesActualItem::query()->whereKey($nasiLine->id)->exists())->toBeTrue();
});

it('menolak Porsi Tambahan dari menu Sales Actual lain atau setelah submit', function () {
    $f = gantiMenuFixture();
    $other = gantiMenuLain($f);
    actingAs($f['sales']);
    $nasiLine = $f['draft']->items->firstWhere('product_id', $f['nasi']->id);

    $this->post(route('salesapp.actuals.extra-portions.store', $f['draft']), [
        'extra_line_id' => $other->items->first()->id,
        'extra_qty' => 3,
    ])->assertSessionHasErrors('extra_line_id');
    expect(SalesActualItem::query()->where('is_extra_portion', true)->exists())->toBeFalse();

    // Kontrol positif: menu PO miliknya sendiri diterima.
    $this->post(route('salesapp.actuals.extra-portions.store', $f['draft']), ['extra_line_id' => $nasiLine->id, 'extra_qty' => 1])
        ->assertSessionHasNoErrors();
    expect(SalesActualItem::query()->where('is_extra_portion', true)->count())->toBe(1);

    app(SalesActualService::class)->submit($f['draft']->fresh());

    $this->post(route('salesapp.actuals.extra-portions.store', $f['draft']), ['extra_line_id' => $nasiLine->id, 'extra_qty' => 1])
        ->assertSessionHasErrors('status');
    expect((float) SalesActualItem::query()->where('is_extra_portion', true)->sole()->qty_delivery)->toBe(1.0);
});

/** Sales Actual customer lain dari DO berbeda. */
function gantiMenuLain(array $f)
{
    $area = Area::query()->first();
    $customer = Customer::query()->create(['name' => 'Customer Lain', 'area_id' => $area->id]);
    $po = PurchaseOrder::query()->create([
        'po_number' => 'PO-LAIN', 'customer_id' => $customer->id, 'recipient_name' => 'Lain', 'shipping_address' => 'Jl. Lain',
        'area_id' => $area->id, 'delivery_date' => now()->toDateString(), 'delivery_time' => '09:00:00',
        'payment_type' => 'cash', 'cash_account_id' => $f['cash']->id, 'status' => 'completed', 'completed_at' => now(),
        'created_by' => $f['sales']->id, 'updated_by' => $f['sales']->id,
    ]);
    PurchaseOrderItem::query()->create(['purchase_order_id' => $po->id, 'product_id' => $f['nasi']->id, 'qty' => 2, 'unit' => 'porsi', 'unit_price' => 1000, 'subtotal' => 2000]);
    $do = DeliveryOrder::query()->create(['do_code' => 'DO-LAIN', 'area_id' => $area->id, 'scheduled_at' => now(), 'driver_user_id' => $f['sales']->id, 'status' => 'delivered']);
    $do->purchaseOrders()->attach($po);

    return app(SalesActualService::class)->createDraftFromDeliveryOrder($do)->first();
}

/** Payload ubah PO dari Admin App untuk PO pertama siapkanProduksi(). */
function ubahPoPayload(PurchaseOrder $po, int $qty, string $recipient = 'Pelanggan'): array
{
    return [
        'customer_id' => $po->customer_id,
        'recipient_name' => $recipient,
        'shipping_address' => 'Jl.',
        'area_id' => $po->area_id,
        'delivery_date' => '2026-09-15',
        'delivery_time' => '09:00',
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'payment_type' => 'receivable',
        'receivable_days' => 7,
        'items' => $po->items->map(fn (PurchaseOrderItem $item, int $index) => [
            'product_id' => $item->product_id,
            'qty' => $index === 0 ? $qty : (int) $item->qty,
        ])->all(),
        'edit_reason' => 'Customer ganti jumlah',
    ];
}

it('menyesuaikan SPK Produksi yang masih terbuka saat menu PO diubah', function () {
    Role::findOrCreate('admin', 'web');
    $data = siapkanProduksi();
    $admin = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $admin->assignRole('admin');
    $order = app(ProductionOrderService::class)->generateFromSpk($data['spk']);
    $po = $data['pos'][0]->load('items');

    actingAs($admin)
        ->put(route('adminapp.orders.update', $po), ubahPoPayload($po, 35))
        ->assertRedirect(route('adminapp.orders.show', $po))
        ->assertSessionHas('success', fn (string $message) => str_contains($message, 'SPK Produksi '.$order->number.' ikut disesuaikan'));

    // 35 + 50 porsi Gorengan; baris lama yang item PO-nya dihapus tidak tertinggal.
    expect((float) $order->fresh()->lines()->where('recipe_id', $data['recipe']->id)->sum('qty'))->toBe(85.0)
        ->and($order->fresh()->lines()->whereNull('purchase_order_item_id')->where('source', 'po')->count())->toBe(0);
});

it('menolak mengubah menu PO bila SPK Produksi-nya sudah ditutup', function () {
    Role::findOrCreate('admin', 'web');
    $data = siapkanProduksi();
    $admin = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $admin->assignRole('admin');
    $order = app(ProductionOrderService::class)->generateFromSpk($data['spk']);
    $order->update(['status' => ProductionOrder::STATUS_COMPLETED]);
    $po = $data['pos'][0]->load('items');

    actingAs($admin)
        ->from(route('adminapp.orders.edit', $po))
        ->put(route('adminapp.orders.update', $po), ubahPoPayload($po, 35))
        ->assertRedirect(route('adminapp.orders.edit', $po))
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Porsi Tambahan'));
    expect((int) $po->items()->sum('qty'))->toBe(50); // 30 + 20 Es Teh, tidak berubah

    // Kontrol positif: perubahan selain menu (nama penerima) tetap boleh.
    actingAs($admin)
        ->put(route('adminapp.orders.update', $po), ubahPoPayload($po, 30, 'Penerima Baru'))
        ->assertRedirect(route('adminapp.orders.show', $po))
        ->assertSessionHas('success');
    expect($po->fresh()->recipient_name)->toBe('Penerima Baru');
});
