<?php

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Login panel admin.
 *
 * Login bawaan Filament hanya menerima email, sedangkan sebagian besar pengguna
 * sistem ini tidak punya email dan masuk memakai nama. Tanpa penyesuaian itu
 * staf akunting tidak bisa membuka modul inventory sama sekali.
 */
beforeEach(fn () => Role::findOrCreate('accounting', 'web'));

it('menerima login memakai nama untuk pengguna tanpa email', function () {
    $user = User::factory()->create([
        'name' => 'Dila',
        'email' => null,
        'password' => Hash::make('rahasia123'),
        'is_active' => true,
        'force_password_change' => false,
    ]);
    $user->assignRole('accounting');

    Livewire::test(Login::class)
        ->fillForm(['login' => 'Dila', 'password' => 'rahasia123'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(Auth::check())->toBeTrue()
        ->and(Auth::id())->toBe($user->id);
});

it('tetap menerima login memakai email', function () {
    $user = User::factory()->create([
        'name' => 'Superadmin',
        'email' => 'superadmin@example.test',
        'password' => Hash::make('rahasia123'),
        'is_active' => true,
        'force_password_change' => false,
    ]);
    $user->assignRole('accounting');

    Livewire::test(Login::class)
        ->fillForm(['login' => 'superadmin@example.test', 'password' => 'rahasia123'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(Auth::id())->toBe($user->id);
});

it('menolak password yang salah', function () {
    $user = User::factory()->create([
        'name' => 'Dila',
        'email' => null,
        'password' => Hash::make('rahasia123'),
        'is_active' => true,
        'force_password_change' => false,
    ]);
    $user->assignRole('accounting');

    Livewire::test(Login::class)
        ->fillForm(['login' => 'Dila', 'password' => 'salah'])
        ->call('authenticate')
        ->assertHasFormErrors(['login']);

    expect(Auth::check())->toBeFalse();
});

it('menolak akun nonaktif dan tidak meninggalkan sesi yang terlanjur masuk', function () {
    $user = User::factory()->create([
        'name' => 'Mantan Karyawan',
        'email' => null,
        'password' => Hash::make('rahasia123'),
        'is_active' => false,
        'force_password_change' => false,
    ]);
    $user->assignRole('accounting');

    Livewire::test(Login::class)
        ->fillForm(['login' => 'Mantan Karyawan', 'password' => 'rahasia123'])
        ->call('authenticate')
        ->assertHasFormErrors(['login']);

    // Pemeriksaan aktif berjalan setelah Filament menetapkan sesi, jadi yang
    // diuji bukan sekadar pesan di layar melainkan sesinya benar-benar dibatalkan.
    expect(Auth::check())->toBeFalse();

    // Positive control: akun yang sama, tapi aktif, berhasil masuk -- jadi yang
    // menolak di atas memang pagar is_active, bukan form yang rusak.
    User::query()->where('name', 'Mantan Karyawan')->update(['is_active' => true]);

    Livewire::test(Login::class)
        ->fillForm(['login' => 'Mantan Karyawan', 'password' => 'rahasia123'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(Auth::check())->toBeTrue();
});

it('mengalihkan pengguna yang wajib ganti password keluar dari panel', function () {
    $user = User::factory()->create([
        'name' => 'Karyawan Baru',
        'email' => null,
        'password' => Hash::make('rahasia123'),
        'is_active' => true,
        'force_password_change' => true,
    ]);
    $user->assignRole('accounting');

    $this->actingAs($user)
        ->get('/inventory-app/inventory-items')
        ->assertRedirect(route('profile.edit'));

    // Positive control: setelah passwordnya diganti, halaman yang sama terbuka.
    $user->update(['force_password_change' => false]);

    $this->actingAs($user)
        ->get('/inventory-app/inventory-items')
        ->assertOk();
});

it('menolak login dari peran yang tidak berkepentingan dengan panel', function () {
    Role::findOrCreate('delivery', 'web');

    $kurir = User::factory()->create([
        'name' => 'Faris',
        'email' => null,
        'password' => Hash::make('rahasia123'),
        'is_active' => true,
        'force_password_change' => false,
    ]);
    $kurir->assignRole('delivery');

    Livewire::test(Login::class)
        ->fillForm(['login' => 'Faris', 'password' => 'rahasia123'])
        ->call('authenticate')
        ->assertHasFormErrors(['login']);

    expect(Auth::check())->toBeFalse();
});
