<?php

use App\Models\InventoryItem;
use App\Models\InventoryPurchase;
use App\Models\StockOpname;
use App\Models\User;
use App\Services\InventoryUsageService;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * Barang yang datang dalam kondisi tidak baik tidak menambah stok tersedia,
 * tetapi uangnya sudah keluar. Nilainya karena itu dipindahkan dari Bahan Baku
 * ke pos "Kerugian Barang Rusak" -- total Laba harus tetap, hanya komposisinya
 * yang bergeser.
 */
beforeEach(function () {
    Role::findOrCreate('accounting', 'web');
    Role::findOrCreate('owner', 'web');

    $this->item = InventoryItem::query()->create([
        'name' => 'Tepung Terigu',
        'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'is_active' => true,
    ]);

    // Barang baik Rp 100.000 dan barang rusak Rp 30.000 di periode yang sama.
    $this->goodPurchase = InventoryPurchase::query()->create([
        'inventory_item_id' => $this->item->id,
        'transaction_date' => '2026-03-10',
        'qty' => 1,
        'unit_cost' => 100000,
        'total_value' => 100000,
        'payment_type' => 'cash',
        'condition' => InventoryPurchase::CONDITION_GOOD,
        'supplier_name' => 'Supplier A',
    ]);

    $this->damagedPurchase = InventoryPurchase::query()->create([
        'inventory_item_id' => $this->item->id,
        'transaction_date' => '2026-03-12',
        'qty' => 1,
        'unit_cost' => 30000,
        'total_value' => 30000,
        'payment_type' => 'cash',
        'condition' => InventoryPurchase::CONDITION_DAMAGED,
        'condition_notes' => 'Karung basah, tepung menggumpal',
        'supplier_name' => 'Supplier A',
    ]);

    StockOpname::query()->create([
        'inventory_item_id' => $this->item->id,
        'opname_date' => '2026-03-31',
        'qty' => 1,
        'unit_cost' => 40000,
        'total_value' => 40000,
    ]);
});

it('tidak menghitung barang rusak sebagai stok masuk di laporan pemakaian bahan', function () {
    $summary = app(InventoryUsageService::class)->calculateForItem(
        $this->item->id,
        Carbon::parse('2026-03-01'),
        Carbon::parse('2026-03-31')
    );

    // Positive control: barang yang datang baik tetap dihitung penuh.
    expect((float) $summary['purchases'])->toBe(100000.0);

    // Pemakaian = 0 + 100.000 - 40.000. Kalau barang rusak ikut terhitung,
    // angkanya akan menjadi 90.000.
    expect((float) $summary['usage'])->toBe(60000.0)
        ->and((float) $summary['ending'])->toBe(40000.0);
});

it('memindahkan nilai barang rusak ke pos kerugian tanpa mengubah total laba', function () {
    $user = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $user->assignRole('owner');
    actingAs($user);

    $response = get(route('accountingapp.reports.profit-loss', [
        'date_from' => '2026-03-01',
        'date_to' => '2026-03-31',
    ]))->assertOk();

    $profitLoss = $response->viewData('profitLoss');

    // Bahan Baku hanya berisi barang yang benar-benar masuk stok.
    expect((float) $profitLoss['bahanBakuBaru'])->toBe(100000.0)
        ->and((float) $profitLoss['bahanBakuTerpakai'])->toBe(60000.0);

    // Nilai barang rusak muncul sebagai baris pengeluaran tersendiri.
    $lossRow = collect($profitLoss['pengeluaranRows'])->firstWhere('label', 'Kerugian Barang Rusak');
    expect($lossRow)->not->toBeNull()
        ->and((float) $lossRow['amount'])->toBe(30000.0);

    // Inti kesepakatannya: yang dikurangi dari Bahan Baku sama persis dengan
    // yang ditambahkan ke pengeluaran, sehingga Laba tidak bergeser.
    // 0 penjualan - 60.000 bahan baku - 30.000 pengeluaran = -90.000, sama
    // dengan bila barang rusak tetap dihitung sebagai bahan baku (0 - 90.000 - 0).
    expect((float) $profitLoss['labaRugi'])->toBe(-90000.0);

    $response->assertSeeText('Kerugian Barang Rusak');
});

it('memindahkan kerugian barang rusak dari HPP ke beban operasional pada statement', function () {
    $user = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $user->assignRole('owner');
    actingAs($user);

    $statement = get(route('accountingapp.reports.profit-loss', [
        'date_from' => '2026-03-01',
        'date_to' => '2026-03-31',
    ]))->assertOk()->viewData('statement');

    expect((float) $statement['cogsTotal'])->toBe(60000.0)
        ->and((float) $statement['operatingExpenseTotal'])->toBe(30000.0)
        ->and((float) $statement['netProfit'])->toBe(-90000.0);
});

it('tidak menghitung barang rusak sebagai aset persediaan di neraca', function () {
    $user = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $user->assignRole('owner');
    actingAs($user);

    // Tanpa opname penutup, neraca jatuh ke fallback saldo awal + pembelian.
    StockOpname::query()->delete();

    $response = get(route('accountingapp.reports.balance-sheet', [
        'report_date' => '2026-03-31',
    ]))->assertOk();

    $persediaan = collect($response->viewData('assetGroups'))->firstWhere('title', 'Persediaan');
    $inventoryTotal = (float) $persediaan['total'];

    // Hanya barang baik yang menjadi aset; barang rusak tidak pernah masuk stok.
    expect($inventoryTotal)->toBe(100000.0);
});
