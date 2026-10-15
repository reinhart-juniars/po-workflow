<?php

use App\Models\Area;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Revisi v3.1: menu punya harga normal dan harga lapak. Admin memilih per
 * item PO; customer lapak otomatis memakai harga lapak, dan menu tanpa harga
 * lapak tetap memakai harga normal. Harga selalu dihitung server dari Master
 * Menu, bukan dari angka yang dikirim form.
 */
function siapkanHargaLapak(bool $customerLapak = false): array
{
    $area = Area::query()->create(['name' => 'Area Lapak', 'code' => 'ALP']);
    $customer = Customer::query()->create(['name' => $customerLapak ? 'KUTISARI LAPAK' : 'Bu Rina', 'area_id' => $area->id, 'active' => true, 'is_lapak' => $customerLapak]);
    $cash = CashAccount::query()->create(['name' => 'Kas', 'type' => 'cash', 'is_active' => true]);
    $nasi = Product::query()->create(['name' => 'NASI AYAM 15K', 'unit' => 'porsi', 'base_price' => 15000, 'lapak_price' => 12000, 'active' => true]);
    $teh = Product::query()->create(['name' => 'ES TEH', 'unit' => 'cup', 'base_price' => 5000, 'active' => true]); // tanpa harga lapak

    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $admin->assignRole('admin');

    $form = fn (array $items) => [
        'customer_id' => $customer->id, 'recipient_name' => 'Penerima', 'shipping_address' => 'Jl. Mawar 1',
        'area_id' => $area->id, 'delivery_date' => '2026-10-20', 'delivery_time' => '09:00',
        'payment_type' => 'cash', 'cash_account_id' => $cash->id, 'shipping_cost' => 0,
        'items' => $items,
    ];

    return compact('admin', 'customer', 'nasi', 'teh', 'form');
}

it('memakai harga lapak per item sesuai pilihan admin', function () {
    ['admin' => $admin, 'nasi' => $nasi, 'teh' => $teh, 'form' => $form] = siapkanHargaLapak();

    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form([
        ['product_id' => $nasi->id, 'qty' => 10, 'price_tier' => 'lapak'],
        ['product_id' => $nasi->id, 'qty' => 2, 'price_tier' => 'normal'],
        // Harga yang dikirim form diabaikan: server mengambil dari Master Menu.
        ['product_id' => $teh->id, 'qty' => 3, 'price_tier' => 'normal', 'unit_price' => 1],
    ]))->assertRedirect(route('adminapp.orders.index'));

    $items = PurchaseOrder::query()->sole()->items()->orderBy('id')->get();
    expect($items->map(fn ($i) => [$i->price_tier, (float) $i->unit_price, (float) $i->subtotal])->all())->toBe([
        ['lapak', 12000.0, 120000.0],
        ['normal', 15000.0, 30000.0],
        ['normal', 5000.0, 15000.0],
    ])->and((float) PurchaseOrder::query()->sole()->total_amount)->toBe(165000.0);
});

it('otomatis memakai harga lapak untuk customer lapak, dan harga normal untuk menu tanpa harga lapak', function () {
    ['admin' => $admin, 'nasi' => $nasi, 'teh' => $teh, 'form' => $form] = siapkanHargaLapak(customerLapak: true);

    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form([
        ['product_id' => $nasi->id, 'qty' => 10],
        ['product_id' => $teh->id, 'qty' => 2, 'price_tier' => 'lapak'],
    ]))->assertRedirect();

    $items = PurchaseOrder::query()->sole()->items()->orderBy('id')->get();
    expect([$items[0]->price_tier, (float) $items[0]->unit_price])->toBe(['lapak', 12000.0])
        // Es teh tidak punya harga lapak: tercatat normal, bukan "lapak" dengan harga normal.
        ->and([$items[1]->price_tier, (float) $items[1]->unit_price])->toBe(['normal', 5000.0]);
});

it('menolak tingkat harga yang tidak dikenal', function () {
    ['admin' => $admin, 'nasi' => $nasi, 'form' => $form] = siapkanHargaLapak();

    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form([
        ['product_id' => $nasi->id, 'qty' => 1, 'price_tier' => 'grosir'],
    ]))->assertSessionHasErrors('items.0.price_tier');
    expect(PurchaseOrder::query()->count())->toBe(0);

    // Kontrol positif: form yang sama dengan tingkat sah tersimpan.
    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form([
        ['product_id' => $nasi->id, 'qty' => 1, 'price_tier' => 'lapak'],
    ]))->assertSessionHasNoErrors();
    expect(PurchaseOrder::query()->count())->toBe(1);
});

it('mempertahankan harga lama saat PO diubah, kecuali tingkat harganya diganti', function () {
    ['admin' => $admin, 'nasi' => $nasi, 'form' => $form] = siapkanHargaLapak();

    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form([
        ['product_id' => $nasi->id, 'qty' => 10, 'price_tier' => 'lapak'],
    ]));
    $po = PurchaseOrder::query()->sole();

    // Harga master naik setelah PO dibuat.
    $nasi->update(['base_price' => 16000, 'lapak_price' => 13000]);

    $ubah = fn (string $tier) => $form([['product_id' => $nasi->id, 'qty' => 10, 'price_tier' => $tier]]) + ['edit_reason' => 'Uji ubah'];

    // Tingkat sama: harga lama dipertahankan.
    $this->actingAs($admin)->put(route('adminapp.orders.update', $po), $ubah('lapak'))->assertSessionHasNoErrors();
    expect((float) $po->fresh()->items()->sole()->unit_price)->toBe(12000.0);

    // Ganti ke normal: harga normal terbaru.
    $this->actingAs($admin)->put(route('adminapp.orders.update', $po), $ubah('normal'))->assertSessionHasNoErrors();
    $item = $po->fresh()->items()->sole();
    expect([$item->price_tier, (float) $item->unit_price])->toBe(['normal', 16000.0]);
});

it('menyimpan harga lapak dari Master Menu dan Excel', function () {
    ['admin' => $admin, 'nasi' => $nasi] = siapkanHargaLapak();

    $this->actingAs($admin)->put(route('adminapp.products.update', $nasi), [
        'name' => $nasi->name, 'sku' => 'Nasi Ayam', 'unit' => 'porsi', 'base_price' => 15000, 'lapak_price' => 11500, 'active' => 1,
    ])->assertSessionHasNoErrors();
    expect((float) $nasi->fresh()->lapak_price)->toBe(11500.0);

    // Dikosongkan = tidak punya harga lapak lagi.
    $this->actingAs($admin)->put(route('adminapp.products.update', $nasi), [
        'name' => $nasi->name, 'sku' => 'Nasi Ayam', 'unit' => 'porsi', 'base_price' => 15000, 'lapak_price' => '', 'active' => 1,
    ])->assertSessionHasNoErrors();
    expect($nasi->fresh()->lapak_price)->toBeNull()
        ->and($nasi->fresh()->priceForTier(PurchaseOrderItem::TIER_LAPAK))->toBe(15000.0);
});
