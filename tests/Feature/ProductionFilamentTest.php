<?php

use App\Filament\Pages\HppComparisonReport;
use App\Filament\Resources\InventoryMovementResource;
use App\Filament\Resources\ProductionOrderResource;
use App\Filament\Resources\RequisitionResource;
use App\Models\Area;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeTask;
use App\Models\Requisition;
use App\Models\Spk;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Antarmuka produksi di panel: SPK Produksi, Form Kebutuhan, Lembar Kerja,
 * Plating, Ledger, dan Perbandingan HPP.
 *
 * Yang diuji adalah alur lengkap lewat halaman yang sebenarnya dipakai orang,
 * bukan layanan di baliknya (itu sudah diuji sendiri): tombol yang salah
 * sambung atau kolom yang tidak ikut tersimpan baru ketahuan di sini.
 */
beforeEach(function () {
    Role::findOrCreate('admin', 'web');

    $this->user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $this->user->assignRole('admin');
    $this->actingAs($this->user);

    $bucket = InventoryItem::query()->create([
        'name' => 'Bahan Baku', 'unit' => 'All', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true,
    ]);

    $this->tepung = InventoryItem::query()->create([
        'parent_id' => $bucket->id, 'name' => 'Tepung Terigu', 'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'unit_price' => 12000, 'is_active' => true,
    ]);

    $product = Product::query()->create(['name' => 'Gorengan 10K', 'unit' => 'porsi', 'base_price' => 10000, 'active' => true]);

    $this->recipe = Recipe::query()->create([
        'name' => 'Gorengan', 'jenis' => Recipe::JENIS_UTAMA,
        'yield_qty' => 10, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25,
    ]);
    $product->update(['recipe_id' => $this->recipe->id]);
    RecipeItem::query()->create(['recipe_id' => $this->recipe->id, 'inventory_item_id' => $this->tepung->id, 'raw_name' => 'tepung', 'qty' => 250, 'unit' => 'gr', 'section' => 'Adonan']);
    RecipeTask::query()->create(['recipe_id' => $this->recipe->id, 'sort_order' => 0, 'task' => 'goreng', 'object' => 'adonan', 'quantity_text' => '10 porsi', 'pic' => 'Mia']);

    $area = Area::query()->create(['name' => 'Area', 'code' => 'AR']);
    $customer = Customer::query()->create(['name' => 'Pelanggan', 'area_id' => $area->id, 'is_lapak' => false]);

    $this->spk = Spk::query()->create([
        'scheduled_at' => '2026-09-15 07:00:00', 'slot_type' => 'fixed_07', 'status' => 'draft', 'responsible_user_id' => $this->user->id,
    ]);

    $po = PurchaseOrder::query()->create([
        'customer_id' => $customer->id, 'recipient_name' => 'Pelanggan', 'shipping_address' => 'Jl.',
        'area_id' => $area->id, 'delivery_date' => '2026-09-15', 'delivery_time' => '09:00:00',
        'payment_type' => 'cash', 'status' => 'pending', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);
    PurchaseOrderItem::query()->create(['purchase_order_id' => $po->id, 'product_id' => $product->id, 'qty' => 40, 'unit' => 'porsi']);
    $this->spk->purchaseOrders()->attach($po->id);
});

it('menyusun spk produksi dari slot spk lewat daftar', function () {
    Livewire::test(ProductionOrderResource\Pages\ListProductionOrders::class)
        ->callAction('dari_spk', ['spk_id' => $this->spk->id])
        ->assertHasNoActionErrors();

    $order = ProductionOrder::query()->firstWhere('spk_id', $this->spk->id);

    expect($order)->not->toBeNull()
        ->and($order->lines)->toHaveCount(1)
        ->and($order->lines[0]->recipe_id)->toBe($this->recipe->id)
        ->and((float) $order->lines[0]->qty)->toBe(40.0);
});

it('menjalankan form kebutuhan dari susun sampai spk ditutup lewat halaman', function () {
    $order = app(\App\Services\ProductionOrderService::class)->generateFromSpk($this->spk, $this->user->id);

    $page = Livewire::test(ProductionOrderResource\Pages\RequisitionForm::class, ['record' => $order->id])
        ->assertSee('Pratinjau Kebutuhan')
        ->callAction('susun')
        ->assertHasNoActionErrors();

    $requisition = $order->fresh()->requisition;
    expect($requisition)->not->toBeNull()
        ->and($requisition->lines)->toHaveCount(1)
        // 40 porsi = 4x resep = 1.000 gr = 1 kg.
        ->and((float) $requisition->lines[0]->required_qty)->toBe(1.0);

    // Isi Stok Awal 0,25 kg lewat form -> Beli 0,75 kg tersimpan.
    $page->fillForm(['lines' => [[
        'id' => $requisition->lines[0]->id,
        'opening_stock_qty' => 0.25,
        'purchase_qty' => 0.75,
        'notes' => 'stok rak 2',
    ]]])
        ->callAction('simpan')
        ->assertHasNoActionErrors();

    $line = $requisition->lines[0]->fresh();
    expect((float) $line->opening_stock_qty)->toBe(0.25)
        ->and((float) $line->purchase_qty)->toBe(0.75)
        ->and($line->notes)->toBe('stok rak 2');

    // Ajukan (produksi) -> Setujui (supervisor) -> Periksa: ledger terisi saldo awal + pembelian.
    Livewire::test(ProductionOrderResource\Pages\RequisitionForm::class, ['record' => $order->id])
        ->assertActionHidden('setujui')
        ->callAction('ajukan')
        ->assertHasNoActionErrors();
    expect($requisition->fresh()->status)->toBe(Requisition::STATUS_SUBMITTED);

    Livewire::test(ProductionOrderResource\Pages\RequisitionForm::class, ['record' => $order->id])
        ->assertActionHidden('ajukan')
        ->assertActionHidden('simpan')
        ->callAction('setujui')
        ->assertHasNoActionErrors();
    expect($requisition->fresh()->status)->toBe(Requisition::STATUS_APPROVED);

    // Tahap Disetujui: kolom Diterima terbuka; 0,25 kg datang rusak -> diterima 0,5.
    // Periksa memakai isian yang belum disimpan itu.
    // Cara pembayaran & alasan tolak ikut diisi di halaman yang sama.
    $bayar = bayarTunai($requisition->fresh());
    Livewire::test(ProductionOrderResource\Pages\RequisitionForm::class, ['record' => $order->id])
        ->fillForm([
            'payment_type' => 'cash',
            'expense_category_id' => $bayar['category']->id,
            'cash_account_id' => $bayar['cash_account']->id,
            'lines' => [['id' => $line->id, 'received_qty' => 0.5, 'rejected_reason' => 'kemasan sobek', 'purchase_price' => 13000]],
        ])
        ->callAction('periksa')
        ->assertHasNoActionErrors();
    expect($requisition->fresh()->status)->toBe(Requisition::STATUS_CHECKED)
        ->and((float) $line->fresh()->received_qty)->toBe(0.5)
        ->and($line->fresh()->rejected_reason)->toBe('kemasan sobek')
        ->and(InventoryMovement::query()->count())->toBe(2)
        ->and((float) InventoryMovement::query()->ofType(InventoryMovement::TYPE_PURCHASE)->sum('qty'))->toBe(0.5)
        // Pembelian 0,5 kg x Rp 13.000 tercatat tunai; harga master ikut nota.
        ->and((float) $requisition->fresh()->purchases()->sum('total_value'))->toBe(6500.0)
        ->and((float) $line->fresh()->item->unit_price)->toBe(13000.0);

    // Catat aktual 1,1 kg lalu tutup: pemakaian terposting, SPK selesai.
    Livewire::test(ProductionOrderResource\Pages\RequisitionForm::class, ['record' => $order->id])
        ->fillForm(['lines' => [[
            'id' => $line->id,
            'actual_used_qty' => 1.1,
            'remaining_qty' => null,
        ]]])
        ->callAction('tutup')
        ->assertHasNoActionErrors();

    expect($order->fresh()->status)->toBe(ProductionOrder::STATUS_COMPLETED)
        ->and((float) InventoryMovement::query()->ofType(InventoryMovement::TYPE_USAGE)->sum('qty'))->toBe(-1.1);
});

it('tidak menampilkan tombol ajukan sebelum form disusun dan menolak pengajuan tanpa stok awal', function () {
    $order = app(\App\Services\ProductionOrderService::class)->generateFromSpk($this->spk, $this->user->id);

    Livewire::test(ProductionOrderResource\Pages\RequisitionForm::class, ['record' => $order->id])
        ->assertActionHidden('ajukan')
        ->callAction('susun')
        ->assertActionVisible('ajukan')
        ->assertActionHidden('setujui')
        // Stok Awal belum diisi: layanan menolak, dan penolakannya harus sampai
        // ke pengguna sebagai notifikasi -- bukan menghilang di balik halaman.
        ->callAction('ajukan')
        ->assertNotified('Belum bisa diajukan');

    expect($order->fresh()->requisition->status)->toBe(Requisition::STATUS_DRAFT);
});

it('menyalin template kerja menu ke lembar kerja spk', function () {
    $order = app(\App\Services\ProductionOrderService::class)->generateFromSpk($this->spk, $this->user->id);

    Livewire::test(ProductionOrderResource\Pages\ProductionTasks::class, ['record' => $order->id])
        ->callAction('salin')
        ->assertNotified('1 pekerjaan disalin');

    $task = $order->tasks()->first();

    expect($task->task)->toBe('goreng')
        ->and($task->worker_name)->toBe('Mia')
        ->and($task->menu_label)->toBe('GORENGAN 10K');
});

it('menampilkan komponen plating dan mengunduh dokumen pdf', function () {
    $order = app(\App\Services\ProductionOrderService::class)->generateFromSpk($this->spk, $this->user->id);

    Livewire::test(ProductionOrderResource\Pages\PlatingSheet::class, ['record' => $order->id])
        ->assertSee('GORENGAN 10K')
        // Kelompok resep ("Adonan") menjadi komponen di piring.
        ->assertSee('Adonan')
        ->callAction('cetak')
        ->assertFileDownloaded('plating-'.$order->number.'.pdf');

    Livewire::test(ProductionOrderResource\Pages\EditProductionOrder::class, ['record' => $order->id])
        ->callAction('cetak')
        ->assertFileDownloaded($order->number.'.pdf');
});

it('menampilkan daftar form, ledger, dan perbandingan hpp', function () {
    $order = app(\App\Services\ProductionOrderService::class)->generateFromSpk($this->spk, $this->user->id);
    $service = app(\App\Services\RequisitionService::class);
    $requisition = $service->build($order)['requisition'];
    $service->fillOpeningStock($requisition->lines[0], 0);
    $service->submit($requisition->fresh());
    $service->approve($requisition->fresh());
    bayarTunai($requisition->fresh());
    $service->check($requisition->fresh());
    app(\App\Services\ProductionCompletionService::class)->complete($order->fresh());

    Livewire::test(RequisitionResource\Pages\ListRequisitions::class)
        ->assertCanSeeTableRecords([$requisition]);

    Livewire::test(InventoryMovementResource\Pages\ListInventoryMovements::class)
        ->assertCanSeeTableRecords(InventoryMovement::query()->get());

    // 1 kg x Rp 12.000 = Rp 12.000 pemakaian resep di periode ini.
    Livewire::test(HppComparisonReport::class)
        ->fillForm(['date_from' => '2026-09-01', 'date_to' => '2026-09-30'])
        ->assertSee('Rp 12.000,00');
});

it('menampilkan jumlah porsi sebagai bilangan bulat dan menolak pecahan', function () {
    Livewire::test(ProductionOrderResource\Pages\ListProductionOrders::class)
        ->callAction('dari_spk', ['spk_id' => $this->spk->id]);

    $order = ProductionOrder::query()->firstWhere('spk_id', $this->spk->id);
    $line = $order->lines[0];

    // Kolom DB decimal(15,4) menyimpan "40.0000"; form harus menampilkan 40, bukan 40.0000 / 40,00.
    $page = Livewire::test(ProductionOrderResource\Pages\EditProductionOrder::class, ['record' => $order->getRouteKey()])
        ->assertFormSet(fn (array $state) => expect($state['lines'])->toHaveCount(1)
            ->and(collect($state['lines'])->first()['qty'])->toBe(40));

    // Kontrol positif: bilangan bulat tersimpan (item repeater diubah lewat kuncinya sendiri).
    $withQty = function ($qty) use ($page): array {
        $lines = $page->get('data.lines');
        foreach ($lines as &$row) {
            $row['qty'] = $qty;
        }

        return $lines;
    };

    $page->fillForm(['lines' => $withQty(3)])
        ->call('save')
        ->assertHasNoFormErrors();
    expect((float) $line->fresh()->qty)->toBe(3.0);

    // 3,14 porsi ditolak.
    $page->fillForm(['lines' => $withQty(3.14)])
        ->call('save')
        ->assertHasFormErrors();
    expect((float) $line->fresh()->qty)->toBe(3.0);
});
