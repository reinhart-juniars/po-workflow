<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\InventoryItemResource;
use App\Filament\Widgets\LowStockAlertWidget;
use App\Models\InventoryItem;
use App\Models\InventoryOpening;
use App\Models\InventoryPurchase;
use App\Models\StockOpname;
use App\Models\User;
use App\Services\InventoryStockAlertService;
use Carbon\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function buatItem(array $attributes = []): InventoryItem
{
    return InventoryItem::query()->create(array_merge([
        'name' => 'Tepung Terigu',
        'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'is_active' => true,
    ], $attributes));
}

function catatPembelian(InventoryItem $item, string $date, float $value, string $condition = InventoryPurchase::CONDITION_GOOD): InventoryPurchase
{
    return InventoryPurchase::query()->create([
        'inventory_item_id' => $item->id,
        'transaction_date' => $date,
        'qty' => 1,
        'unit_cost' => $value,
        'total_value' => $value,
        'payment_type' => 'cash',
        'condition' => $condition,
    ]);
}

it('menghitung nilai stok dari opname terakhir ditambah pembelian sesudahnya', function () {
    $item = buatItem();

    InventoryOpening::query()->create([
        'inventory_item_id' => $item->id,
        'balance_date' => '2026-03-01',
        'qty' => 1,
        'unit_cost' => 500000,
        'total_value' => 500000,
    ]);

    catatPembelian($item, '2026-03-05', 200000);

    StockOpname::query()->create([
        'inventory_item_id' => $item->id,
        'opname_date' => '2026-03-20',
        'qty' => 1,
        'unit_cost' => 300000,
        'total_value' => 300000,
    ]);

    catatPembelian($item, '2026-03-25', 150000);

    $stock = app(InventoryStockAlertService::class)
        ->currentStockValue($item->id, Carbon::parse('2026-03-31'));

    // Opname 300.000 sudah memperhitungkan saldo awal dan pembelian sebelumnya,
    // jadi yang ditambahkan hanya pembelian sesudah tanggal opname.
    expect($stock['value'])->toBe(450000.0)
        ->and($stock['basis'])->toBe('opname');
});

it('tidak menghitung barang rusak sebagai stok berjalan', function () {
    $item = buatItem();

    catatPembelian($item, '2026-03-05', 200000);
    catatPembelian($item, '2026-03-06', 80000, InventoryPurchase::CONDITION_DAMAGED);

    $stock = app(InventoryStockAlertService::class)
        ->currentStockValue($item->id, Carbon::parse('2026-03-31'));

    // Positive control ada di angkanya sendiri: pembelian baik tetap terhitung
    // penuh (200.000), sementara yang rusak tidak menambah apa pun.
    expect($stock['value'])->toBe(200000.0)
        ->and($stock['basis'])->toBe('saldo_awal');
});

it('memunculkan item yang nilai stoknya di bawah ambang, dan hanya yang itu', function () {
    $dibawah = buatItem(['name' => 'Tepung Terigu', 'minimum_stock_value' => 500000]);
    catatPembelian($dibawah, '2026-03-05', 100000);

    // Positive control: item yang stoknya cukup tidak boleh ikut muncul.
    $aman = buatItem(['name' => 'Gula Pasir', 'minimum_stock_value' => 100000]);
    catatPembelian($aman, '2026-03-05', 900000);

    $alerts = app(InventoryStockAlertService::class)->alerts(Carbon::parse('2026-03-31'));

    expect($alerts)->toHaveCount(1);
    expect($alerts->first()['item']->name)->toBe('Tepung Terigu')
        ->and($alerts->first()['stock_value'])->toBe(100000.0)
        ->and($alerts->first()['shortfall'])->toBe(400000.0);
});

it('mengabaikan item tanpa ambang dan item nonaktif', function () {
    // Tanpa ambang: alert harus dinyalakan secara sadar, bukan default.
    $tanpaAmbang = buatItem(['name' => 'Garam']);
    catatPembelian($tanpaAmbang, '2026-03-05', 0.0);

    $nonaktif = buatItem([
        'name' => 'Santan Kara',
        'minimum_stock_value' => 500000,
        'is_active' => false,
    ]);
    catatPembelian($nonaktif, '2026-03-05', 1000);

    // Positive control: item aktif berambang yang stoknya kurang tetap muncul.
    $aktif = buatItem(['name' => 'Minyak Goreng', 'minimum_stock_value' => 500000]);
    catatPembelian($aktif, '2026-03-05', 1000);

    $alerts = app(InventoryStockAlertService::class)->alerts(Carbon::parse('2026-03-31'));

    expect($alerts->pluck('item.name')->all())->toBe(['Minyak Goreng']);
});

it('menyampaikan alert stok minimum sebagai notifikasi di dashboard, sekali per hari', function () {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    // Tanpa item di bawah ambang: dashboard sunyi (positive control untuk kondisi di bawah).
    Livewire::test(Dashboard::class)->assertOk()->assertNotNotified();

    $item = buatItem(['minimum_stock_value' => 500000]);
    catatPembelian($item, '2026-03-05', 100000);

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertNotified('1 bahan di bawah stok minimum');

    // Kunjungan berikutnya di hari yang sama tidak mengulang notifikasinya;
    // widget dan badge navigasi tetap menampilkannya.
    Livewire::test(Dashboard::class)->assertOk()->assertNotNotified();
    expect(LowStockAlertWidget::canView())->toBeTrue()
        ->and(InventoryItemResource::getNavigationBadge())->toBe('1');

    // Peran tanpa izin inventory tidak diganggu notifikasi maupun widget.
    Role::findOrCreate('production', 'web')->revokePermissionTo('inventory.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $produksi = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $produksi->assignRole('production');
    $this->actingAs($produksi);
    session()->forget('inventory_stock_alert_notified_on');

    Livewire::test(Dashboard::class)->assertForbidden();
    expect(LowStockAlertWidget::canView())->toBeFalse();
});
