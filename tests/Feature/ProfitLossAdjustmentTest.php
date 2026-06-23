<?php

use App\Models\ProfitLossAdjustment;
use App\Models\User;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-07 10:00:00'));

    Role::findOrCreate('accounting', 'web');
    Role::findOrCreate('owner', 'web');
    Role::findOrCreate('superadmin', 'web');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('lets owner create historical profit loss adjustments and includes them in profit reports', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('owner');

    actingAs($user);

    $token = 'profit-loss-adjustment-test';
    session(['_token' => $token]);

    post(route('accountingapp.profit-loss-adjustments.store'), [
        '_token' => $token,
        'adjustment_date' => '2026-04-30',
        'group' => ProfitLossAdjustment::GROUP_REVENUE,
        'label' => 'Koreksi pendapatan April',
        'amount' => 100000,
        'notes' => 'Data histori owner',
    ])->assertRedirect(route('accountingapp.profit-loss-adjustments.index'));

    post(route('accountingapp.profit-loss-adjustments.store'), [
        '_token' => $token,
        'adjustment_date' => '2026-04-30',
        'group' => ProfitLossAdjustment::GROUP_COGS,
        'label' => 'Koreksi HPP April',
        'amount' => 25000,
        'notes' => 'Data histori owner',
    ])->assertRedirect(route('accountingapp.profit-loss-adjustments.index'));

    post(route('accountingapp.profit-loss-adjustments.store'), [
        '_token' => $token,
        'adjustment_date' => '2026-04-30',
        'group' => ProfitLossAdjustment::GROUP_OPERATING_EXPENSE,
        'label' => 'Koreksi beban April',
        'amount' => 5000,
        'notes' => 'Data histori owner',
    ])->assertRedirect(route('accountingapp.profit-loss-adjustments.index'));

    post(route('accountingapp.profit-loss-adjustments.store'), [
        '_token' => $token,
        'adjustment_date' => '2026-04-30',
        'group' => ProfitLossAdjustment::GROUP_OTHER_INCOME,
        'label' => 'Koreksi pendapatan lain April',
        'amount' => -10000,
        'notes' => 'Data histori owner',
    ])->assertRedirect(route('accountingapp.profit-loss-adjustments.index'));

    get(route('accountingapp.reports.profit-loss', [
        'date_from' => '2026-04-01',
        'date_to' => '2026-04-30',
    ]))
        ->assertOk()
        ->assertSeeText('Koreksi pendapatan April')
        ->assertSeeText('Koreksi HPP April')
        ->assertSeeText('Koreksi beban April')
        ->assertSeeText('Koreksi pendapatan lain April')
        ->assertSeeText('Rp 100.000')
        ->assertSeeText('Rp 25.000')
        ->assertSeeText('Rp 5.000')
        ->assertSeeText('Rp 60.000')
        ->assertViewHas('statement', function (array $statement) {
            return (float) $statement['salesRevenue'] === 100000.0
                && (float) $statement['cogsTotal'] === 25000.0
                && (float) $statement['operatingExpenseTotal'] === 5000.0
                && (float) $statement['otherIncomeTotal'] === -10000.0
                && (float) $statement['netProfit'] === 60000.0;
        });

    get(route('accountingapp.reports.balance-sheet', [
        'report_date' => '2026-04-30',
    ]))
        ->assertOk()
        ->assertViewHas('equityRows', function ($equityRows) {
            return (float) $equityRows->firstWhere('label', 'Laba Berjalan')['amount'] === 60000.0;
        });
});

it('blocks current month profit loss adjustments', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('superadmin');

    actingAs($user);

    $token = 'profit-loss-current-month-adjustment-test';
    session(['_token' => $token]);

    post(route('accountingapp.profit-loss-adjustments.store'), [
        '_token' => $token,
        'adjustment_date' => '2026-05-01',
        'group' => ProfitLossAdjustment::GROUP_REVENUE,
        'label' => 'Koreksi bulan berjalan',
        'amount' => 100000,
    ])
        ->assertSessionHasErrors('adjustment_date');
});

it('lets owner edit historical profit loss adjustments', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('owner');

    $adjustment = ProfitLossAdjustment::query()->create([
        'adjustment_date' => '2026-04-15',
        'statement_group' => ProfitLossAdjustment::GROUP_REVENUE,
        'label' => 'Koreksi lama',
        'amount' => 50000,
        'notes' => 'Catatan lama',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    actingAs($user);

    get(route('accountingapp.profit-loss-adjustments.edit', $adjustment))
        ->assertOk()
        ->assertSeeText('Edit Adjustment Laba Rugi')
        ->assertSee('Koreksi lama');

    $token = 'profit-loss-adjustment-update-test';
    session(['_token' => $token]);

    put(route('accountingapp.profit-loss-adjustments.update', $adjustment), [
        '_token' => $token,
        'adjustment_date' => '2026-04-30',
        'group' => ProfitLossAdjustment::GROUP_OPERATING_EXPENSE,
        'label' => 'Koreksi beban April',
        'amount' => 12500,
        'notes' => 'Catatan baru',
    ])->assertRedirect(route('accountingapp.profit-loss-adjustments.index'));

    $adjustment->refresh();

    expect($adjustment->adjustment_date->toDateString())->toBe('2026-04-30')
        ->and($adjustment->statement_group)->toBe(ProfitLossAdjustment::GROUP_OPERATING_EXPENSE)
        ->and($adjustment->label)->toBe('Koreksi beban April')
        ->and((float) $adjustment->amount)->toBe(12500.0)
        ->and($adjustment->notes)->toBe('Catatan baru');
});

it('blocks updating profit loss adjustments into the current month', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('superadmin');

    $adjustment = ProfitLossAdjustment::query()->create([
        'adjustment_date' => '2026-04-15',
        'statement_group' => ProfitLossAdjustment::GROUP_REVENUE,
        'label' => 'Koreksi histori',
        'amount' => 50000,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    actingAs($user);

    $token = 'profit-loss-current-month-update-test';
    session(['_token' => $token]);

    put(route('accountingapp.profit-loss-adjustments.update', $adjustment), [
        '_token' => $token,
        'adjustment_date' => '2026-05-01',
        'group' => ProfitLossAdjustment::GROUP_REVENUE,
        'label' => 'Koreksi bulan berjalan',
        'amount' => 100000,
    ])
        ->assertSessionHasErrors('adjustment_date');
});

it('hides and blocks profit loss adjustments for regular accounting users', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('accounting');

    actingAs($user);

    get(route('accountingapp.dashboard'))
        ->assertOk()
        ->assertDontSeeText('Adjustment Laba Rugi');

    get(route('accountingapp.profit-loss-adjustments.index'))
        ->assertForbidden();
});
