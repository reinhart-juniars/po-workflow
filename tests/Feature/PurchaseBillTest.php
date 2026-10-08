<?php

use App\Filament\Resources\PurchaseBillResource\Pages\CreatePurchaseBill;
use App\Filament\Resources\PurchaseBillResource\Pages\EditPurchaseBill;
use App\Models\CashAccount;
use App\Models\CashOut;
use App\Models\InventoryPurchase;
use App\Models\Payable;
use App\Models\PeriodClosing;
use App\Models\PurchaseBill;
use App\Models\User;
use App\Services\BalanceSheetService;
use App\Services\InventoryLedgerService;
use App\Services\InventoryPurchaseFlowService;
use App\Services\ProductionOrderService;
use App\Services\PurchaseBillService;
use App\Services\RequisitionService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Tagihan Pembelian (revisi 7 Okt 2026): gudang belanja lalu menagih ke
 * accounting. Barang diterima -> persediaan & hutang tagihan lahir bersama;
 * accounting membayar -> kas keluar sebagai pelunasan hutang. Neraca harus
 * seimbang di setiap langkah: kekayaan bersih tidak bergeser.
 */
function penggunaTagihan(string $role): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

/** Form Kebutuhan diperiksa: tepung 2 kg @ 12.000 diterima -> satu tagihan draft. */
function tagihanDariForm(): array
{
    $d = siapkanProduksi();
    $order = app(ProductionOrderService::class)->generateFromSpk($d['spk']);
    $service = app(RequisitionService::class);
    $requisition = $service->build($order)['requisition'];
    $tepung = $requisition->lines->firstWhere('inventory_item_id', $d['tepung']->id);
    $service->fillOpeningStock($tepung, 0);
    $service->fillOpeningStock($requisition->lines->firstWhere('inventory_item_id', $d['minyak']->id), 5);
    $service->submit($requisition->fresh());
    $service->approve($requisition->fresh());
    $service->recordReceipt($tepung->fresh(), 2, null, null, 12000);
    $service->check($requisition->fresh());

    return $d + ['requisition' => $requisition->fresh(), 'bill' => PurchaseBill::query()->sole()];
}

function kasTagihan(float $saldo = 1_000_000): CashAccount
{
    return CashAccount::query()->create(['name' => 'Kas Besar', 'type' => 'cash', 'is_active' => true, 'opening_balance' => $saldo]);
}

function kekayaanBersih(): float
{
    return round((float) app(BalanceSheetService::class)->buildReport(Carbon::today())['wealthAmount'], 2);
}

it('membukukan seimbang: barang diterima (persediaan = hutang) lalu dibayar (kas = hutang), kekayaan bersih tidak bergeser', function () {
    $kas = kasTagihan();
    $sebelum = kekayaanBersih();

    $d = tagihanDariForm();
    $bill = $d['bill'];

    // Barang masuk, belum dibayar: hutang tagihan = nilai pembelian.
    expect((float) $bill->total)->toBe(24000.0)
        ->and((float) $bill->payable->amount)->toBe((float) $bill->purchases()->sum('total_value'))
        ->and(CashOut::query()->count())->toBe(0)
        ->and(kekayaanBersih())->toBe($sebelum);

    app(PurchaseBillService::class)->submit($bill, null);
    app(PurchaseBillService::class)->pay($bill->fresh(), ['cash_account_id' => $kas->id, 'paid_on' => today()->toDateString()], null);

    $bill->refresh();
    $kasKeluar = CashOut::query()->sole();
    // Debit hutang = kredit kas, sama persis.
    expect($bill->status)->toBe(PurchaseBill::STATUS_PAID)
        ->and($bill->payable->status)->toBe('paid')
        ->and((float) $kasKeluar->amount)->toBe((float) $bill->payable->amount)
        ->and($kasKeluar->payable_id)->toBe($bill->payable_id)
        ->and($kasKeluar->cash_account_id)->toBe($kas->id)
        ->and($kasKeluar->category->name)->toBe(PurchaseBillService::SETTLEMENT_CATEGORY)
        ->and($kasKeluar->category->include_hpp)->toBeFalse()
        ->and(kekayaanBersih())->toBe($sebelum);

    // Tidak bisa dibayar dua kali.
    expect(fn () => app(PurchaseBillService::class)->pay($bill->fresh(), ['cash_account_id' => $kas->id, 'paid_on' => today()->toDateString()], null))
        ->toThrow(ValidationException::class);
    expect(CashOut::query()->count())->toBe(1);
});

it('mencatat belanja lepas: barang masuk Kartu Stok dan hutang tagihan lahir bersamanya', function () {
    $d = siapkanProduksi();
    kasTagihan();
    $sebelum = kekayaanBersih();

    $bill = app(PurchaseBillService::class)->createStandalone([
        'bill_date' => today()->toDateString(),
        'supplier_name' => 'Pasar Pagi',
        'lines' => [
            ['inventory_item_id' => $d['tepung']->id, 'qty' => 3, 'unit_cost' => 11000],
            ['inventory_item_id' => $d['minyak']->id, 'qty' => 0, 'unit_cost' => 20000], // baris kosong diabaikan
        ],
    ], null);

    expect($bill->requisition_id)->toBeNull()
        ->and($bill->purchases)->toHaveCount(1)
        ->and($bill->purchases->first()->payment_type)->toBe(InventoryPurchase::PAYMENT_BILL)
        ->and((float) $bill->total)->toBe(33000.0)
        ->and((float) $bill->payable->amount)->toBe(33000.0)
        ->and(app(InventoryLedgerService::class)->balance($d['tepung']->id))->toBe(3.0)
        ->and(kekayaanBersih())->toBe($sebelum);

    // Tanpa baris berisi: ditolak, tidak ada yang tercatat.
    expect(fn () => app(PurchaseBillService::class)->createStandalone(['bill_date' => today()->toDateString(), 'lines' => []], null))
        ->toThrow(ValidationException::class);
    expect(PurchaseBill::query()->count())->toBe(1);
});

it('menjadikan hutang supplier dengan jatuh tempo, lalu tetap bisa dibayar dari halaman tagihan', function () {
    $kas = kasTagihan();
    $bill = tagihanDariForm()['bill'];
    $service = app(PurchaseBillService::class);

    $service->submit($bill, null);
    $service->markCredit($bill->fresh(), today()->addDays(14)->toDateString(), null);

    expect($bill->fresh()->status)->toBe(PurchaseBill::STATUS_CREDIT)
        ->and($bill->fresh()->payable->due_date->toDateString())->toBe(today()->addDays(14)->toDateString())
        ->and(CashOut::query()->count())->toBe(0);

    $service->pay($bill->fresh(), ['cash_account_id' => $kas->id, 'paid_on' => today()->toDateString()], null);
    expect($bill->fresh()->status)->toBe(PurchaseBill::STATUS_PAID);
});

it('mengembalikan tagihan ke gudang dengan alasan, lalu gudang bisa mengajukan ulang', function () {
    $bill = tagihanDariForm()['bill'];
    $service = app(PurchaseBillService::class);
    $service->submit($bill, null);

    $service->returnToInventory($bill->fresh(), 'Nota belum dilampirkan', null);
    expect($bill->fresh()->status)->toBe(PurchaseBill::STATUS_RETURNED)
        ->and($bill->fresh()->return_reason)->toBe('Nota belum dilampirkan')
        ->and($bill->fresh()->isEditableByInventory())->toBeTrue();

    $service->submit($bill->fresh(), null);
    expect($bill->fresh()->status)->toBe(PurchaseBill::STATUS_SUBMITTED)
        ->and($bill->fresh()->return_reason)->toBeNull();
});

it('menolak pembayaran di periode yang sudah ditutup dan akun kas nonaktif', function () {
    $bill = tagihanDariForm()['bill'];
    app(PurchaseBillService::class)->submit($bill, null);
    $nonaktif = CashAccount::query()->create(['name' => 'Kas Lama', 'type' => 'cash', 'is_active' => false]);
    $kas = kasTagihan();
    PeriodClosing::query()->create(['period_month' => today()->subMonth()->month, 'period_year' => today()->subMonth()->year, 'closed_at' => now(), 'closed_by' => penggunaTagihan('owner')->id]);

    expect(fn () => app(PurchaseBillService::class)->pay($bill->fresh(), ['cash_account_id' => $nonaktif->id, 'paid_on' => today()->toDateString()], null))
        ->toThrow(ValidationException::class, 'Akun kas');
    expect(fn () => app(PurchaseBillService::class)->pay($bill->fresh(), ['cash_account_id' => $kas->id, 'paid_on' => today()->subMonth()->toDateString()], null))
        ->toThrow(ValidationException::class, 'sudah ditutup');

    // Kontrol positif: akun aktif, periode terbuka -> lolos.
    app(PurchaseBillService::class)->pay($bill->fresh(), ['cash_account_id' => $kas->id, 'paid_on' => today()->toDateString()], null);
    expect($bill->fresh()->status)->toBe(PurchaseBill::STATUS_PAID);
});

it('mengunci pembelian milik tagihan dari ubah/hapus satuan', function () {
    $bill = tagihanDariForm()['bill'];
    $purchase = $bill->purchases()->first();
    $flow = app(InventoryPurchaseFlowService::class);

    expect(fn () => $flow->update($purchase, ['inventory_item_id' => $purchase->inventory_item_id, 'transaction_date' => today()->toDateString(), 'total_cost' => 1, 'payment_type' => 'cash'], null))
        ->toThrow(ValidationException::class, $bill->number);
    expect(fn () => $flow->delete($purchase))->toThrow(ValidationException::class, $bill->number);

    expect($purchase->fresh()->payment_type)->toBe(InventoryPurchase::PAYMENT_BILL)
        ->and((float) $bill->fresh()->total)->toBe(24000.0);
});

it('menyembunyikan hutang tagihan dari Pembayaran Kredit dan menolak membayarnya lewat Pengeluaran', function () {
    $kas = kasTagihan();
    $bill = tagihanDariForm()['bill'];
    $hutangLain = Payable::query()->create(['transaction_date' => today(), 'supplier_name' => 'Supplier Lain', 'description' => 'Hutang biasa', 'amount' => 5000, 'status' => 'unpaid']);
    $akunting = penggunaTagihan('accounting');

    $this->actingAs($akunting)->get(route('accountingapp.expenses.index'))->assertOk()
        ->assertViewHas('openPayables', fn ($payables) => $payables->pluck('id')->contains($hutangLain->id)
            && ! $payables->pluck('id')->contains($bill->payable_id));

    $this->actingAs($akunting)->post(route('accountingapp.expenses.store'), [
        'expense_flow' => 'payable_settlement',
        'payable_id' => $bill->payable_id,
        'cash_account_id' => $kas->id,
        'expense_date' => today()->toDateString(),
    ])->assertSessionHasErrors('payable_id');

    expect($bill->payable->fresh()->status)->toBe('unpaid')
        ->and(CashOut::query()->where('payable_id', $bill->payable_id)->exists())->toBeFalse();
});

it('membiarkan accounting membayar lewat halaman, dan menolak gudang / sales', function () {
    $kas = kasTagihan();
    $bill = tagihanDariForm()['bill'];
    app(PurchaseBillService::class)->submit($bill, null);

    // Gudang (inventory) tidak punya aplikasi Accounting maupun izin bayar.
    $gudang = penggunaTagihan('inventory');
    $this->actingAs($gudang)->get(route('accountingapp.purchase-bills.index'))->assertForbidden();
    $this->actingAs($gudang)->post(route('accountingapp.purchase-bills.pay', $bill), ['cash_account_id' => $kas->id, 'paid_on' => today()->toDateString()])->assertForbidden();
    $this->actingAs(penggunaTagihan('sales'))->get(route('accountingapp.purchase-bills.show', $bill))->assertForbidden();
    expect($bill->fresh()->status)->toBe(PurchaseBill::STATUS_SUBMITTED);

    // Kontrol positif: accounting melihat antrean dan membayar.
    $akunting = penggunaTagihan('accounting');
    $this->actingAs($akunting)->get(route('accountingapp.purchase-bills.index'))->assertOk()
        ->assertSee($bill->number)->assertSee('Menunggu dibayar');
    $this->actingAs($akunting)->get(route('accountingapp.purchase-bills.show', $bill))->assertOk()
        ->assertSee('Bayar Rp 24.000');
    $this->actingAs($akunting)->post(route('accountingapp.purchase-bills.pay', $bill), ['cash_account_id' => $kas->id, 'paid_on' => today()->toDateString()])
        ->assertSessionHasNoErrors()->assertRedirect(route('accountingapp.purchase-bills.show', $bill));

    expect($bill->fresh()->status)->toBe(PurchaseBill::STATUS_PAID);
});

it('memvalidasi isian accounting lewat form request', function () {
    kasTagihan();
    $bill = tagihanDariForm()['bill'];
    app(PurchaseBillService::class)->submit($bill, null);
    $akunting = penggunaTagihan('accounting');

    $this->actingAs($akunting)->post(route('accountingapp.purchase-bills.pay', $bill), ['paid_on' => today()->addDay()->toDateString()])
        ->assertSessionHasErrors(['cash_account_id', 'paid_on']);
    $this->actingAs($akunting)->post(route('accountingapp.purchase-bills.return', $bill), ['return_reason' => 'x'])
        ->assertSessionHasErrors('return_reason');
    $this->actingAs($akunting)->post(route('accountingapp.purchase-bills.credit', $bill), ['due_date' => today()->subDay()->toDateString()])
        ->assertSessionHasErrors('due_date');

    expect($bill->fresh()->status)->toBe(PurchaseBill::STATUS_SUBMITTED);
});

it('membiarkan gudang melampirkan nota dan mengajukan dari panel; nota hanya dibuka pemegang izin', function () {
    Storage::fake('local');
    $bill = tagihanDariForm()['bill'];
    $gudang = penggunaTagihan('inventory');
    penggunaTagihan('accounting'); // penerima lonceng purchase.pay

    $this->actingAs($gudang);
    Livewire::test(EditPurchaseBill::class, ['record' => $bill->getRouteKey()])
        ->assertOk()
        ->assertSee('Bahan yang ditagihkan')
        ->fillForm(['receipt_path' => UploadedFile::fake()->image('nota.jpg'), 'notes' => 'Belanja pagi'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->callAction('ajukan');

    $bill->refresh();
    expect($bill->status)->toBe(PurchaseBill::STATUS_SUBMITTED)
        ->and($bill->receipt_path)->not->toBeNull()
        ->and($bill->notes)->toBe('Belanja pagi');
    Storage::disk('local')->assertExists($bill->receipt_path);

    // Sudah di accounting: gudang masih bisa melihat, tapi tidak mengubah.
    Livewire::test(EditPurchaseBill::class, ['record' => $bill->getRouteKey()])
        ->assertOk()
        ->assertActionHidden('ajukan');
    expect($gudang->can('update', $bill))->toBeFalse();

    $this->actingAs($gudang)->get(route('purchase-bills.receipt', $bill))->assertOk();
    $this->actingAs(penggunaTagihan('accounting'))->get(route('purchase-bills.receipt', $bill))->assertOk();
    $this->actingAs(penggunaTagihan('sales'))->get(route('purchase-bills.receipt', $bill))->assertForbidden();
});

it('mencatat belanja lepas dari panel Inventory', function () {
    $d = siapkanProduksi();
    $this->actingAs(penggunaTagihan('inventory'));
    // Baris kosong bawaan Repeater (defaultItems) diganti isian tes.
    $undoRepeaterFake = Filament\Forms\Components\Repeater::fake();

    Livewire::test(CreatePurchaseBill::class)
        ->fillForm([
            'bill_date' => today()->toDateString(),
            'lines' => [['inventory_item_id' => $d['tepung']->id, 'qty' => 1.5, 'unit_cost' => 12000]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $undoRepeaterFake();

    $bill = PurchaseBill::query()->sole();
    expect((float) $bill->total)->toBe(18000.0)
        ->and($bill->status)->toBe(PurchaseBill::STATUS_DRAFT)
        ->and(app(InventoryLedgerService::class)->balance($d['tepung']->id))->toBe(1.5);

    // Kontrol negatif: peran tanpa izin inventory tidak bisa membuka panelnya.
    $this->actingAs(penggunaTagihan('sales'))->get('/inventory/purchase-bills/belanja-lepas')->assertForbidden();
});
