<?php

use App\Filament\Resources\ProductionOrderResource\Pages\RequisitionForm;
use App\Models\CashOut;
use App\Models\InventoryItemPriceHistory;
use App\Models\InventoryMovement;
use App\Models\InventoryPurchase;
use App\Models\Payable;
use App\Models\RequisitionLine;
use App\Models\User;
use App\Services\InventoryLedgerService;
use App\Services\ProductionOrderService;
use App\Services\RequisitionService;
use App\Support\Settings\Settings;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Penerimaan barang di Form Kebutuhan: karyawan yang menerima barang mengisi
 * Diterima, alasan & perlakuan yang ditolak, harga beli dari nota, dan cara
 * pembayaran -- lalu Periksa mencatat stok (kartu stok), pembelian bahan baku,
 * dan kas keluar / hutang sekaligus. Sebelum ini nota harus diinput dua kali
 * dan barang ditolak tidak berjejak.
 */
function formDisetujui(): array
{
    $d = siapkanProduksi();
    $order = app(ProductionOrderService::class)->generateFromSpk($d['spk']);
    $service = app(RequisitionService::class);
    $requisition = $service->build($order)['requisition'];

    // Tepung: kebutuhan 2 kg, stok 0 -> beli 2. Minyak: stok cukup, tidak beli.
    $service->fillOpeningStock($requisition->lines->firstWhere('inventory_item_id', $d['tepung']->id), 0);
    $service->fillOpeningStock($requisition->lines->firstWhere('inventory_item_id', $d['minyak']->id), 5);
    $service->approve($requisition->fresh());

    return $d + ['order' => $order, 'requisition' => $requisition->fresh(), 'service' => $service];
}

it('mencatat penerimaan: diterima masuk stok dengan harga beli, ditolak-retur tidak dibayar, pembelian & kas keluar dibuat otomatis', function () {
    $d = formDisetujui();
    $tepung = $d['requisition']->lines->firstWhere('inventory_item_id', $d['tepung']->id);

    // Beli 2 kg, datang 1,8 kg layak; 0,2 kg busuk dikembalikan. Nota Rp 12.500/kg (master 12.000).
    $d['service']->recordReceipt($tepung, 1.8, 'busuk', RequisitionLine::REJECT_RETURN, 12500);
    $bayar = bayarTunai($d['requisition']->fresh(), 'Pak Udin');
    $d['service']->check($d['requisition']->fresh());

    $tepung = $tepung->fresh();
    expect((float) $tepung->rejected_qty)->toBe(0.2)
        ->and(app(InventoryLedgerService::class)->balance($d['tepung']->id))->toBe(1.8)
        ->and((float) InventoryMovement::query()->ofType(InventoryMovement::TYPE_PURCHASE)->where('inventory_item_id', $d['tepung']->id)->value('unit_price'))->toBe(12500.0);

    // Satu pembelian Baik 1,8 kg x 12.500 = 22.500, tunai dari Kas Tes, tertaut ke form; tidak ada pembelian rusak.
    $purchases = InventoryPurchase::query()->get();
    expect($purchases)->toHaveCount(1);
    $beli = $purchases->first();
    expect($beli->condition)->toBe(InventoryPurchase::CONDITION_GOOD)
        ->and((float) $beli->qty)->toBe(1.8)
        ->and((float) $beli->total_value)->toBe(22500.0)
        ->and($beli->requisition_id)->toBe($d['requisition']->id)
        ->and($beli->supplier_name)->toBe('Pak Udin')
        ->and($tepung->inventory_purchase_id)->toBe($beli->id)
        ->and($tepung->damaged_purchase_id)->toBeNull();

    // Uang keluar = pembelian, dari akun kas yang dipilih.
    expect(CashOut::query()->count())->toBe(1)
        ->and((float) CashOut::query()->sum('amount'))->toBe((float) InventoryPurchase::query()->sum('total_value'))
        ->and(CashOut::query()->first()->cash_account_id)->toBe($bayar['cash_account']->id)
        ->and(Payable::query()->count())->toBe(0);

    // Harga master ikut nota dan berjejak di histori harga dengan sumber nomor form.
    expect((float) $d['tepung']->fresh()->unit_price)->toBe(12500.0)
        ->and(InventoryItemPriceHistory::query()->where('inventory_item_id', $d['tepung']->id)->latest('id')->value('source'))->toBe('Form '.$d['requisition']->number)
        // Kolom source di MySQL 60 karakter (SQLite tes tidak menegakkan panjang).
        ->and(strlen('Form '.$d['requisition']->number))->toBeLessThanOrEqual(60);

    // Minyak tidak dibeli: tidak ada pembelian, harga master tetap.
    expect(InventoryPurchase::query()->where('inventory_item_id', $d['minyak']->id)->exists())->toBeFalse()
        ->and((float) $d['minyak']->fresh()->unit_price)->toBe(20000.0);
});

it('barang ditolak yang tetap dibayar menjadi pembelian Tidak Baik (kerugian) tanpa menambah stok', function () {
    $d = formDisetujui();
    $tepung = $d['requisition']->lines->firstWhere('inventory_item_id', $d['tepung']->id);

    $d['service']->recordReceipt($tepung, 1.5, 'kemasan pecah', RequisitionLine::REJECT_PAID, 10000);
    bayarTunai($d['requisition']->fresh());
    $d['service']->check($d['requisition']->fresh());

    $tepung = $tepung->fresh();
    $baik = $tepung->inventoryPurchase;
    $rusak = $tepung->damagedPurchase;

    expect($baik->condition)->toBe(InventoryPurchase::CONDITION_GOOD)
        ->and((float) $baik->total_value)->toBe(15000.0)
        ->and($rusak->condition)->toBe(InventoryPurchase::CONDITION_DAMAGED)
        ->and((float) $rusak->qty)->toBe(0.5)
        ->and((float) $rusak->total_value)->toBe(5000.0)
        ->and($rusak->condition_notes)->toBe('kemasan pecah')
        // Stok hanya bertambah sebesar yang layak; uang keluar untuk keduanya.
        ->and(app(InventoryLedgerService::class)->balance($d['tepung']->id))->toBe(1.5)
        ->and((float) CashOut::query()->sum('amount'))->toBe(20000.0)
        ->and((float) InventoryPurchase::query()->addsToStock()->sum('total_value'))->toBe(15000.0)
        ->and((float) InventoryPurchase::query()->damaged()->sum('total_value'))->toBe(5000.0);
});

it('pembelian kredit membentuk hutang ke supplier, bukan kas keluar', function () {
    $d = formDisetujui();
    $tepung = $d['requisition']->lines->firstWhere('inventory_item_id', $d['tepung']->id);

    $d['service']->recordReceipt($tepung, 2, null, null, 12000);
    $d['service']->recordPaymentHeader($d['requisition']->fresh(), [
        'payment_type' => 'payable', 'supplier_name' => 'Toko Sembako', 'due_date' => '2026-10-15',
    ]);
    $d['service']->check($d['requisition']->fresh());

    $payable = Payable::query()->first();
    expect(CashOut::query()->count())->toBe(0)
        ->and(Payable::query()->count())->toBe(1)
        ->and((float) $payable->amount)->toBe(24000.0)
        ->and($payable->supplier_name)->toBe('Toko Sembako')
        ->and($payable->due_date->toDateString())->toBe('2026-10-15')
        ->and($payable->status)->toBe('unpaid')
        ->and(InventoryPurchase::query()->first()->payable_id)->toBe($payable->id);
});

it('menolak Periksa bila alasan tolak, harga beli, atau cara pembayaran belum lengkap, lalu lolos setelah dilengkapi', function () {
    $d = formDisetujui();
    $tepung = $d['requisition']->lines->firstWhere('inventory_item_id', $d['tepung']->id);
    $service = $d['service'];

    // Diterima lebih dari Beli & harga negatif ditolak di tempat.
    expect(fn () => $service->recordReceipt($tepung, 3))->toThrow(RuntimeException::class, 'melebihi Beli');
    expect(fn () => $service->recordReceipt($tepung, 1, null, null, -1))->toThrow(RuntimeException::class, 'negatif');
    expect(fn () => $service->recordReceipt($tepung, 1, null, 'dibuang'))->toThrow(RuntimeException::class, 'tidak dikenal');

    $service->recordReceipt($tepung, 1.0);
    $blockers = $service->checkBlockers($d['requisition']->fresh());
    expect(implode(' ', $blockers))->toContain('ditolak tanpa alasan')->toContain('cara pembayaran');
    expect(fn () => $service->check($d['requisition']->fresh()))->toThrow(RuntimeException::class, 'Belum bisa diperiksa');
    expect(InventoryMovement::query()->count())->toBe(0)->and(InventoryPurchase::query()->count())->toBe(0);

    // Bahan yang belum punya harga master saat form disusun -> harga beli wajib.
    $tepung->update(['unit_price' => null]);
    $service->recordReceipt($tepung->fresh(), 1.0, 'busuk');
    bayarTunai($d['requisition']->fresh());
    expect(implode(' ', $service->checkBlockers($d['requisition']->fresh())))->toContain('harga beli belum diisi');

    // Kontrol positif: dilengkapi -> lolos, perlakuan mengikuti pengaturan bawaan (retur).
    $service->recordReceipt($tepung->fresh(), 1.0, 'busuk', null, 11000);
    expect($service->checkBlockers($d['requisition']->fresh()))->toBe([]);
    $service->check($d['requisition']->fresh());
    expect($tepung->fresh()->rejected_treatment)->toBe(app(Settings::class)->get('requisition.reject_default_treatment'))
        ->and(InventoryPurchase::query()->count())->toBe(1);
});

it('membiarkan pemegang izin Periksa (inventory) mengisi penerimaan lewat halaman, dan menolak yang tidak berhak', function () {
    $d = formDisetujui();
    $line = $d['requisition']->lines->firstWhere('inventory_item_id', $d['tepung']->id);
    $bayar = bayarTunai($d['requisition']->fresh());

    $buat = function (string $role): User {
        Role::findOrCreate($role, 'web');
        $u = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
        $u->assignRole($role);

        return $u;
    };

    // Produksi (tanpa requisition.check) tidak boleh mengisi tahap penerimaan.
    $this->actingAs($buat('production'));
    Livewire::test(RequisitionForm::class, ['record' => $d['order']->id])
        ->fillForm(['lines' => [['id' => $line->id, 'received_qty' => 1]]])
        ->call('save')
        ->assertForbidden();
    expect($line->fresh()->received_qty)->toBeNull();

    // Staf inventory boleh: isian tersimpan, termasuk harga beli & cara pembayaran.
    $this->actingAs($buat('inventory'));
    Livewire::test(RequisitionForm::class, ['record' => $d['order']->id])
        ->assertSee('Pembayaran belanja')
        ->fillForm([
            'payment_type' => 'cash',
            'expense_category_id' => $bayar['category']->id,
            'cash_account_id' => $bayar['cash_account']->id,
            'supplier_name' => 'Pasar Induk',
            'lines' => [['id' => $line->id, 'received_qty' => 1.75, 'rejected_reason' => 'basah', 'rejected_treatment' => RequisitionLine::REJECT_RETURN, 'purchase_price' => 12800]],
        ])
        ->call('save');

    $line = $line->fresh();
    expect((float) $line->received_qty)->toBe(1.75)
        ->and((float) $line->rejected_qty)->toBe(0.25)
        ->and($line->rejected_reason)->toBe('basah')
        ->and((float) $line->purchase_price)->toBe(12800.0)
        ->and($d['requisition']->fresh()->supplier_name)->toBe('Pasar Induk');

    // Tetapi tidak boleh menyetujui form (bukan haknya) -- kontrol negatif tombol tahap.
    expect(auth()->user()->can('requisition.approve'))->toBeFalse()
        ->and(auth()->user()->can('requisition.check'))->toBeTrue();
});
