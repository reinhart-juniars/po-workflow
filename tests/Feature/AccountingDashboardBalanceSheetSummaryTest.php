<?php

use App\Models\CashAccount;
use App\Models\InventoryItem;
use App\Models\InventoryOpening;
use App\Models\OpeningBalance;
use App\Models\Payable;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    Role::findOrCreate('accounting', 'web');
});

it('shows balance sheet summary cards on the accounting dashboard', function () {
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
        'unit_cost' => 2500,
        'total_value' => 25000,
    ]);

    $openingPayable = OpeningBalance::query()->create([
        'balance_date' => '2026-03-01',
        'type' => 'payable',
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

    get(route('accountingapp.dashboard', [
        'date_from' => '2026-03-01',
        'date_to' => '2026-03-31',
    ]))
        ->assertOk()
        ->assertSeeText('Ringkasan Neraca')
        ->assertSeeText('Buka Laporan Neraca')
        ->assertSeeText('Total Aset')
        ->assertSeeText('Total Kewajiban')
        ->assertSeeText('Modal')
        ->assertSeeText('Rp 175.000')
        ->assertSeeText('Rp 20.000')
        ->assertSeeText('Rp 155.000');
});
