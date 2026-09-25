<?php

use App\Models\ExpenseCategory;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Toolbar tabel ala panel Filament (components/table-toolbar) di aplikasi
 * Blade: filter dalam popover, badge jumlah filter, dan chip "Filter aktif".
 * Diuji lewat halaman sungguhan supaya parameter yang dibaca controller dan
 * yang ditulis toolbar tetap sama.
 */
function penggunaToolbar(string $role): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

it('menampilkan chip filter dengan label opsi, bukan nilai mentahnya', function () {
    $this->actingAs(penggunaToolbar('accounting'))
        ->get(route('accountingapp.payables.index', ['source' => 'inventory_purchase', 'urgency' => 'overdue']))
        ->assertOk()
        ->assertSee('Sumber: Pembelian Stok')
        ->assertSee('Status Jatuh Tempo: Lewat Jatuh Tempo')
        ->assertDontSee('Sumber: inventory_purchase')
        // Badge ikon filter = jumlah filter aktif.
        ->assertSee('<span class="tt-badge">2</span>', false);
});

it('melepas satu chip hanya membuang parameter filter itu', function () {
    $response = $this->actingAs(penggunaToolbar('accounting'))
        ->get(route('accountingapp.payables.index', ['source' => 'inventory_purchase', 'urgency' => 'overdue']));

    // Tautan lepas chip "Sumber" masih membawa urgency; tautan "Status" masih membawa source.
    $response->assertSee(e(route('accountingapp.payables.index', ['urgency' => 'overdue'])), false)
        ->assertSee(e(route('accountingapp.payables.index', ['source' => 'inventory_purchase'])), false);
});

it('menampilkan periode bawaan sebagai keterangan dan membuat periode pilihan pengguna bisa dilepas', function () {
    $user = penggunaToolbar('accounting');

    // Tanpa tanggal di URL: periode bawaan controller tampil, tanpa tombol lepas.
    $this->actingAs($user)->get(route('accountingapp.expenses.index'))
        ->assertOk()
        ->assertSee('Periode:')
        ->assertDontSee('Lepas filter Periode', false);

    // Positive control: periode yang dipilih pengguna bisa dilepas.
    $this->actingAs($user)
        ->get(route('accountingapp.expenses.index', ['date_from' => '2026-08-01', 'date_to' => '2026-08-31']))
        ->assertOk()
        ->assertSee('Periode: 1 Agt 2026 – 31 Agt 2026')
        ->assertSee('Lepas filter Periode', false);
});

it('menerapkan filter kategori dari toolbar ke data yang tampil', function () {
    $user = penggunaToolbar('accounting');
    $gaji = ExpenseCategory::query()->create(['name' => 'Gaji Uji', 'is_active' => true]);
    ExpenseCategory::query()->create(['name' => 'Listrik Uji', 'is_active' => true]);

    $this->actingAs($user)
        ->get(route('accountingapp.expenses.index', ['expense_category_id' => $gaji->id]))
        ->assertOk()
        ->assertSee('Kategori: Gaji Uji')
        // Pilihan yang aktif ikut terpilih di popover.
        ->assertSee('<option value="'.$gaji->id.'" selected', false);
});

it('membawa parameter lain lewat keep sehingga filter tidak mereset tampilan', function () {
    $this->actingAs(penggunaToolbar('accounting'))
        ->get(route('accountingapp.periods.index', ['view' => 'paid']))
        ->assertOk()
        ->assertSee('<input type="hidden" name="view" value="paid">', false)
        // "Atur ulang" tetap di tampilan piutang lunas.
        ->assertSee('href="'.e(route('accountingapp.periods.index', ['view' => 'paid'])).'" class="tt-reset"', false);
});

it('mengirim status "Semua" secara eksplisit di dashboard sales yang bawaannya draft', function () {
    $this->actingAs(penggunaToolbar('sales'))
        ->get(route('salesapp.dashboard'))
        ->assertOk()
        ->assertSee('<option value="all"', false)
        ->assertSee('Status: Draft')
        // Tanpa opsi kosong: pilihan kosong akan jatuh kembali ke draft.
        ->assertDontSee('<option value="">Semua</option>', false);
});

it('meng-escape nilai filter dari URL di chip dan field', function () {
    $payload = '<script>alert(1)</script>';

    $this->actingAs(penggunaToolbar('accounting'))
        ->get(route('accountingapp.payables.index', ['supplier_name' => $payload]))
        ->assertOk()
        ->assertDontSee($payload, false)
        // Positive control: nilainya tetap tampil, dalam bentuk ter-escape.
        ->assertSee(e('Supplier: '.$payload), false);
});
