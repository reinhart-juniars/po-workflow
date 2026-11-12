<?php

use App\Models\Area;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

/**
 * Kasus 12 Nov 2026: harga Barang Sisa diketik 15.000 lalu langsung klik Submit
 * tanpa Simpan Perubahan. Form submit dulu terpisah dari tabel, jadi ketikannya
 * terbuang dan yang tersubmit harga lama 17.500. Sekarang tombol Submit
 * mengirim form tabel dan isiannya disimpan dulu.
 */
function submitSimpanDuluFixture(): array
{
    Role::findOrCreate('sales', 'web');
    $sales = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $sales->assignRole('sales');

    $area = Area::query()->create(['name' => 'Area Lapak', 'code' => 'LPK']);
    $customer = Customer::query()->create(['name' => 'ARIS LAPAK', 'area_id' => $area->id, 'is_lapak' => true]);
    $dori = Product::query()->create([
        'name' => 'Rice Bowl Dori Cabe Garam', 'sku' => 'DORI', 'unit' => 'porsi', 'base_price' => 17500,
        'raw_material_cost' => 8000, 'overhead_cost' => 2000, 'active' => true,
    ]);

    $draft = SalesActual::query()->create(['customer_id' => $customer->id, 'sales_date' => now()->toDateString(), 'status' => 'draft']);
    $sisa = SalesActualItem::query()->create([
        'sales_actual_id' => $draft->id, 'product_id' => $dori->id, 'item_name' => 'Rice Bowl Dori Cabe Garam', 'unit' => 'porsi',
        'qty_delivery' => 2, 'qty_actual' => 2, 'qty_return' => 0, 'qty_cancel' => 0, 'unit_price' => 17500,
        'raw_material_cost' => 8000, 'overhead_cost' => 2000, 'is_carry_forward' => true,
    ]);

    return compact('sales', 'draft', 'sisa');
}

it('menyimpan harga Barang Sisa yang baru diketik saat langsung klik Submit', function () {
    $f = submitSimpanDuluFixture();
    actingAs($f['sales']);

    // Tombol Submit adalah bagian dari form tabel.
    $this->get(route('salesapp.actuals.edit', $f['draft']))
        ->assertOk()
        ->assertSee('id="sales-actual-form"', false)
        ->assertSee('form="sales-actual-form"', false)
        ->assertSee('formaction="'.route('salesapp.actuals.submit', $f['draft']).'"', false);

    // Yang dikirim browser: isi form tabel (dengan _method=PUT) ke URL submit.
    $this->put(route('salesapp.actuals.submit', $f['draft']), [
        'items' => [$f['sisa']->id => ['qty_actual' => 2, 'qty_waste' => 0, 'unit_price' => 15000]],
        'notes' => null,
    ])->assertSessionHasNoErrors()->assertRedirect(route('salesapp.dashboard'));

    $sisa = $f['sisa']->fresh();
    expect($f['draft']->fresh()->status)->toBe('submitted')
        ->and((float) $sisa->unit_price)->toBe(15000.0)
        ->and((float) $sisa->subtotal_actual)->toBe(30000.0);
});

it('tidak men-submit bila isian tabel tidak sah, dan tetap bisa submit tanpa isian tabel', function () {
    $f = submitSimpanDuluFixture();
    actingAs($f['sales']);

    $this->put(route('salesapp.actuals.submit', $f['draft']), [
        'items' => [$f['sisa']->id => ['qty_actual' => 2, 'unit_price' => -1]],
    ])->assertSessionHasErrors('items.'.$f['sisa']->id.'.unit_price');

    expect($f['draft']->fresh()->status)->toBe('draft')
        ->and((float) $f['sisa']->fresh()->unit_price)->toBe(17500.0);

    // Kontrol positif: submit lama (POST tanpa isian) tetap jalan dengan nilai tersimpan.
    $this->post(route('salesapp.actuals.submit', $f['draft']))->assertSessionHasNoErrors()->assertRedirect(route('salesapp.dashboard'));

    expect($f['draft']->fresh()->status)->toBe('submitted')
        ->and((float) $f['sisa']->fresh()->unit_price)->toBe(17500.0);
});

it('mengoreksi harga Barang Sisa yang terlanjur tersubmit, hanya sebelum closing', function () {
    $f = submitSimpanDuluFixture();
    $f['draft']->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $f['sales']->id])->save();

    // Uji-jalan tidak mengubah apa pun.
    $this->artisan('sales-actual:koreksi-harga-barang-sisa', ['items' => [$f['sisa']->id], '--harga' => 15000])
        ->expectsOutputToContain('Uji-jalan')
        ->assertSuccessful();
    expect((float) $f['sisa']->fresh()->unit_price)->toBe(17500.0);

    $this->artisan('sales-actual:koreksi-harga-barang-sisa', ['items' => [$f['sisa']->id], '--harga' => 15000, '--execute' => true])
        ->assertSuccessful();

    $sisa = $f['sisa']->fresh();
    expect((float) $sisa->unit_price)->toBe(15000.0)
        ->and((float) $sisa->subtotal_actual)->toBe(30000.0)
        ->and(App\Models\AuditLog::query()->where('action', 'sales_actual_item_price_corrected')->value('message'))
        ->toContain('dari Rp 17.500 ke Rp 15.000');
});

it('menolak koreksi harga untuk baris menu PO, Sales Actual draft, atau yang sudah closing', function () {
    $f = submitSimpanDuluFixture();

    // Draft: ubah lewat halaman edit.
    $this->artisan('sales-actual:koreksi-harga-barang-sisa', ['items' => [$f['sisa']->id], '--harga' => 15000])
        ->expectsOutputToContain('belum disubmit')
        ->assertFailed();

    $f['draft']->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();

    // Baris menu biasa: harganya dari PO.
    $menu = SalesActualItem::query()->create([
        'sales_actual_id' => $f['draft']->id, 'item_name' => 'NASI GORENG', 'unit' => 'porsi', 'qty_delivery' => 1,
        'qty_actual' => 1, 'unit_price' => 12000, 'raw_material_cost' => 5000, 'overhead_cost' => 1000, 'is_carry_forward' => false,
    ]);
    $this->artisan('sales-actual:koreksi-harga-barang-sisa', ['items' => [$menu->id], '--harga' => 10000, '--execute' => true])
        ->expectsOutputToContain('bukan Penjualan Barang Sisa')
        ->assertFailed();
    expect((float) $menu->fresh()->unit_price)->toBe(12000.0);

    // Kontrol positif: baris Barang Sisa di SA tersubmit lolos uji-jalan.
    $this->artisan('sales-actual:koreksi-harga-barang-sisa', ['items' => [$f['sisa']->id], '--harga' => 15000])->assertSuccessful();

    // Sudah closing: kas sudah diposting.
    $closing = App\Models\SalesDailyClosing::query()->create([
        'closing_date' => now()->toDateString(), 'cash_in_date' => now()->toDateString(), 'gross_amount' => 0, 'discount_amount' => 0, 'net_amount' => 0, 'posted_at' => now(),
    ]);
    $f['draft']->forceFill(['sales_daily_closing_id' => $closing->id])->saveQuietly();
    $this->artisan('sales-actual:koreksi-harga-barang-sisa', ['items' => [$f['sisa']->id], '--harga' => 15000, '--execute' => true])
        ->expectsOutputToContain('closing harian')
        ->assertFailed();
    expect((float) $f['sisa']->fresh()->unit_price)->toBe(17500.0);
});
