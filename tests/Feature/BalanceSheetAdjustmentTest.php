<?php

use App\Models\BalanceSheetAdjustment;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(function () {
    Role::findOrCreate('accounting', 'web');
    Role::findOrCreate('owner', 'web');
    Role::findOrCreate('superadmin', 'web');
});

it('lets owner create balance sheet adjustments and includes them in the report', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('owner');

    actingAs($user);

    $token = 'balance-sheet-adjustment-test';
    session(['_token' => $token]);

    post(route('accountingapp.balance-sheet-adjustments.store'), [
        '_token' => $token,
        'adjustment_date' => '2026-01-31',
        'group' => BalanceSheetAdjustment::GROUP_CASH,
        'label' => 'Koreksi kas Januari',
        'amount' => 120000,
        'notes' => 'Data owner',
    ])->assertRedirect(route('accountingapp.balance-sheet-adjustments.index'));

    post(route('accountingapp.balance-sheet-adjustments.store'), [
        '_token' => $token,
        'adjustment_date' => '2026-01-31',
        'group' => BalanceSheetAdjustment::GROUP_PAYABLE,
        'label' => 'Koreksi kewajiban Januari',
        'amount' => 30000,
        'notes' => 'Data owner',
    ])->assertRedirect(route('accountingapp.balance-sheet-adjustments.index'));

    post(route('accountingapp.balance-sheet-adjustments.store'), [
        '_token' => $token,
        'adjustment_date' => '2026-01-31',
        'group' => BalanceSheetAdjustment::GROUP_FIXED_ASSET,
        'label' => 'Koreksi inventaris Januari',
        'amount' => 45000,
        'notes' => 'Data owner',
    ])->assertRedirect(route('accountingapp.balance-sheet-adjustments.index'));

    post(route('accountingapp.balance-sheet-adjustments.store'), [
        '_token' => $token,
        'adjustment_date' => '2026-01-31',
        'group' => BalanceSheetAdjustment::GROUP_WEALTH,
        'label' => 'Koreksi kekayaan Januari',
        'amount' => 15000,
        'notes' => 'Data owner',
    ])->assertRedirect(route('accountingapp.balance-sheet-adjustments.index'));

    get(route('accountingapp.reports.balance-sheet', [
        'report_date' => '2026-01-31',
    ]))
        ->assertOk()
        ->assertSeeText('Koreksi kas Januari')
        ->assertSeeText('Koreksi kewajiban Januari')
        ->assertSeeText('Koreksi inventaris Januari')
        ->assertSeeText('Koreksi kekayaan Januari')
        ->assertSeeText('Aktiva Tetap')
        ->assertSeeText('Kekayaan')
        ->assertSeeText('Rp 120.000')
        ->assertSeeText('Rp 45.000')
        ->assertSeeText('Rp 30.000')
        ->assertSeeText('Rp 15.000')
        ->assertSeeText('Rp 135.000');
});

it('hides and blocks balance sheet adjustments for regular accounting users', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('accounting');

    actingAs($user);

    get(route('accountingapp.dashboard'))
        ->assertOk()
        ->assertDontSeeText('Adjustment Neraca');

    get(route('accountingapp.balance-sheet-adjustments.index'))
        ->assertForbidden();
});

it('lets owner edit balance sheet adjustments', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('owner');

    $adjustment = BalanceSheetAdjustment::query()->create([
        'adjustment_date' => '2026-01-31',
        'account_group' => BalanceSheetAdjustment::GROUP_CASH,
        'label' => 'Koreksi lama',
        'amount' => 100000,
        'notes' => 'Catatan lama',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    actingAs($user);

    get(route('accountingapp.balance-sheet-adjustments.edit', $adjustment))
        ->assertOk()
        ->assertSeeText('Edit Adjustment Neraca')
        ->assertSee('Koreksi lama');

    $token = 'balance-sheet-adjustment-update-test';
    session(['_token' => $token]);

    put(route('accountingapp.balance-sheet-adjustments.update', $adjustment), [
        '_token' => $token,
        'adjustment_date' => '2026-02-28',
        'group' => BalanceSheetAdjustment::GROUP_WEALTH,
        'label' => 'Koreksi kekayaan Februari',
        'amount' => -25000,
        'notes' => 'Catatan baru',
    ])->assertRedirect(route('accountingapp.balance-sheet-adjustments.index'));

    $adjustment->refresh();

    expect($adjustment->adjustment_date->toDateString())->toBe('2026-02-28')
        ->and($adjustment->account_group)->toBe(BalanceSheetAdjustment::GROUP_WEALTH)
        ->and($adjustment->label)->toBe('Koreksi kekayaan Februari')
        ->and((float) $adjustment->amount)->toBe(-25000.0)
        ->and($adjustment->notes)->toBe('Catatan baru');
});
