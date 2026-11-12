<?php

use App\Models\Area;
use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use App\Models\User;
use App\Services\LeftoverStockService;

/**
 * Kasus 12 Nov 2026: Barang Sisa Rice Bowl Dori 11 Nov dijual ke lapak yang
 * masih punya draft V2 (Mei), jadi penjualannya bertanggal Mei dan draft V2
 * itu ikut tersubmit. Perintah sales-actual:pindah-barang-sisa memindahkannya
 * ke tanggal yang benar dan mengembalikan draft V2 seperti semula.
 */
function sisaSalahTempatFixture(): array
{
    test()->travelTo(Carbon\Carbon::parse('2026-11-12 10:30'));

    $user = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $area = Area::query()->create(['name' => 'Area Lapak', 'code' => 'LPK']);
    $dapur = Customer::query()->create(['name' => 'Stok Siap Dapur', 'area_id' => $area->id, 'is_internal' => true]);
    $kutisari = Customer::query()->create(['name' => 'KUTISARI LAPAK', 'area_id' => $area->id, 'is_lapak' => true]);
    $faris = Customer::query()->create(['name' => 'FARIS LAPAK', 'area_id' => $area->id, 'is_lapak' => true]);
    $kas = CashAccount::query()->create(['name' => 'Kas Lapak', 'type' => 'cash', 'is_active' => true]);
    $dori = Product::query()->create([
        'name' => 'Rice Bowl Dori Cabe Garam', 'sku' => 'DORI', 'unit' => 'porsi', 'base_price' => 17500,
        'raw_material_cost' => 8000, 'overhead_cost' => 2000, 'active' => true,
    ]);

    // Sisa 11 Nov: 7 porsi dikirim ke Stok Siap Dapur, tidak ada yang terjual.
    $asal = SalesActual::query()->create([
        'customer_id' => $dapur->id, 'sales_date' => '2026-11-11', 'status' => 'submitted',
        'is_internal' => true, 'submitted_at' => '2026-11-11 20:00', 'submitted_by' => $user->id,
    ]);
    $entry = SalesActualItem::query()->create([
        'sales_actual_id' => $asal->id, 'product_id' => $dori->id, 'item_name' => 'Rice Bowl Dori Cabe Garam',
        'unit' => 'porsi', 'qty_delivery' => 7, 'qty_actual' => 0, 'unit_price' => 17500,
        'raw_material_cost' => 8000, 'overhead_cost' => 2000,
    ]);

    // Draft V2 yang tidak pernah disubmit, berisi menu lama.
    $mei = SalesActual::query()->create(['customer_id' => $kutisari->id, 'sales_date' => '2026-05-14', 'status' => 'draft']);
    $menuV2 = SalesActualItem::query()->create([
        'sales_actual_id' => $mei->id, 'item_name' => 'NASI UDANG CRISPY 12K', 'unit' => 'porsi',
        'qty_delivery' => 4, 'qty_actual' => 4, 'unit_price' => 12000, 'raw_material_cost' => 5000, 'overhead_cost' => 1000,
    ]);
    $april = SalesActual::query()->create(['customer_id' => $faris->id, 'sales_date' => '2026-04-30', 'status' => 'draft']);
    $poApril = PurchaseOrder::query()->create([
        'po_number' => 'PO-APRIL', 'customer_id' => $faris->id, 'recipient_name' => 'Faris', 'shipping_address' => 'Lapak',
        'area_id' => $area->id, 'delivery_date' => '2026-04-30', 'delivery_time' => '07:00:00', 'payment_type' => 'receivable',
        'status' => 'completed', 'completed_at' => '2026-04-30 12:00', 'created_by' => $user->id, 'updated_by' => $user->id,
    ]);

    // Penjualan Barang Sisa yang salah tempat, lalu draft V2 tersubmit hari ini.
    $leftovers = app(LeftoverStockService::class);
    $tunai = $leftovers->addToSalesActual($mei, $entry, 2, 17500, $kas->id);
    $ikutPo = $leftovers->addToSalesActual($april, $entry, 2, 17500, $kas->id);
    $ikutPo->forceFill(['cash_account_id' => null, 'purchase_order_id' => $poApril->id])->save();

    foreach ([$mei, $april] as $sa) {
        $sa->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $user->id])->save();
    }

    return compact('entry', 'mei', 'april', 'menuV2', 'tunai', 'ikutPo', 'kas', 'kutisari', 'faris', 'poApril');
}

it('hanya menampilkan rencana tanpa --execute', function () {
    $f = sisaSalahTempatFixture();

    $this->artisan('sales-actual:pindah-barang-sisa', ['items' => [$f['tunai']->id, $f['ikutPo']->id], '--cash-account' => $f['kas']->id])
        ->expectsOutputToContain('Uji-jalan')
        ->assertSuccessful();

    expect($f['tunai']->fresh()->sales_actual_id)->toBe($f['mei']->id)
        ->and($f['mei']->fresh()->status)->toBe('submitted')
        ->and(AuditLog::query()->where('action', 'sales_actual_leftover_moved')->exists())->toBeFalse();
});

it('memindahkan Barang Sisa ke tanggal yang benar dan mengembalikan draft V2 seperti semula', function () {
    $f = sisaSalahTempatFixture();
    $sisaSebelum = app(LeftoverStockService::class)->availableQty($f['entry']);

    $this->artisan('sales-actual:pindah-barang-sisa', [
        'items' => [$f['tunai']->id, $f['ikutPo']->id], '--cash-account' => $f['kas']->id, '--execute' => true,
    ])->assertSuccessful();

    $tunai = $f['tunai']->fresh('salesActual');
    $ikutPo = $f['ikutPo']->fresh('salesActual');

    // Masuk draft baru per customer, bertanggal hari ini, tanpa DO.
    expect($tunai->salesActual)->toMatchArray(['customer_id' => $f['kutisari']->id, 'status' => 'draft', 'delivery_order_id' => null])
        ->and($tunai->salesActual->sales_date->toDateString())->toBe('2026-11-12')
        ->and($tunai->cash_account_id)->toBe($f['kas']->id)
        ->and($ikutPo->salesActual->customer_id)->toBe($f['faris']->id)
        ->and($ikutPo->salesActual->sales_date->toDateString())->toBe('2026-11-12')
        // PO April tidak boleh menagih penjualan November: dibayar Tunai.
        ->and($ikutPo->purchase_order_id)->toBeNull()
        ->and($ikutPo->cash_account_id)->toBe($f['kas']->id)
        // Nilai penjualan tidak berubah.
        ->and((float) $tunai->subtotal_actual)->toBe(35000.0);

    // Draft V2 kembali seperti di V2, menu lamanya tetap di sana.
    foreach (['mei', 'april'] as $key) {
        expect($f[$key]->fresh())->toMatchArray(['status' => 'draft', 'submitted_at' => null, 'submitted_by' => null]);
    }
    expect($f['menuV2']->fresh()->sales_actual_id)->toBe($f['mei']->id)
        ->and(SalesActualItem::query()->where('sales_actual_id', $f['mei']->id)->count())->toBe(1);

    // Stok Barang Sisa tidak bertambah atau berkurang karena pemindahan.
    expect(app(LeftoverStockService::class)->availableQty($f['entry']))->toBe($sisaSebelum);

    // Jejak untuk accounting.
    expect(AuditLog::query()->where('action', 'sales_actual_leftover_moved')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'sales_actual_reopened')->pluck('entity_id')->sort()->values()->all())
        ->toBe(collect([$f['mei']->id, $f['april']->id])->sort()->values()->all())
        ->and(AuditLog::query()->where('action', 'sales_actual_leftover_moved')->where('entity_id', $tunai->sales_actual_id)->value('message'))
        ->toContain('Rice Bowl Dori Cabe Garam')->toContain('14/05/2026')->toContain('12/11/2026');
});

it('menolak baris yang ikut PO lama tanpa akun kas, tanpa mengubah apa pun', function () {
    $f = sisaSalahTempatFixture();

    $this->artisan('sales-actual:pindah-barang-sisa', ['items' => [$f['tunai']->id, $f['ikutPo']->id], '--execute' => true])
        ->expectsOutputToContain('--cash-account')
        ->assertFailed();

    expect($f['tunai']->fresh()->sales_actual_id)->toBe($f['mei']->id)
        ->and($f['mei']->fresh()->status)->toBe('submitted');

    // Kontrol positif: baris tunai saja boleh dipindah tanpa akun kas.
    $this->artisan('sales-actual:pindah-barang-sisa', ['items' => [$f['tunai']->id], '--execute' => true])->assertSuccessful();

    expect($f['tunai']->fresh()->salesActual->sales_date->toDateString())->toBe('2026-11-12');
});

it('menolak baris yang tidak salah tempat atau Sales Actual yang sudah closing', function () {
    $f = sisaSalahTempatFixture();

    // Kontrol positif: baris salah tempat lolos uji-jalan.
    $this->artisan('sales-actual:pindah-barang-sisa', ['items' => [$f['tunai']->id]])->assertSuccessful();

    // Penjualan biasa (bukan Barang Sisa) ditolak.
    $this->artisan('sales-actual:pindah-barang-sisa', ['items' => [$f['menuV2']->id]])
        ->expectsOutputToContain('bukan Penjualan Barang Sisa')
        ->assertFailed();

    // Sudah di tanggal yang benar: tidak ada yang perlu dipindah.
    $this->artisan('sales-actual:pindah-barang-sisa', ['items' => [$f['tunai']->id], '--execute' => true])->assertSuccessful();
    $this->artisan('sales-actual:pindah-barang-sisa', ['items' => [$f['tunai']->id]])
        ->expectsOutputToContain('tidak sebelum tanggal sisanya')
        ->assertFailed();

    // Sales Actual yang sudah masuk closing harian tidak disentuh.
    $closing = App\Models\SalesDailyClosing::query()->create([
        'closing_date' => '2026-04-30', 'cash_in_date' => '2026-04-30', 'gross_amount' => 0, 'discount_amount' => 0, 'net_amount' => 0, 'posted_at' => now(),
    ]);
    $f['april']->forceFill(['sales_daily_closing_id' => $closing->id])->saveQuietly();
    $this->artisan('sales-actual:pindah-barang-sisa', ['items' => [$f['ikutPo']->id], '--cash-account' => $f['kas']->id])
        ->expectsOutputToContain('closing harian')
        ->assertFailed();
});
