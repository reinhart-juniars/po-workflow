<?php

use App\Models\CashAccount;
use App\Models\InventoryItem;
use App\Models\InventoryOpening;
use App\Models\InventoryPurchase;
use App\Models\OpeningBalance;
use App\Models\Payable;
use App\Models\StockOpname;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    Role::findOrCreate('accounting', 'web');
});

it('groups inventory item categories into inventory and fixed asset balance sheet sections', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('accounting');

    $rawMaterial = InventoryItem::query()->create([
        'name' => 'Tepung',
        'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'is_active' => true,
    ]);

    $fixedAsset = InventoryItem::query()->create([
        'name' => 'Mixer',
        'unit' => 'unit',
        'category' => InventoryItem::CATEGORY_FIXED_ASSET,
        'is_active' => true,
    ]);

    InventoryOpening::query()->create([
        'inventory_item_id' => $rawMaterial->id,
        'balance_date' => '2026-03-01',
        'qty' => 10,
        'unit_cost' => 10000,
        'total_value' => 100000,
    ]);

    InventoryOpening::query()->create([
        'inventory_item_id' => $fixedAsset->id,
        'balance_date' => '2026-03-01',
        'qty' => 1,
        'unit_cost' => 150000,
        'total_value' => 150000,
    ]);

    InventoryPurchase::query()->create([
        'inventory_item_id' => $fixedAsset->id,
        'transaction_date' => '2026-03-15',
        'qty' => 1,
        'unit_cost' => 250000,
        'total_value' => 250000,
        'payment_type' => 'cash',
    ]);

    actingAs($user);

    get(route('accountingapp.reports.balance-sheet', [
        'report_date' => '2026-03-31',
    ]))
        ->assertOk()
        ->assertSeeText('Persediaan')
        ->assertSeeText('Aktiva Tetap')
        ->assertSeeText('Bahan Baku - Persediaan Akhir')
        ->assertSeeText('Mixer (unit) - Inventaris Lama')
        ->assertSeeText('Mixer (unit) - Inventaris Baru')
        ->assertSeeText('Rp 150.000')
        ->assertSeeText('Rp 250.000');
});

it('shows a balance sheet report with assets liabilities and equity totals', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('accounting');

    $cashAccount = CashAccount::query()->create([
        'name' => 'Kas Utama',
        'type' => 'cash',
        'is_active' => true,
    ]);

    OpeningBalance::query()->create([
        'balance_date' => '2026-03-01',
        'type' => 'cash',
        'reference_id' => $cashAccount->id,
        'amount' => 100000,
        'description' => 'Saldo awal kas',
    ]);

    OpeningBalance::query()->create([
        'balance_date' => '2026-03-01',
        'type' => 'receivable',
        'reference_id' => null,
        'amount' => 50000,
        'description' => 'Piutang awal pelanggan',
    ]);

    $inventoryItem = InventoryItem::query()->create([
        'name' => 'Gula Pasir',
        'unit' => 'kg',
        'is_active' => true,
    ]);

    InventoryOpening::query()->create([
        'inventory_item_id' => $inventoryItem->id,
        'balance_date' => '2026-03-01',
        'qty' => 10,
        'unit_cost' => 3000,
        'total_value' => 30000,
    ]);

    StockOpname::query()->create([
        'inventory_item_id' => $inventoryItem->id,
        'opname_date' => '2026-03-31',
        'qty' => 8,
        'unit_cost' => 3125,
        'total_value' => 25000,
    ]);

    $openingPayable = OpeningBalance::query()->create([
        'balance_date' => '2026-03-01',
        'type' => 'payable',
        'reference_id' => null,
        'supplier_name' => 'Supplier A',
        'amount' => 20000,
        'description' => 'Saldo awal hutang',
    ]);

    Payable::query()->create([
        'opening_balance_id' => $openingPayable->id,
        'transaction_date' => '2026-03-01',
        'supplier_name' => 'Supplier A',
        'description' => 'Saldo awal hutang',
        'amount' => 20000,
        'status' => 'unpaid',
    ]);

    actingAs($user);

    get(route('accountingapp.reports.balance-sheet', [
        'report_date' => '2026-03-31',
    ]))
        ->assertOk()
        ->assertSeeText('Laporan Neraca')
        ->assertSeeText('Kas')
        ->assertSeeText('Piutang Usaha')
        ->assertSeeText('Persediaan')
        ->assertSeeText('Kewajiban')
        ->assertSeeText('Modal Awal')
        ->assertSeeText('Kekayaan')
        ->assertSeeText('Laba Ditahan')
        ->assertSeeText('Laba Berjalan')
        ->assertSeeText('Rp 175.000')
        ->assertSeeText('Rp 20.000')
        ->assertSeeText('Rp 160.000')
        ->assertSeeText('(Rp 5.000)');
});
