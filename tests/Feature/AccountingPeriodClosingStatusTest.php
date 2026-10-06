<?php

use App\Models\PeriodClosing;
use App\Models\User;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    Role::findOrCreate('accounting', 'web');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('shows a warning when the previous period has not been closed after entering a new month', function () {
    Carbon::setTestNow('2026-04-10 09:00:00');

    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('accounting');

    actingAs($user);

    get(route('accountingapp.period-closings.index'))
        ->assertOk()
        ->assertSeeText('Saat ini sudah masuk April 2026, tetapi periode Maret 2026 masih aktif dan belum ditutup.');
});

it('hides the warning when the previous period has already been closed', function () {
    Carbon::setTestNow('2026-04-10 09:00:00');

    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('accounting');

    PeriodClosing::query()->create([
        'period_month' => 3,
        'period_year' => 2026,
        'closed_at' => now(),
        'closed_by' => $user->id,
        'notes' => 'Tutup bulan Maret',
    ]);

    actingAs($user);

    get(route('accountingapp.period-closings.index'))
        ->assertOk()
        ->assertDontSeeText('Saat ini sudah masuk April 2026, tetapi periode Maret 2026 masih aktif dan belum ditutup.');
});
