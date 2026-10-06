<?php

use App\Filament\Resources\InventoryItemResource;
use App\Filament\Resources\InventoryOpeningResource;
use App\Filament\Resources\StockOpnameResource;
use App\Filament\Widgets\LowStockAlertWidget;
use App\Models\InventoryItem;
use App\Models\InventoryOpening;
use App\Models\InventoryPurchase;
use App\Models\StockOpname;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $this->user->assignRole('admin');

    $this->actingAs($this->user);
});

it('menyimpan item inventaris beserta ambang stok minimumnya', function () {
    Livewire::test(InventoryItemResource\Pages\CreateInventoryItem::class)
        ->fillForm([
            'name' => 'Tepung Terigu',
            'unit' => 'kg',
            'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
            'minimum_stock_value' => 500000,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $item = InventoryItem::query()->firstWhere('name', 'Tepung Terigu');

    expect($item)->not->toBeNull()
        ->and((float) $item->minimum_stock_value)->toBe(500000.0)
        ->and($item->category)->toBe(InventoryItem::CATEGORY_RAW_MATERIAL);
});

it('menolak menghapus item yang sudah dipakai transaksi', function () {
    $terpakai = InventoryItem::query()->create([
        'name' => 'Tepung Terigu',
        'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'is_active' => true,
    ]);

    InventoryPurchase::query()->create([
        'inventory_item_id' => $terpakai->id,
        'transaction_date' => '2026-03-10',
        'qty' => 1,
        'unit_cost' => 100000,
        'total_value' => 100000,
        'payment_type' => 'cash',
    ]);

    expect(InventoryItemResource::transactionBlockers($terpakai))->toBe(['pembelian stok (1)']);

    Livewire::test(InventoryItemResource\Pages\ListInventoryItems::class)
        ->callTableAction('delete', $terpakai);

    expect(InventoryItem::query()->whereKey($terpakai->id)->exists())->toBeTrue();

    // Positive control: item tanpa transaksi tetap bisa dihapus, jadi yang diuji
    // di atas benar-benar pagarnya, bukan tombol hapus yang rusak.
    $bersih = InventoryItem::query()->create([
        'name' => 'Item Belum Dipakai',
        'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'is_active' => true,
    ]);

    expect(InventoryItemResource::transactionBlockers($bersih))->toBe([]);

    Livewire::test(InventoryItemResource\Pages\ListInventoryItems::class)
        ->callTableAction('delete', $bersih);

    expect(InventoryItem::query()->whereKey($bersih->id)->exists())->toBeFalse();
});

it('menyimpan stock opname dengan konvensi nilai qty satu', function () {
    $item = InventoryItem::query()->create([
        'name' => 'Tepung Terigu',
        'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'is_active' => true,
    ]);

    Livewire::test(StockOpnameResource\Pages\CreateStockOpname::class)
        ->fillForm([
            'inventory_item_id' => $item->id,
            'opname_date' => '2026-03-31',
            'unit_cost' => 250000,
            'notes' => 'Opname akhir Maret',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $opname = StockOpname::query()->firstWhere('inventory_item_id', $item->id);

    // total_value dihitung model dari qty x unit_cost; qty harus tetap 1 supaya
    // laporan pemakaian bahan membacanya sebagai nilai penuh.
    expect((float) $opname->qty)->toBe(1.0)
        ->and((float) $opname->total_value)->toBe(250000.0)
        ->and($opname->created_by)->toBe($this->user->id);
});

it('menyimpan saldo awal dengan pencatat yang tercatat', function () {
    $item = InventoryItem::query()->create([
        'name' => 'Tepung Terigu',
        'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'is_active' => true,
    ]);

    Livewire::test(InventoryOpeningResource\Pages\CreateInventoryOpening::class)
        ->fillForm([
            'inventory_item_id' => $item->id,
            'balance_date' => '2026-03-01',
            'unit_cost' => 750000,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $opening = InventoryOpening::query()->firstWhere('inventory_item_id', $item->id);

    expect((float) $opening->total_value)->toBe(750000.0)
        ->and($opening->created_by)->toBe($this->user->id);
});

it('menampilkan widget alert hanya ketika ada item di bawah ambang', function () {
    $item = InventoryItem::query()->create([
        'name' => 'Tepung Terigu',
        'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'minimum_stock_value' => 500000,
        'is_active' => true,
    ]);

    InventoryPurchase::query()->create([
        'inventory_item_id' => $item->id,
        'transaction_date' => '2026-03-10',
        'qty' => 1,
        'unit_cost' => 100000,
        'total_value' => 100000,
        'payment_type' => 'cash',
    ]);

    expect(LowStockAlertWidget::canView())->toBeTrue();

    Livewire::test(LowStockAlertWidget::class)
        ->assertCanSeeTableRecords([$item]);

    // Positive control: begitu stoknya cukup, widget menghilang sepenuhnya
    // sehingga dashboard tidak menampilkan kartu kosong.
    $item->update(['minimum_stock_value' => 50000]);

    expect(LowStockAlertWidget::canView())->toBeFalse();
});

it('menampilkan daftar item inventaris muat satu layar: kolom induk disembunyikan dan aksi berupa ikon', function () {
    $bucket = InventoryItem::query()->create(['name' => 'Bahan Baku', 'unit' => 'kg', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);
    $item = InventoryItem::query()->create(['name' => 'Gula Pasir', 'unit' => 'kg', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'parent_id' => $bucket->id, 'is_active' => true]);

    // Induk hanya muncul bila dipilih lewat tombol kolom; kolom lain tetap tampil.
    $page = Livewire::test(InventoryItemResource\Pages\ListInventoryItems::class)
        ->assertCanSeeTableRecords([$item])
        ->assertCanRenderTableColumn('name')
        ->assertCanRenderTableColumn('stock_value')
        ->assertCanNotRenderTableColumn('parent.name')
        ->assertTableActionVisible('edit', $item)
        ->assertTableActionVisible('delete', $item);

    // Tombol ikon, bukan berteks, supaya kolom aksi tidak melebarkan tabel.
    $actions = $page->instance()->getTable()->getFlatActions();

    expect($actions['edit']->isIconButton())->toBeTrue()
        ->and($actions['delete']->isIconButton())->toBeTrue();
});

it('menjaga posisi sidebar dan prefetch menu di panel maupun layout Blade', function () {
    // Positive control: skrip bawaan Filament yang menggulir menu aktif ke
    // tengah memang ada, jadi penggantinya harus dirender sebelum ia berjalan
    // (di akhir <nav>, sedangkan skrip Filament menunggu DOMContentLoaded).
    $this->get(InventoryItemResource::getUrl('index'))
        ->assertOk()
        ->assertSee('<script type="speculationrules">', false)
        // Animasi geser antar menu: opt-in inline (bukan dari Vite) dan
        // penanda akhir halaman yang ditunggu transisinya.
        ->assertSeeInOrder(['@view-transition', '</head>', 'id="sh-page-end"'], false)
        ->assertSee('sh-sidebar-scroll:inventory', false)
        ->assertSeeInOrder(['sh-sidebar-scroll:inventory', '</nav>', 'sidebarWrapper.scrollTo'], false);

    // Layout Blade memakai partial yang sama, dengan kunci per aplikasi.
    $this->get(route('adminapp.dashboard'))
        ->assertOk()
        ->assertSee('<script type="speculationrules">', false)
        ->assertSeeInOrder(['@view-transition', '</head>', 'id="sh-page-end"'], false)
        ->assertSeeInOrder(['sh-sidebar-scroll:admin', '</nav>'], false);
});

it('menggambar panel utuh sejak frame pertama, tanpa menunggu Alpine atau muatan susulan', function () {
    // Tanpa aturan ini, sidebar & konten tersembunyi sampai Alpine berjalan
    // sehingga setiap perpindahan menu terasa seperti halaman di-refresh.
    $this->get(InventoryItemResource::getUrl('index'))
        ->assertOk()
        ->assertSee('.fi-main-ctn.opacity-0', false)
        ->assertSee(".fi-main-sidebar[x-cloak='-lg']", false)
        // Lonceng notifikasi ikut dirender, bukan placeholder lazy Livewire.
        ->assertDontSee('__lazyLoad', false);
});
