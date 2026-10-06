<?php

use App\Models\OpeningBalance;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    Role::findOrCreate('accounting', 'web');
});

it('shows opening payable rows even when no payable record exists', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('accounting');

    OpeningBalance::query()->create([
        'balance_date' => '2026-03-01',
        'type' => 'payable',
        'supplier_name' => 'Supplier Fallback',
        'amount' => 45000,
        'description' => 'Saldo awal hutang lama',
    ]);

    actingAs($user);

    get(route('accountingapp.reports.balance-sheet', [
        'report_date' => '2026-03-31',
    ]))
        ->assertOk()
        ->assertSeeText('Supplier Fallback')
        ->assertSeeText('Rp 45.000');
});
