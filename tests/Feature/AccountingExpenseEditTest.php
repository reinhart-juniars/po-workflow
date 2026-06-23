<?php

use App\Models\CashAccount;
use App\Models\CashOut;
use App\Models\ExpenseCategory;
use App\Models\ExpenseLocation;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\put;

beforeEach(function () {
    Role::findOrCreate('accounting', 'web');
});

it('lets accounting user edit an expense from accounting app', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('accounting');

    $category = ExpenseCategory::query()->create([
        'name' => 'Operasional',
        'expense_mode' => ExpenseCategory::MODE_DIRECT_EXPENSE,
        'is_active' => true,
    ]);

    $newCategory = ExpenseCategory::query()->create([
        'name' => 'Transport',
        'expense_mode' => ExpenseCategory::MODE_DIRECT_EXPENSE,
        'is_active' => true,
    ]);

    $cashAccount = CashAccount::query()->create([
        'name' => 'Kas Utama',
        'type' => 'cash',
        'is_active' => true,
    ]);

    $newCashAccount = CashAccount::query()->create([
        'name' => 'Bank Utama',
        'type' => 'bank',
        'is_active' => true,
    ]);

    $expense = CashOut::query()->create([
        'expense_category_id' => $category->id,
        'expense_location_id' => ExpenseLocation::query()->create([
            'name' => 'Pusat',
            'type' => 'center',
            'is_active' => true,
        ])->id,
        'cash_account_id' => $cashAccount->id,
        'amount' => 15000,
        'expense_date' => '2026-04-22',
        'description' => 'Biaya awal',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    actingAs($user);

    get(route('accountingapp.expenses.index', [
        'date_from' => '2026-04-01',
        'date_to' => '2026-04-30',
    ]))
        ->assertOk()
        ->assertSeeText('Edit')
        ->assertSeeText('Hapus');

    get(route('accountingapp.expenses.edit', $expense))
        ->assertOk()
        ->assertSeeText('Edit Pengeluaran')
        ->assertSee('Biaya awal');

    put(route('accountingapp.expenses.update', $expense), [
        'expense_date' => '2026-04-23',
        'expense_category_id' => $newCategory->id,
        'cash_account_id' => $newCashAccount->id,
        'amount' => 27500,
        'description' => 'Biaya transport update',
    ])->assertRedirect(route('accountingapp.expenses.index'));

    $expense->refresh();

    expect($expense->expense_date->toDateString())->toBe('2026-04-23')
        ->and($expense->expense_category_id)->toBe($newCategory->id)
        ->and($expense->cash_account_id)->toBe($newCashAccount->id)
        ->and((float) $expense->amount)->toBe(27500.0)
        ->and($expense->description)->toBe('Biaya transport update');
});

it('lets accounting user delete an open-period expense from accounting app', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('accounting');

    $category = ExpenseCategory::query()->create([
        'name' => 'Operasional',
        'expense_mode' => ExpenseCategory::MODE_DIRECT_EXPENSE,
        'is_active' => true,
    ]);

    $cashAccount = CashAccount::query()->create([
        'name' => 'Kas Utama',
        'type' => 'cash',
        'is_active' => true,
    ]);

    $expense = CashOut::query()->create([
        'expense_category_id' => $category->id,
        'expense_location_id' => ExpenseLocation::query()->create([
            'name' => 'Pusat',
            'type' => 'center',
            'is_active' => true,
        ])->id,
        'cash_account_id' => $cashAccount->id,
        'amount' => 15000,
        'expense_date' => '2026-04-22',
        'description' => 'Biaya hapus',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    actingAs($user);

    delete(route('accountingapp.expenses.destroy', $expense))
        ->assertRedirect(route('accountingapp.expenses.index'))
        ->assertSessionHas('success', 'Pengeluaran berhasil dihapus.');

    expect(CashOut::query()->whereKey($expense->id)->exists())->toBeFalse();
});
