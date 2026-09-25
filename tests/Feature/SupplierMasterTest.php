<?php

use App\Filament\Resources\InventoryPurchaseResource\Pages\EditInventoryPurchase;
use App\Filament\Resources\ProductionOrderResource\Pages\RequisitionForm;
use App\Filament\Resources\SupplierResource\Pages\CreateSupplier;
use App\Filament\Resources\SupplierResource\Pages\EditSupplier;
use App\Filament\Resources\SupplierResource\Pages\ListSuppliers;
use App\Models\CashAccount;
use App\Models\ExpenseCategory;
use App\Models\InventoryItem;
use App\Models\InventoryPurchase;
use App\Models\Payable;
use App\Models\Requisition;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryPurchaseFlowService;
use App\Services\ProductionOrderService;
use App\Services\RequisitionService;
use Filament\Tables\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Master Supplier: data supplier bahan baku, bahan yang dipasoknya, dan
 * tautannya ke pembelian, form kebutuhan, dan hutang. supplier_name tetap
 * menjadi salinan nama di transaksi karena laporan hutang & neraca membacanya.
 */
beforeEach(function () {
    Role::findOrCreate('accounting', 'web');

    $this->user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $this->user->assignRole('accounting');
    $this->actingAs($this->user);

    $this->tepung = InventoryItem::query()->create([
        'name' => 'Tepung Terigu',
        'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'is_active' => true,
    ]);
});

function supplierMakmur(array $attributes = []): Supplier
{
    return Supplier::query()->create($attributes + [
        'name' => 'Toko Makmur',
        'phone' => '081234567890',
        'payment_term_days' => 14,
        'is_active' => true,
    ]);
}

it('menyimpan supplier baru beserta bahan yang dipasoknya', function () {
    Livewire::test(CreateSupplier::class)
        ->fillForm([
            'name' => '  Toko Makmur  ',
            'contact_person' => 'Bu Sari',
            'phone' => '081234567890',
            'bank_account' => 'BCA 1234567890 a.n. Sari',
            'payment_term_days' => 30,
            'items' => [$this->tepung->id],
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $supplier = Supplier::query()->sole();

    expect($supplier->name)->toBe('Toko Makmur')
        ->and($supplier->payment_term_days)->toBe(30)
        ->and($supplier->created_by)->toBe($this->user->id)
        ->and($supplier->items->pluck('id')->all())->toBe([$this->tepung->id])
        ->and($this->tepung->suppliers->pluck('id')->all())->toBe([$supplier->id]);
});

it('menolak nama supplier kembar walau beda huruf besar/kecil', function () {
    supplierMakmur();

    Livewire::test(CreateSupplier::class)
        ->fillForm(['name' => 'toko makmur', 'is_active' => true])
        ->call('create')
        ->assertHasFormErrors(['name']);

    // Positive control: nama lain tetap bisa disimpan.
    Livewire::test(CreateSupplier::class)
        ->fillForm(['name' => 'Toko Sentosa', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Supplier::query()->count())->toBe(2);

    // Menyunting supplier tanpa mengganti namanya tidak dianggap kembar.
    $makmur = Supplier::query()->firstWhere('name', 'Toko Makmur');
    Livewire::test(EditSupplier::class, ['record' => $makmur->getRouteKey()])
        ->fillForm(['phone' => '0899'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($makmur->fresh()->phone)->toBe('0899');
});

it('pembelian kredit dari master menyalin nama, mengisi jatuh tempo dari termin, dan menautkan bahan', function () {
    $supplier = supplierMakmur();

    $purchase = app(InventoryPurchaseFlowService::class)->create([
        'inventory_item_id' => $this->tepung->id,
        'transaction_date' => '2026-09-01',
        'qty' => 10,
        'unit_cost' => 12000,
        'payment_type' => 'payable',
        'supplier_id' => $supplier->id,
    ], $this->user->id);

    $payable = $purchase->payable;

    expect($purchase->supplier_id)->toBe($supplier->id)
        ->and($purchase->supplier_name)->toBe('Toko Makmur')
        ->and($payable->supplier_id)->toBe($supplier->id)
        ->and($payable->supplier_name)->toBe('Toko Makmur')
        // Termin 14 hari dari 1 September.
        ->and($payable->due_date->toDateString())->toBe('2026-09-15')
        // Hutang yang terbentuk sama persis dengan nilai pembelian.
        ->and((float) $payable->amount)->toBe((float) $purchase->total_value)
        ->and((float) $payable->amount)->toBe(120000.0)
        ->and($supplier->items()->pluck('inventory_items.id')->all())->toBe([$this->tepung->id]);

    // Jatuh tempo yang diisi manual tidak ditimpa termin.
    $manual = app(InventoryPurchaseFlowService::class)->create([
        'inventory_item_id' => $this->tepung->id,
        'transaction_date' => '2026-09-01',
        'qty' => 1,
        'unit_cost' => 12000,
        'payment_type' => 'payable',
        'supplier_id' => $supplier->id,
        'due_date' => '2026-09-05',
    ], $this->user->id);

    expect($manual->payable->due_date->toDateString())->toBe('2026-09-05');
});

it('menautkan nama yang diketik di form Blade ke master bila cocok, dan menyimpan apa adanya bila tidak', function () {
    $supplier = supplierMakmur();

    // Jalur modul Pengeluaran menulis model langsung dengan supplier_name.
    $cocok = InventoryPurchase::query()->create([
        'inventory_item_id' => $this->tepung->id,
        'transaction_date' => '2026-09-01',
        'qty' => 1,
        'unit_cost' => 5000,
        'payment_type' => 'cash',
        'supplier_name' => ' toko MAKMUR ',
    ]);

    $asing = InventoryPurchase::query()->create([
        'inventory_item_id' => $this->tepung->id,
        'transaction_date' => '2026-09-01',
        'qty' => 1,
        'unit_cost' => 5000,
        'payment_type' => 'cash',
        'supplier_name' => 'Pedagang Pasar',
    ]);

    expect($cocok->supplier_id)->toBe($supplier->id)
        ->and($cocok->supplier_name)->toBe('Toko Makmur')
        ->and($asing->supplier_id)->toBeNull()
        ->and($asing->supplier_name)->toBe('Pedagang Pasar');

    // Nama diganti ke yang tidak ada di master: tautan lama ikut lepas.
    $cocok->update(['supplier_name' => 'Pedagang Pasar']);
    expect($cocok->fresh()->supplier_id)->toBeNull();

    // Hutang dari saldo awal / modul Blade juga ikut tertaut.
    $payable = Payable::query()->create([
        'transaction_date' => '2026-09-01',
        'supplier_name' => 'Toko Makmur',
        'amount' => 1000,
        'status' => 'unpaid',
    ]);
    expect($payable->supplier_id)->toBe($supplier->id);
});

it('menyunting pembelian lama tanpa menghapus nama supplier yang belum ada di master', function () {
    $category = ExpenseCategory::query()->create(['name' => 'Pembelian Stok', 'expense_mode' => ExpenseCategory::MODE_INVENTORY_PURCHASE, 'is_active' => true]);
    $cash = CashAccount::query()->create(['name' => 'Kas Kecil', 'type' => 'cash', 'is_active' => true]);

    $purchase = app(InventoryPurchaseFlowService::class)->create([
        'inventory_item_id' => $this->tepung->id,
        'transaction_date' => now()->toDateString(),
        'qty' => 1,
        'unit_cost' => 5000,
        'payment_type' => 'cash',
        'expense_category_id' => $category->id,
        'cash_account_id' => $cash->id,
        'supplier_name' => 'Pedagang Pasar',
    ], $this->user->id);

    expect($purchase->supplier_id)->toBeNull();

    Livewire::test(EditInventoryPurchase::class, ['record' => $purchase->getRouteKey()])
        ->fillForm(['notes' => 'dikoreksi'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($purchase->fresh()->supplier_name)->toBe('Pedagang Pasar')
        ->and($purchase->fresh()->notes)->toBe('dikoreksi');

    // Setelah dipilih dari master, nama ikut master.
    $supplier = supplierMakmur();

    Livewire::test(EditInventoryPurchase::class, ['record' => $purchase->getRouteKey()])
        ->fillForm(['supplier_id' => $supplier->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($purchase->fresh()->supplier_id)->toBe($supplier->id)
        ->and($purchase->fresh()->supplier_name)->toBe('Toko Makmur');
});

it('mewajibkan supplier dari master untuk pembelian kredit di panel', function () {
    $supplier = supplierMakmur();

    $purchase = app(InventoryPurchaseFlowService::class)->create([
        'inventory_item_id' => $this->tepung->id,
        'transaction_date' => now()->toDateString(),
        'qty' => 1,
        'unit_cost' => 5000,
        'payment_type' => 'payable',
        'supplier_id' => $supplier->id,
    ], $this->user->id);

    Livewire::test(EditInventoryPurchase::class, ['record' => $purchase->getRouteKey()])
        ->fillForm(['supplier_id' => null])
        ->call('save')
        ->assertHasFormErrors(['supplier_id' => 'required']);

    // Id yang tidak ada di master ditolak service, bukan disimpan tanpa nama.
    expect(fn () => app(InventoryPurchaseFlowService::class)->update($purchase, [
        'inventory_item_id' => $this->tepung->id,
        'transaction_date' => now()->toDateString(),
        'total_cost' => 5000,
        'payment_type' => 'payable',
        'supplier_id' => 999999,
    ], $this->user->id))->toThrow(Illuminate\Validation\ValidationException::class);

    expect($purchase->fresh()->supplier_id)->toBe($supplier->id);
});

it('memakai supplier dari master di form kebutuhan sampai ke pembelian dan hutangnya', function () {
    $d = siapkanProduksi();
    $order = app(ProductionOrderService::class)->generateFromSpk($d['spk']);
    $service = app(RequisitionService::class);
    $requisition = $service->build($order)['requisition'];
    $service->fillOpeningStock($requisition->lines->firstWhere('inventory_item_id', $d['tepung']->id), 0);
    $service->fillOpeningStock($requisition->lines->firstWhere('inventory_item_id', $d['minyak']->id), 5);
    $service->submit($requisition->fresh());
    $service->approve($requisition->fresh());

    $supplier = supplierMakmur(['payment_term_days' => 7]);
    $line = $requisition->fresh()->lines->firstWhere('inventory_item_id', $d['tepung']->id);
    $service->recordReceipt($line, 2, null, null, 12000);

    Livewire::test(RequisitionForm::class, ['record' => $order->id])
        ->set('data.payment_type', 'payable')
        ->set('data.supplier_id', $supplier->id)
        ->call('save');

    $requisition = $requisition->fresh();
    expect($requisition->supplier_id)->toBe($supplier->id)
        ->and($requisition->supplier_name)->toBe('Toko Makmur');

    $service->check($requisition);

    $purchase = InventoryPurchase::query()->sole();
    expect($purchase->supplier_id)->toBe($supplier->id)
        ->and($purchase->payable->supplier_id)->toBe($supplier->id)
        ->and($purchase->payable->due_date->toDateString())->toBe($purchase->transaction_date->addDays(7)->toDateString())
        ->and((float) $purchase->payable->amount)->toBe((float) $purchase->total_value)
        ->and($supplier->items()->pluck('inventory_items.id')->all())->toBe([$d['tepung']->id]);
});

it('mempertahankan nama lama di form kebutuhan bila pilihan supplier dibiarkan kosong', function () {
    $requisition = Requisition::query()->create([
        'production_order_id' => App\Models\ProductionOrder::query()->create(['production_date' => now()->toDateString(), 'status' => 'planned'])->id,
        'status' => Requisition::STATUS_APPROVED,
        'supplier_name' => 'Pedagang Pasar',
    ]);

    $service = app(RequisitionService::class);
    $service->recordPaymentHeader($requisition, ['payment_type' => 'cash', 'expense_category_id' => null, 'cash_account_id' => null, 'supplier_id' => null]);
    expect($requisition->fresh()->supplier_name)->toBe('Pedagang Pasar');

    // Supplier master yang dikosongkan dengan sengaja memang hilang.
    $supplier = supplierMakmur();
    $service->recordPaymentHeader($requisition->fresh(), ['payment_type' => 'cash', 'expense_category_id' => null, 'cash_account_id' => null, 'supplier_id' => $supplier->id]);
    expect($requisition->fresh()->supplier_name)->toBe('Toko Makmur');

    $service->recordPaymentHeader($requisition->fresh(), ['payment_type' => 'cash', 'expense_category_id' => null, 'cash_account_id' => null, 'supplier_id' => null]);
    expect($requisition->fresh()->supplier_id)->toBeNull()
        ->and($requisition->fresh()->supplier_name)->toBeNull();
});

it('menolak penghapusan supplier yang sudah bertransaksi, tetapi menghapus yang belum', function () {
    $dipakai = supplierMakmur();
    $kosong = supplierMakmur(['name' => 'Toko Baru']);

    InventoryPurchase::query()->create([
        'inventory_item_id' => $this->tepung->id,
        'transaction_date' => now()->toDateString(),
        'qty' => 1,
        'unit_cost' => 5000,
        'payment_type' => 'cash',
        'supplier_id' => $dipakai->id,
    ]);

    Livewire::test(ListSuppliers::class)
        ->callTableAction(DeleteAction::class, $dipakai)
        ->assertNotified('Supplier tidak bisa dihapus');

    expect(Supplier::query()->whereKey($dipakai->id)->exists())->toBeTrue();

    // Positive control: supplier tanpa transaksi terhapus.
    Livewire::test(ListSuppliers::class)
        ->callTableAction(DeleteAction::class, $kosong);

    expect(Supplier::query()->whereKey($kosong->id)->exists())->toBeFalse();

    // Pagar database juga menolak, bila jalur UI terlewati.
    expect(fn () => $dipakai->delete())->toThrow(Illuminate\Database\QueryException::class);
});

it('mengisi master supplier dari nama lama saat migrasi', function () {
    // Kondisi sebelum migrasi: nama teks bebas tanpa tautan.
    DB::table('inventory_purchases')->insert([
        ['inventory_item_id' => $this->tepung->id, 'transaction_date' => '2026-01-01', 'qty' => 1, 'unit_cost' => 1, 'total_value' => 1, 'payment_type' => 'cash', 'condition' => 'good', 'supplier_name' => 'Toko Makmur', 'created_at' => now(), 'updated_at' => now()],
        ['inventory_item_id' => $this->tepung->id, 'transaction_date' => '2026-01-02', 'qty' => 1, 'unit_cost' => 1, 'total_value' => 1, 'payment_type' => 'cash', 'condition' => 'good', 'supplier_name' => 'toko makmur ', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('payables')->insert([
        ['transaction_date' => '2026-01-01', 'supplier_name' => 'TOKO MAKMUR', 'amount' => 1, 'status' => 'unpaid', 'created_at' => now(), 'updated_at' => now()],
        ['transaction_date' => '2026-01-01', 'supplier_name' => 'Saldo Awal Hutang #3', 'amount' => 1, 'status' => 'unpaid', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $migration = require database_path('migrations/2026_09_25_100002_link_supplier_to_purchases_payables_requisitions.php');
    (new ReflectionMethod($migration, 'backfill'))->invoke($migration);

    $supplier = Supplier::query()->sole();

    expect($supplier->name)->toBe('Toko Makmur')
        ->and(DB::table('inventory_purchases')->where('supplier_id', $supplier->id)->count())->toBe(2)
        ->and(DB::table('payables')->where('supplier_id', $supplier->id)->count())->toBe(1)
        // Nama keterangan di hutang saldo awal tidak dijadikan supplier.
        ->and(DB::table('payables')->where('supplier_name', 'Saldo Awal Hutang #3')->value('supplier_id'))->toBeNull()
        ->and($supplier->items()->pluck('inventory_items.id')->all())->toBe([$this->tepung->id]);
});
