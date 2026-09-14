<?php

use App\Models\User;
use App\Support\Navigation;
use Spatie\Permission\Models\Role;

/**
 * Cangkang 3S BCS: satu menu (App\Support\Navigation) yang dirender sama
 * oleh layout Blade dan panel Filament, satu pintu masuk, tidak ada domain
 * yang punya dua halaman.
 */
function penggunaShell(string ...$roles): User
{
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);

    foreach ($roles as $role) {
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);
    }

    return $user;
}

it('mendefinisikan setiap item menu sekali, menunjuk route yang ada, dengan pemilik yang jelas', function () {
    $items = Navigation::items();
    $labels = collect($items)->pluck('label');
    $routes = collect($items)->pluck('route');

    expect($labels->duplicates()->all())->toBe([], 'Label menu ganda: '.$labels->duplicates()->implode(', '))
        ->and($routes->duplicates()->all())->toBe([], 'Route menu ganda: '.$routes->duplicates()->implode(', '));

    foreach ($items as $item) {
        expect(Route::has($item['route']))->toBeTrue("Route {$item['route']} untuk menu '{$item['label']}' tidak ada.");
        expect(array_key_exists($item['group'], Navigation::groupLabels()))->toBeTrue("Grup '{$item['group']}' tidak terdaftar.");
        expect(isset($item['roles']) xor isset($item['can']))->toBeTrue("Menu '{$item['label']}' harus punya roles (Blade) atau can (Filament), tepat satu.");
    }
});

it('mendaftarkan setiap resource dan halaman filament ke menu terpadu', function () {
    // Halaman Filament yang tidak ada di Navigation akan hilang dari sidebar
    // Blade -- pengguna hanya bisa menemukannya lewat panel. Itu dua sistem.
    $filamentRoutes = collect(Navigation::items())->where('source', 'filament')->pluck('route')->all();

    foreach (\Filament\Facades\Filament::getResources() as $resource) {
        expect(in_array($resource::getRouteBaseName().'.index', $filamentRoutes, true))->toBeTrue("Resource {$resource} belum ada di Navigation.");
    }

    foreach (\Filament\Facades\Filament::getPages() as $page) {
        expect(in_array($page::getRouteName(), $filamentRoutes, true))->toBeTrue("Halaman {$page} belum ada di Navigation.");
    }
});

it('menampilkan sidebar 3s yang sama di halaman blade dan halaman filament', function () {
    $owner = penggunaShell('owner');

    $blade = $this->actingAs($owner)->get(route('accountingapp.dashboard'))->assertOk();
    $filament = $this->actingAs($owner)->get('/inventory/inventory-items')->assertOk();

    foreach ([$blade, $filament] as $response) {
        $response
            ->assertSee('Control System')
            ->assertSee('Pengeluaran')                 // item Blade
            ->assertSee('Resep &amp; Menu', false)     // item Filament
            ->assertSee('Purchase Orders')             // item Blade grup Pesanan
            ->assertSee(route('accountingapp.expenses.index'), false)
            ->assertSee(route('filament.admin.resources.recipes.index'), false)
            ->assertDontSee('Inventory App')
            ->assertDontSee('Accounting App');
    }
});

it('menyaring menu menurut peran di kedua sisi', function () {
    $produksi = penggunaShell('production');

    $blade = $this->actingAs($produksi)->get(route('productionapp.dashboard'))->assertOk();
    $filament = $this->actingAs($produksi)->get('/inventory/production-orders')->assertOk();

    foreach ([$blade, $filament] as $response) {
        $response
            ->assertSee('Produksi Harian')
            ->assertSee('SPK Produksi')
            ->assertDontSee('Pengeluaran')
            ->assertDontSee('Purchase Orders')
            ->assertDontSee('Pengaturan');
    }

    // Positive control: owner melihat yang disembunyikan dari produksi.
    // (Di halaman Blade, karena navigasi Filament dipasang sekali per proses.)
    $this->actingAs(penggunaShell('owner'))->get(route('ownerapp.dashboard'))->assertOk()->assertSee('Pengeluaran')->assertSee('Pengaturan');
});

it('menautkan dashboard ke dashboard peran pengguna dan beranda panel ke sana juga', function () {
    $akunting = penggunaShell('accounting');

    expect(Navigation::dashboardUrl($akunting))->toBe(route('accountingapp.dashboard'));

    $this->actingAs($akunting)->get('/inventory/inventory-items')->assertOk()->assertSee(route('accountingapp.dashboard'), false);
    $this->actingAs($akunting)->get('/inventory')->assertRedirect(route('accountingapp.dashboard'));
});

it('memakai satu pintu masuk dan mengarahkan path lama', function () {
    $this->get('/inventory/inventory-items')->assertRedirect(route('login'));
    $this->get('/admin')->assertRedirect('/inventory');
    $this->get('/admin/recipes/157/hpp')->assertRedirect('/inventory/recipes/157/hpp');

    // Panel tidak lagi punya halaman login sendiri.
    expect(Route::has('filament.admin.auth.login'))->toBeFalse();
});

it('tidak lagi punya dua halaman untuk satu domain', function () {
    // Domain Blade tidak punya resource Filament.
    foreach (['purchase-orders', 'spks', 'delivery-orders', 'customers', 'products', 'users'] as $slug) {
        expect(Route::has("filament.admin.resources.{$slug}.index"))->toBeFalse("Resource Filament '{$slug}' masih ada.");
    }

    // Domain inventory tidak punya halaman Blade: route lamanya mengalihkan.
    $akunting = penggunaShell('accounting');
    $this->actingAs($akunting)->get(route('accountingapp.inventory-items.index'))->assertRedirect(route('filament.admin.resources.inventory-items.index'));
    $this->actingAs($akunting)->get(route('accountingapp.stock-opnames.index'))->assertRedirect(route('filament.admin.resources.stock-opnames.index'));
    $this->actingAs($akunting)->get(route('accountingapp.inventory-openings.index'))->assertRedirect(route('filament.admin.resources.inventory-openings.index'));
});
