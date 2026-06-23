<?php

use App\Models\InventoryItem;
use App\Models\InventoryOpening;
use App\Models\InventoryPurchase;
use App\Models\StockOpname;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    Role::findOrCreate('accounting', 'web');
});

it('shows detailed rows for the selected inventory item usage report', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('accounting');

    $item = InventoryItem::query()->create([
        'name' => 'Tepung Terigu',
        'unit' => 'kg',
        'is_active' => true,
    ]);

    InventoryOpening::query()->create([
        'inventory_item_id' => $item->id,
        'balance_date' => '2026-03-01',
        'qty' => 10,
        'unit_cost' => 5,
        'total_value' => 50,
        'notes' => 'Saldo awal gudang',
    ]);

    InventoryPurchase::query()->create([
        'inventory_item_id' => $item->id,
        'transaction_date' => '2026-03-10',
        'qty' => 8,
        'unit_cost' => 5,
        'total_value' => 40,
        'payment_type' => 'cash',
        'supplier_name' => 'Supplier A',
        'notes' => 'Pembelian mingguan',
    ]);

    StockOpname::query()->create([
        'inventory_item_id' => $item->id,
        'opname_date' => '2026-03-30',
        'qty' => 2,
        'unit_cost' => 5,
        'total_value' => 10,
        'notes' => 'Stok akhir bulan',
    ]);

    actingAs($user);

    get(route('accountingapp.reports.inventory-usage', [
        'inventory_item_id' => $item->id,
        'date_from' => '2026-03-01',
        'date_to' => '2026-03-31',
    ]))
        ->assertOk()
        ->assertSeeText('Rincian Pemakaian Tepung Terigu (kg)')
        ->assertSeeText('Saldo Awal')
        ->assertSeeText('Pembelian')
        ->assertSeeText('Stock Opname')
        ->assertSeeText('Total Saldo Awal')
        ->assertSeeText('Total Pembelian')
        ->assertSeeText('Total Pemakaian')
        ->assertSeeText('Supplier: Supplier A | Tunai | Pembelian mingguan')
        ->assertSeeText('80,00');
});
