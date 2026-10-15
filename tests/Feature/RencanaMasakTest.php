<?php

use App\Filament\Resources\ProductionOrderResource\Pages\CreateProductionOrder;
use App\Models\Area;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Recipe;
use App\Models\Spk;
use App\Models\User;
use App\Services\ProductionOrderService;
use App\Services\ReadyStockService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Revisi v3.1 -- Rencana Masak (stok siap). Gudang menyusun rencana masak
 * untuk lapak yang pesanannya pasti; admin mendistribusikan porsinya ke PO
 * bertanggal kirim sama. Porsi yang diambil dari stok siap tidak dimasak lagi
 * di SPK Produksi slot, dan tidak boleh melebihi yang disiapkan.
 */
function penggunaRencana(string $role): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

function siapkanRencanaMasak(): array
{
    $recipe = Recipe::query()->create(['name' => 'Nasi Ayam Lapak', 'jenis' => Recipe::JENIS_UTAMA, 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0, 'profit_pct' => 0.25, 'is_active' => true]);
    // Dua varian harga berbagi satu resep: mengambil dari masakan yang sama.
    $ayam12 = Product::query()->create(['name' => 'NASI AYAM 12K', 'unit' => 'porsi', 'base_price' => 12000, 'active' => true]);
    $ayam18 = Product::query()->create(['name' => 'NASI AYAM 18K', 'unit' => 'porsi', 'base_price' => 18000, 'active' => true]);
    $ayam12->forceFill(['recipe_id' => $recipe->id])->saveQuietly();
    $ayam18->forceFill(['recipe_id' => $recipe->id])->saveQuietly();
    $tanpaResep = Product::query()->create(['name' => 'ES TEH', 'unit' => 'cup', 'base_price' => 5000, 'active' => true]);

    $plan = ProductionOrder::query()->create(['title' => 'Rencana lapak Senin', 'is_ready_stock' => true, 'production_date' => '2026-10-20']);
    $plan->lines()->create(['kind' => ProductionOrderLine::KIND_MENU, 'source' => ProductionOrderLine::SOURCE_MANUAL, 'recipe_id' => $recipe->id, 'label' => 'Nasi Ayam Lapak', 'qty' => 20, 'unit' => 'porsi', 'sort_order' => 1]);

    $area = Area::query()->create(['name' => 'Area Lapak', 'code' => 'RML']);
    $lapak = Customer::query()->create(['name' => 'KUTISARI LAPAK', 'area_id' => $area->id, 'active' => true, 'is_lapak' => true]);
    $cash = CashAccount::query()->create(['name' => 'Kas', 'type' => 'cash', 'is_active' => true]);

    $form = fn (array $items, string $date = '2026-10-20') => [
        'customer_id' => $lapak->id, 'recipient_name' => 'Lapak', 'shipping_address' => 'Kutisari',
        'area_id' => $area->id, 'delivery_date' => $date, 'delivery_time' => '06:00',
        'payment_type' => 'cash', 'cash_account_id' => $cash->id, 'shipping_cost' => 0,
        'items' => $items,
    ];

    return compact('recipe', 'ayam12', 'ayam18', 'tanpaResep', 'plan', 'form');
}

it('membiarkan gudang menyusun Rencana Masak tetapi tidak mengubah SPK Produksi slot', function () {
    $gudang = penggunaRencana('inventory');
    $slot = ProductionOrder::query()->create(['title' => 'SPK slot', 'production_date' => '2026-10-20']);
    $rencana = ProductionOrder::query()->create(['title' => 'Rencana', 'is_ready_stock' => true, 'production_date' => '2026-10-20']);

    expect($gudang->can('update', $rencana))->toBeTrue()
        ->and($gudang->can('update', $slot))->toBeFalse()
        // Kontrol positif: produksi tetap memegang SPK slot.
        ->and(penggunaRencana('production')->can('update', $slot))->toBeTrue();

    // Toggle dimatikan lewat request pun tetap tersimpan sebagai Rencana Masak.
    $this->actingAs($gudang);
    Livewire::test(CreateProductionOrder::class)
        ->fillForm(['title' => 'Rencana Selasa', 'production_date' => '2026-10-21', 'is_ready_stock' => false])
        ->call('create')
        ->assertHasNoFormErrors();
    expect(ProductionOrder::query()->where('title', 'Rencana Selasa')->sole()->is_ready_stock)->toBeTrue();
});

it('mendistribusikan stok siap ke PO dan menolak yang melebihi sisa', function () {
    ['ayam12' => $ayam12, 'ayam18' => $ayam18, 'form' => $form] = siapkanRencanaMasak();
    $admin = penggunaRencana('admin');

    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form([
        ['product_id' => $ayam12->id, 'qty' => 15, 'from_ready_stock' => 1],
    ]))->assertSessionHasNoErrors();

    // Varian 18K mengambil dari resep yang sama: tinggal 5.
    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form([
        ['product_id' => $ayam18->id, 'qty' => 10, 'from_ready_stock' => 1],
    ]))->assertSessionHasErrors(['items.0.from_ready_stock' => 'Stok siap Nasi Ayam Lapak tanggal 20 Okt 2026 tinggal 5 porsi, diminta 10. Kurangi qty atau hapus centang "Ambil dari stok siap".']);
    expect(PurchaseOrder::query()->count())->toBe(1);

    // Kontrol positif: tepat sisa lolos; PO biasa (tanpa centang) tidak dibatasi stok siap.
    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form([
        ['product_id' => $ayam18->id, 'qty' => 5, 'from_ready_stock' => 1],
        ['product_id' => $ayam18->id, 'qty' => 50],
    ]))->assertSessionHasNoErrors();
    expect(PurchaseOrder::query()->count())->toBe(2);

    expect(app(ReadyStockService::class)->summary(Carbon::parse('2026-10-19'), Carbon::parse('2026-10-21'))->all())->toBe([[
        'date' => '2026-10-20', 'recipe_id' => $ayam12->recipe_id, 'recipe' => 'Nasi Ayam Lapak',
        'planned' => 20.0, 'distributed' => 20.0, 'remaining' => 0.0,
    ]]);
});

it('hanya mengambil dari rencana masak bertanggal sama dan menolak menu tanpa resep', function () {
    ['ayam12' => $ayam12, 'tanpaResep' => $teh, 'form' => $form] = siapkanRencanaMasak();
    $admin = penggunaRencana('admin');

    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form([
        ['product_id' => $ayam12->id, 'qty' => 1, 'from_ready_stock' => 1],
    ], '2026-10-21'))->assertSessionHasErrors('items.0.from_ready_stock');

    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form([
        ['product_id' => $teh->id, 'qty' => 1, 'from_ready_stock' => 1],
    ]))->assertSessionHasErrors(['items.0.from_ready_stock' => 'ES TEH belum punya resep, jadi tidak bisa diambil dari stok siap.']);

    expect(PurchaseOrder::query()->count())->toBe(0);
});

it('menghitung ulang kuota saat PO diubah dan membebaskan porsi PO yang dibatalkan', function () {
    ['ayam12' => $ayam12, 'form' => $form] = siapkanRencanaMasak();
    $admin = penggunaRencana('admin');

    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form([
        ['product_id' => $ayam12->id, 'qty' => 15, 'from_ready_stock' => 1],
    ]));
    $po = PurchaseOrder::query()->sole();
    $ubah = fn (int $qty) => $form([['product_id' => $ayam12->id, 'qty' => $qty, 'from_ready_stock' => 1]]) + ['edit_reason' => 'Uji ubah'];

    // Porsi PO ini sendiri tidak dihitung dua kali: 20 masih muat, 21 tidak.
    $this->actingAs($admin)->put(route('adminapp.orders.update', $po), $ubah(21))->assertSessionHasErrors('items.0.from_ready_stock');
    $this->actingAs($admin)->put(route('adminapp.orders.update', $po), $ubah(20))->assertSessionHasNoErrors();
    expect((int) $po->fresh()->items()->sole()->qty)->toBe(20);

    $po->update(['status' => 'cancelled']);
    expect(app(ReadyStockService::class)->remaining(Carbon::parse('2026-10-20'))->first())->toBe(20.0);
});

it('tidak memasak ulang item stok siap di SPK Produksi slot', function () {
    ['ayam12' => $ayam12, 'ayam18' => $ayam18, 'form' => $form] = siapkanRencanaMasak();
    $admin = penggunaRencana('admin');

    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form([
        ['product_id' => $ayam12->id, 'qty' => 10, 'from_ready_stock' => 1],
        ['product_id' => $ayam18->id, 'qty' => 7],
    ]));
    $po = PurchaseOrder::query()->sole();
    $spk = Spk::query()->create(['scheduled_at' => '2026-10-20 06:00:00', 'slot_type' => 'fixed_07', 'status' => 'draft', 'responsible_user_id' => $admin->id]);
    $spk->purchaseOrders()->attach($po->id);

    $order = app(ProductionOrderService::class)->generateFromSpk($spk, $admin->id);

    expect($order->lines->map(fn ($line) => [$line->label, (float) $line->qty])->all())->toBe([['NASI AYAM 18K', 7.0]])
        ->and(PurchaseOrderItem::query()->where('from_ready_stock', true)->sum('qty'))->toEqual(10);
});

it('menampilkan stok siap, rencana belanja, dan pesanan PO di halaman Rencana Masak untuk gudang dan admin', function () {
    ['ayam12' => $ayam12, 'form' => $form] = siapkanRencanaMasak();
    $admin = penggunaRencana('admin');
    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form([
        ['product_id' => $ayam12->id, 'qty' => 15, 'from_ready_stock' => 1],
        ['product_id' => $ayam12->id, 'qty' => 4],
    ]));

    $this->travelTo(Carbon::parse('2026-10-18 08:00'));

    foreach (['inventory', 'admin'] as $role) {
        $this->actingAs(penggunaRencana($role))->get(route('filament.admin.pages.rencana-masak'))
            ->assertOk()
            ->assertSee('Stok siap per menu')
            ->assertSee('Nasi Ayam Lapak')
            ->assertSee('NASI AYAM 12K')
            ->assertSee('Rencana belanja');
    }

    // Peran tanpa akses produksi tidak membuka halaman ini.
    $this->actingAs(penggunaRencana('sales'))->get(route('filament.admin.pages.rencana-masak'))->assertForbidden();
});

it('memberi sisa stok siap per produk ke form PO', function () {
    ['ayam12' => $ayam12, 'ayam18' => $ayam18, 'form' => $form] = siapkanRencanaMasak();
    $admin = penggunaRencana('admin');
    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form([
        ['product_id' => $ayam12->id, 'qty' => 6, 'from_ready_stock' => 1],
    ]));
    $po = PurchaseOrder::query()->sole();

    $this->actingAs($admin)->getJson(route('adminapp.ready-stock.available', ['date' => '2026-10-20']))
        ->assertOk()
        ->assertExactJson(['products' => [(string) $ayam12->id => 14, (string) $ayam18->id => 14]]);

    // Saat mengubah PO itu sendiri, porsinya tidak dihitung terpakai.
    $this->actingAs($admin)->getJson(route('adminapp.ready-stock.available', ['date' => '2026-10-20', 'po' => $po->id]))
        ->assertJsonPath('products.'.$ayam12->id, 20);

    $this->actingAs(penggunaRencana('sales'))->getJson(route('adminapp.ready-stock.available', ['date' => '2026-10-20']))->assertForbidden();
});

/** Rencana 20 porsi; resep Rp 2.000/porsi (beras 100 g x Rp 20). */
function siapkanPenutupan(): array
{
    $data = siapkanRencanaMasak();
    $beras = \App\Models\InventoryItem::query()->create(['name' => 'Beras', 'unit' => 'gram', 'category' => \App\Models\InventoryItem::CATEGORY_RAW_MATERIAL, 'unit_price' => 20, 'is_active' => true]);
    \App\Models\RecipeItem::query()->create(['recipe_id' => $data['recipe']->id, 'inventory_item_id' => $beras->id, 'raw_name' => 'beras', 'qty' => 100, 'unit' => 'gram']);
    app(\App\Support\Settings\Settings::class)->set('leftover.accounting_start', '2026-10-01');

    return $data;
}

it('menutup stok siap hanya setelah Rencana Masak ditutup, lalu sisanya menjadi Barang Sisa bernilai HPP', function () {
    ['ayam12' => $ayam12, 'plan' => $plan, 'form' => $form] = siapkanPenutupan();
    $admin = penggunaRencana('admin');
    $gudang = penggunaRencana('inventory');
    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form([
        ['product_id' => $ayam12->id, 'qty' => 15, 'from_ready_stock' => 1],
    ]));
    $this->travelTo(Carbon::parse('2026-10-20 20:00'));
    $service = app(ReadyStockService::class);

    // SPK Rencana Masak belum ditutup: bahannya belum dibebankan, jadi sisa belum boleh jadi persediaan.
    expect(fn () => $service->closeDay(Carbon::parse('2026-10-20'), $gudang))
        ->toThrow(\Illuminate\Validation\ValidationException::class, 'Tutup dulu SPK Rencana Masak '.$plan->number);
    expect(\App\Models\ReadyStockClosing::query()->count())->toBe(0);

    // Kontrol positif: setelah ditutup produksi, penutupan berhasil.
    $plan->forceFill(['status' => ProductionOrder::STATUS_COMPLETED, 'completed_at' => now()])->save();
    $closing = $service->closeDay(Carbon::parse('2026-10-20'), $gudang);

    expect((float) $closing->leftover_qty)->toBe(5.0)
        ->and((float) $closing->leftover_value)->toBe(10000.0);

    $leftovers = app(\App\Services\LeftoverStockService::class);
    $sisa = $leftovers->available()->sole();
    expect([$sisa['item_name'], $sisa['available_qty'], $sisa['unit_cost'], $sisa['customer_name']])
        ->toBe(['Nasi Ayam Lapak', 5.0, 2000.0, \App\Models\Customer::INTERNAL_READY_STOCK])
        // Persediaan Barang Sisa di Neraca & pengurang HPP Laba Rugi.
        ->and($leftovers->valueAt(Carbon::parse('2026-10-20')))->toBe(10000.0)
        ->and($leftovers->valueAt(Carbon::parse('2026-10-19')))->toBe(0.0);

    // Tidak bisa ditutup dua kali.
    expect(fn () => $service->closeDay(Carbon::parse('2026-10-20'), $gudang))->toThrow(\Illuminate\Validation\ValidationException::class, 'sudah ditutup');
});

it('mengunci distribusi tanggal yang sudah ditutup, tanpa memblokir koreksi PO lama', function () {
    ['ayam12' => $ayam12, 'plan' => $plan, 'form' => $form] = siapkanPenutupan();
    $admin = penggunaRencana('admin');
    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form([
        ['product_id' => $ayam12->id, 'qty' => 15, 'from_ready_stock' => 1],
    ]));
    $po = PurchaseOrder::query()->sole();
    $plan->forceFill(['status' => ProductionOrder::STATUS_COMPLETED])->save();
    $this->travelTo(Carbon::parse('2026-10-20 20:00'));
    app(ReadyStockService::class)->closeDay(Carbon::parse('2026-10-20'), $admin);

    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form([
        ['product_id' => $ayam12->id, 'qty' => 1, 'from_ready_stock' => 1],
    ]))->assertSessionHasErrors(['items.0.from_ready_stock' => 'Stok siap tanggal 20 Okt 2026 sudah ditutup; sisa Nasi Ayam Lapak sudah menjadi Barang Sisa. Jual dari halaman Barang Sisa, atau hapus centang "Ambil dari stok siap".']);

    // PO lama boleh dikoreksi selama tidak mengambil lebih banyak.
    $ubah = fn (int $qty) => $form([['product_id' => $ayam12->id, 'qty' => $qty, 'from_ready_stock' => 1]]) + ['edit_reason' => 'Koreksi'];
    $this->actingAs($admin)->put(route('adminapp.orders.update', $po), $ubah(14))->assertSessionHasNoErrors();
    $this->actingAs($admin)->put(route('adminapp.orders.update', $po), $ubah(16))->assertSessionHasErrors('items.0.from_ready_stock');
});

it('tidak menampilkan Sales Actual dan customer internal di daftar penjualan & customer', function () {
    ['plan' => $plan] = siapkanPenutupan();
    $admin = penggunaRencana('admin');
    $plan->forceFill(['status' => ProductionOrder::STATUS_COMPLETED])->save();
    $this->travelTo(Carbon::parse('2026-10-20 20:00'));
    app(ReadyStockService::class)->closeDay(Carbon::parse('2026-10-20'), $admin);
    expect(\App\Models\SalesActual::query()->where('is_internal', true)->count())->toBe(1);

    $sales = penggunaRencana('sales');
    $this->actingAs($sales)->get(route('salesapp.dashboard', ['date_from' => '2026-10-20', 'date_to' => '2026-10-20', 'status' => 'all']))
        ->assertOk()->assertDontSee(\App\Models\Customer::INTERNAL_READY_STOCK);
    $this->actingAs($admin)->get(route('adminapp.customers.index'))
        ->assertOk()->assertDontSee(\App\Models\Customer::INTERNAL_READY_STOCK)->assertSee('KUTISARI LAPAK');

    // Kontrol positif: Barang Sisa-nya tetap terlihat di halaman Barang Sisa.
    $this->actingAs($sales)->get(route('salesapp.leftovers.index'))->assertOk()->assertSee('Nasi Ayam Lapak');
});

it('menampilkan Rencana Masak hari ini beserta KPI-nya di Dashboard Admin', function () {
    ['ayam12' => $ayam12, 'form' => $form] = siapkanRencanaMasak();
    $admin = penggunaRencana('admin');
    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form([
        ['product_id' => $ayam12->id, 'qty' => 15, 'from_ready_stock' => 1],
    ]));

    $this->travelTo(Carbon::parse('2026-10-20 07:00'));
    $this->actingAs($admin)->get(route('adminapp.dashboard'))
        ->assertOk()
        ->assertSee('Rencana Masak Hari Ini')
        ->assertSeeInOrder(['disiapkan 20', 'terdistribusi 15', 'sisa 5 porsi'])
        ->assertSee('Nasi Ayam Lapak');

    // Hari lain: rencana tanggal 20 tidak tampil.
    $this->travelTo(Carbon::parse('2026-10-21 07:00'));
    $this->actingAs($admin)->get(route('adminapp.dashboard'))
        ->assertOk()
        ->assertSee('Belum ada Rencana Masak untuk hari ini.')
        ->assertDontSee('Nasi Ayam Lapak');
});
