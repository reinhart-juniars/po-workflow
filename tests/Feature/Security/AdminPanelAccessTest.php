<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

/**
 * Panel admin pernah berjalan tanpa middleware sesi maupun autentikasi sama
 * sekali: seluruh halamannya membalas 200 berisi data asli kepada siapa pun
 * yang membukanya, dan login mustahil karena CSRF token selalu kosong.
 *
 * Tes di sini menjaga kedua sisi itu sekaligus, dan sengaja menelusuri daftar
 * route yang sebenarnya -- bukan daftar yang ditulis tangan -- supaya resource
 * baru yang lolos dari perlindungan ikut membuat build merah.
 */

/** @return array<int, string> Seluruh URI GET panel yang seharusnya butuh login. */
function uriPanelTerlindungi(): array
{
    return collect(Route::getRoutes())
        // Hanya panel Filament (prefix 'admin/'); route Blade 'admin-app/...'
        // punya pagar sendiri dan diuji terpisah.
        ->filter(fn ($route) => ($route->uri() === 'admin' || str_starts_with($route->uri(), 'admin/'))
            && in_array('GET', $route->methods(), true))
        ->reject(fn ($route) => str_contains($route->uri(), 'login')
            || str_contains($route->uri(), 'logout')
            || str_contains($route->uri(), 'password-reset'))
        // Route ber-parameter butuh record nyata; perlindungannya sudah terwakili
        // oleh route indeks pada resource yang sama.
        ->reject(fn ($route) => str_contains($route->uri(), '{'))
        ->map(fn ($route) => $route->uri())
        ->unique()
        ->values()
        ->all();
}

/** Pengguna berperan akunting -- salah satu peran yang boleh membuka panel. */
function penggunaPanel(): User
{
    Role::findOrCreate('accounting', 'web');

    $user = User::factory()->create([
        'is_active' => true,
        'force_password_change' => false,
    ]);

    $user->assignRole('accounting');

    return $user;
}

it('menolak seluruh halaman panel untuk pengunjung yang belum login', function () {
    $uris = uriPanelTerlindungi();

    // Kalau daftarnya kosong, tes ini akan lulus tanpa menguji apa pun.
    expect($uris)->not->toBeEmpty();

    foreach ($uris as $uri) {
        $this->get('/'.$uri)
            ->assertRedirect(route('filament.admin.auth.login'));
    }
});

it('meloloskan pengguna yang sudah login dari pagar autentikasi', function () {
    // Positive control untuk tes di atas: tanpa ini, route yang rusak total pun
    // akan tampak "aman" karena sama-sama tidak membalas 200.
    //
    // Yang diperiksa hanya pagar autentikasinya. Sebagian resource lama
    // dijaga policy filament-shield dan wajar membalas 403 bagi pengguna
    // tanpa izin -- 403 tetap berarti pagar login sudah terlewati.
    $user = penggunaPanel();

    $this->actingAs($user);

    foreach (uriPanelTerlindungi() as $uri) {
        $response = $this->get('/'.$uri);

        expect($response->headers->get('Location'))
            ->not->toBe(route('filament.admin.auth.login'), "Halaman /{$uri} masih menolak pengguna yang sudah login.");
    }
});

it('membuka halaman inventory untuk pengguna yang sudah login', function () {
    // Akunting memegang inventory.view (ModuleAccess), jadi di sinilah 200
    // benar-benar bisa dituntut -- membuktikan pagar login bukan sekadar
    // menolak semua. Matriks izin per peran diuji di ModuleAccessTest.
    $user = penggunaPanel();

    $this->actingAs($user);

    foreach (['admin/inventory-items', 'admin/stock-opnames', 'admin/inventory-openings', 'admin/inventory-purchases'] as $uri) {
        $this->get('/'.$uri)->assertOk();
    }
});

it('memulai sesi dan menerbitkan csrf token di halaman login', function () {
    // Tanpa middleware sesi, halaman login tetap membalas 200 tetapi tidak
    // pernah menaruh cookie dan token CSRF-nya kosong, sehingga setiap kiriman
    // form berakhir 419. Itulah yang membuat panel tidak bisa dipakai login.
    $response = $this->get('/admin/login')->assertOk();

    expect($response->headers->getCookies())->not->toBeEmpty()
        ->and(csrf_token())->not->toBeEmpty();

    $response->assertSee(csrf_token(), false);
});

it('menolak pengguna yang perannya tidak berkepentingan dengan panel', function () {
    // Tanpa gate eksplisit, Filament meloloskan siapa pun yang login selama
    // APP_ENV bernilai 'local', dan menolak semua orang di environment lain.
    Role::findOrCreate('delivery', 'web');

    $kurir = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $kurir->assignRole('delivery');

    $this->actingAs($kurir)->get('/admin/inventory-items')->assertForbidden();

    // Positive control: peran yang berkepentingan tetap masuk.
    $this->actingAs(penggunaPanel())->get('/admin/inventory-items')->assertOk();
});

it('menolak pengguna nonaktif meski perannya berkepentingan', function () {
    $user = penggunaPanel();
    $user->update(['is_active' => false]);

    $this->actingAs($user)->get('/admin/inventory-items')->assertForbidden();
});
