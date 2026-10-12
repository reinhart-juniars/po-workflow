<?php

use App\Models\CashAccount;
use App\Models\CashOut;
use App\Models\ExpenseCategory;
use App\Models\ExpenseLocation;
use App\Models\User;
use App\Support\Settings\Settings;
use Spatie\Permission\Models\Role;

/**
 * Kategori "Mengurangi Kekayaan (di luar Laba Rugi)" -- mis. Biaya Marketing.
 * Di-port dari versi yang sudah berjalan di server (po-workflow lama, 2 Okt
 * 2026), dengan tanggal mulai `wealth_reduction.accounting_start` (bawaan
 * 1 Okt 2026): sebelum itu pengeluarannya tetap beban supaya Laba Rugi yang
 * sudah dilaporkan tidak bergeser.
 */
function akuntanKekayaan(): User
{
    Role::findOrCreate('accounting', 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole('accounting');

    return $user;
}

function keluarkan(ExpenseCategory $category, float $amount, string $date): CashOut
{
    return CashOut::query()->create([
        'expense_category_id' => $category->id,
        'expense_location_id' => ExpenseLocation::query()->firstOrCreate(['name' => 'Pusat'], ['type' => 'center', 'is_active' => true])->id,
        'cash_account_id' => CashAccount::query()->firstOrCreate(['name' => 'Kas Besar'], ['type' => 'cash', 'is_active' => true])->id,
        'amount' => $amount,
        'expense_date' => $date,
        'description' => 'tes',
    ]);
}

function laporanFinal(User $user, string $from, string $to)
{
    return test()->actingAs($user)
        ->get(route('accountingapp.reports.final', ['date_from' => $from, 'date_to' => $to]))
        ->assertOk();
}

it('menyediakan mode Mengurangi Kekayaan di master kategori dan menolak mode tak dikenal', function () {
    $akuntan = akuntanKekayaan();

    $this->actingAs($akuntan)->get(route('accountingapp.categories.index'))->assertOk()
        ->assertSee('Mengurangi Kekayaan (di luar Laba Rugi)');

    $this->actingAs($akuntan)->post(route('accountingapp.categories.store'), [
        'name' => 'Biaya Marketing', 'expense_mode' => ExpenseCategory::MODE_WEALTH_REDUCTION, 'is_active' => '1',
    ])->assertSessionHasNoErrors();
    expect(ExpenseCategory::query()->where('name', 'Biaya Marketing')->value('expense_mode'))->toBe(ExpenseCategory::MODE_WEALTH_REDUCTION);

    $this->actingAs($akuntan)->post(route('accountingapp.categories.store'), [
        'name' => 'Ngawur', 'expense_mode' => 'tidak_dikenal', 'is_active' => '1',
    ])->assertSessionHasErrors('expense_mode');
    expect(ExpenseCategory::query()->where('name', 'Ngawur')->exists())->toBeFalse();
});

it('mengeluarkan biaya marketing sejak tanggal mulai dari Laba Rugi dan mengurangkannya dari Kekayaan', function () {
    app(Settings::class)->setMany(['wealth_reduction.accounting_start' => '2026-10-01']);
    $akuntan = akuntanKekayaan();
    $marketing = ExpenseCategory::query()->create(['name' => 'Promosi', 'expense_mode' => ExpenseCategory::MODE_WEALTH_REDUCTION, 'is_active' => true]);
    $gaji = ExpenseCategory::query()->create(['name' => 'Gaji', 'expense_mode' => ExpenseCategory::MODE_DIRECT_EXPENSE, 'is_active' => true]);

    keluarkan($gaji, 200_000, '2026-10-05');
    keluarkan($marketing, 96_810, '2026-10-20');

    $oktober = laporanFinal($akuntan, '2026-10-01', '2026-10-31');
    $labaRugi = $oktober->viewData('profitLoss');
    $kekayaan = collect($oktober->viewData('balanceSheet')['wealthRows']);

    // Laba Rugi: hanya gaji; marketing tidak ada di rincian pengeluaran.
    expect((float) $labaRugi['totalPengeluaran'])->toBe(200_000.0)
        ->and(collect($labaRugi['pengeluaranRows'])->pluck('label'))->not->toContain('Promosi')
        ->and((float) $labaRugi['labaRugi'])->toBe(-200_000.0);

    // Neraca: baris pengurang sendiri, tidak jatuh ke "Penyesuaian Neraca",
    // dan Laba Berjalan = Laba Rugi.
    expect((float) $kekayaan->firstWhere('label', 'Promosi')['amount'])->toBe(-96_810.0)
        ->and($kekayaan->firstWhere('label', 'Penyesuaian Neraca'))->toBeNull()
        ->and((float) $kekayaan->firstWhere('label', 'Laba Berjalan')['amount'])->toBe(-200_000.0);
});

it('tetap menghitung biaya marketing sebelum tanggal mulai sebagai beban (laporan lama tidak bergeser)', function () {
    app(Settings::class)->setMany(['wealth_reduction.accounting_start' => '2026-10-01']);
    $akuntan = akuntanKekayaan();
    $marketing = ExpenseCategory::query()->create(['name' => 'Promosi', 'expense_mode' => ExpenseCategory::MODE_WEALTH_REDUCTION, 'is_active' => true]);

    keluarkan($marketing, 720_000, '2026-08-18');

    $agustus = laporanFinal($akuntan, '2026-08-01', '2026-08-31');
    expect((float) $agustus->viewData('profitLoss')['totalPengeluaran'])->toBe(720_000.0)
        ->and(collect($agustus->viewData('profitLoss')['pengeluaranRows'])->pluck('label'))->toContain('Promosi')
        ->and(collect($agustus->viewData('balanceSheet')['wealthRows'])->firstWhere('label', 'Promosi'))->toBeNull()
        ->and(collect($agustus->viewData('balanceSheet')['wealthRows'])->firstWhere('label', 'Penyesuaian Neraca'))->toBeNull();

    // Kontrol positif: tanggal mulai dimundurkan -> Agustus ikut aturan baru.
    app(Settings::class)->setMany(['wealth_reduction.accounting_start' => '2026-08-01']);
    $agustus = laporanFinal($akuntan, '2026-08-01', '2026-08-31');
    expect((float) $agustus->viewData('profitLoss')['totalPengeluaran'])->toBe(0.0)
        ->and((float) collect($agustus->viewData('balanceSheet')['wealthRows'])->firstWhere('label', 'Promosi')['amount'])->toBe(-720_000.0);
});

it('menjaga kekayaan bersih sama apa pun modenya: hanya komposisinya yang berubah', function () {
    app(Settings::class)->setMany(['wealth_reduction.accounting_start' => '2026-10-01']);
    $akuntan = akuntanKekayaan();
    $marketing = ExpenseCategory::query()->create(['name' => 'Promosi', 'expense_mode' => ExpenseCategory::MODE_DIRECT_EXPENSE, 'is_active' => true]);
    keluarkan($marketing, 150_000, '2026-10-14');

    $sebagaiBeban = (float) laporanFinal($akuntan, '2026-10-01', '2026-10-31')->viewData('balanceSheet')['wealthAmount'];

    $marketing->update(['expense_mode' => ExpenseCategory::MODE_WEALTH_REDUCTION]);
    $sebagaiPengurang = (float) laporanFinal($akuntan, '2026-10-01', '2026-10-31')->viewData('balanceSheet')['wealthAmount'];

    expect($sebagaiPengurang)->toBe($sebagaiBeban);
});

it('tidak menghitung biaya marketing sejak tanggal mulai sebagai OHC Real di Analisa HPP', function () {
    app(Settings::class)->setMany(['wealth_reduction.accounting_start' => '2026-10-01']);
    Role::findOrCreate('owner', 'web');
    $owner = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $owner->assignRole('owner');
    $marketing = ExpenseCategory::query()->create(['name' => 'Promosi', 'expense_mode' => ExpenseCategory::MODE_WEALTH_REDUCTION, 'is_active' => true]);
    $gaji = ExpenseCategory::query()->create(['name' => 'Gaji', 'expense_mode' => ExpenseCategory::MODE_DIRECT_EXPENSE, 'is_active' => true]);
    keluarkan($gaji, 100_000, '2026-10-05');
    keluarkan($marketing, 50_000, '2026-10-06');
    keluarkan($marketing, 30_000, '2026-09-06'); // sebelum mulai: tetap OHC

    $response = $this->actingAs($owner)->get(route('ownerapp.hpp-analysis', ['date_from' => '2026-09-01', 'date_to' => '2026-10-31']))->assertOk();
    $baris = collect($response->viewData('periodRows'))->keyBy('period');

    expect((float) $baris['2026-10']['ohc_real'])->toBe(100_000.0)
        ->and((float) $baris['2026-09']['ohc_real'])->toBe(30_000.0);
});
