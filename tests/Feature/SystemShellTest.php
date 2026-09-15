<?php

use App\Models\User;
use App\Support\Navigation;
use Spatie\Permission\Models\Role;

/**
 * Cangkang 3S BCS: bilah aplikasi di atas + sidebar menu aplikasi, satu
 * definisi (App\Support\Navigation) yang dirender sama oleh layout Blade dan
 * panel Filament; satu pintu masuk; tidak ada domain yang punya dua halaman.
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

it('mendefinisikan setiap halaman sekali dan menunjuk route yang ada', function () {
    $routes = collect(Navigation::menus())->flatMap(fn ($sections) => collect($sections)->flatten(1))->pluck('route');

    expect($routes->duplicates()->all())->toBe([], 'Route menu ganda: '.$routes->duplicates()->implode(', '));

    foreach ($routes as $route) {
        expect(Route::has($route))->toBeTrue("Route {$route} di menu tidak ada.");
    }

    foreach (Navigation::apps() as $key => $app) {
        expect(Route::has($app['dashboard']))->toBeTrue("Dashboard aplikasi {$key} tidak ada.");
        expect(array_key_exists($key, Navigation::menus()))->toBeTrue("Aplikasi {$key} belum punya menu.");
    }
});

it('membuka setiap sidebar aplikasi dengan grup Ringkasan berisi Dashboard', function () {
    // Struktur grup pertama sama di semua app (termasuk Inventory) supaya
    // jarak dan susunan sidebar Blade dan Filament identik.
    foreach (Navigation::menus() as $app => $sections) {
        expect(array_key_first($sections))->toBe('Ringkasan', "Grup pertama sidebar {$app} bukan Ringkasan.");
        expect($sections['Ringkasan'])->toHaveCount(1, "Grup Ringkasan {$app} berisi lebih dari Dashboard.");
        expect($sections['Ringkasan'][0]['label'])->toStartWith('Dashboard');
        expect($sections['Ringkasan'][0]['route'])->toBe(Navigation::apps()[$app]['dashboard']);
    }
});

it('mendaftarkan setiap resource dan halaman filament ke menu aplikasi inventory dengan grup yang sama', function () {
    $menu = Navigation::menus()['inventory'];
    $routes = collect($menu)->flatten(1)->pluck('route')->all();
    $groups = array_keys($menu);

    foreach (\Filament\Facades\Filament::getResources() as $resource) {
        expect(in_array($resource::getRouteBaseName().'.index', $routes, true))->toBeTrue("Resource {$resource} belum ada di Navigation.");
        expect(in_array($resource::getNavigationGroup(), $groups, true))->toBeTrue("Grup '{$resource::getNavigationGroup()}' pada {$resource} tidak ada di Navigation.");
    }

    foreach (\Filament\Facades\Filament::getPages() as $page) {
        expect(in_array($page::getRouteName(), $routes, true))->toBeTrue("Halaman {$page} belum ada di Navigation.");
        expect(in_array($page::getNavigationGroup(), $groups, true))->toBeTrue("Grup '{$page::getNavigationGroup()}' pada {$page} tidak ada di Navigation.");
    }
});

it('menampilkan bilah aplikasi yang sama di halaman blade dan halaman filament', function () {
    $owner = penggunaShell('owner');

    $blade = $this->actingAs($owner)->get(route('accountingapp.expenses.index'))->assertOk();
    $filament = $this->actingAs($owner)->get('/inventory/inventory-items')->assertOk();

    foreach ([$blade, $filament] as $response) {
        $response
            ->assertSee('Control System')
            ->assertSee('class="sh-tab', false)
            ->assertSee(route('ownerapp.dashboard'), false)
            ->assertSee(route('accountingapp.dashboard'), false)
            ->assertSee(route('filament.admin.pages.dashboard'), false)
            ->assertSee(route('salesapp.dashboard'), false);
    }

    // Tab yang aktif mengikuti halaman: Accounting di Blade, Inventory di panel.
    expect(collect(Navigation::tabs($owner, 'accounting'))->firstWhere('active', true)['key'])->toBe('accounting');
    expect(collect(Navigation::tabs($owner, 'inventory'))->firstWhere('active', true)['key'])->toBe('inventory');
});

it('menampilkan sidebar menu aplikasi yang sedang dibuka saja', function () {
    $owner = penggunaShell('owner');

    $this->actingAs($owner)->get(route('accountingapp.dashboard'))->assertOk()
        ->assertSee('Pengeluaran')
        ->assertSee('Monitoring Hutang')
        ->assertDontSee('Purchase Orders')   // menu Admin
        ->assertDontSee('Master User');      // menu Owner

    $this->actingAs($owner)->get(route('adminapp.dashboard'))->assertOk()
        ->assertSee('Purchase Orders')
        ->assertDontSee('Pengeluaran');

    $this->actingAs($owner)->get('/inventory/recipes')->assertOk()
        ->assertSee('Resep &amp; Menu', false)
        ->assertSee('SPK Produksi')
        ->assertDontSee('Pengeluaran');
});

it('menyaring tab dan menu menurut peran', function () {
    $produksi = penggunaShell('production');

    expect(collect(Navigation::tabs($produksi))->pluck('key')->all())->toBe(['inventory', 'production']);
    expect(Navigation::sidebar('accounting', $produksi))->toBe([]);

    $this->actingAs($produksi)->get(route('productionapp.dashboard'))->assertOk()
        ->assertSee('Dashboard Produksi')
        ->assertDontSee(route('accountingapp.dashboard'), false)
        ->assertDontSee(route('adminapp.dashboard'), false);

    $this->actingAs($produksi)->get('/inventory/production-orders')->assertOk()
        ->assertSee('SPK Produksi')
        ->assertDontSee('Pengaturan Inventory');

    // Positive control: owner melihat semua tab dan Pengaturan Inventory
    // (dari definisi menu, karena navigasi Filament dipasang sekali per proses).
    $owner = penggunaShell('owner');
    expect(collect(Navigation::tabs($owner))->pluck('key')->all())->toBe(['owner', 'admin', 'accounting', 'inventory', 'sales', 'production', 'delivery']);
    expect(collect(Navigation::sidebar('inventory', $owner))->pluck('label')->all())->toBe(['Ringkasan', 'Inventory', 'Resep & HPP', 'Produksi']);
    expect(collect(collect(Navigation::sidebar('inventory', $owner))->firstWhere('label', 'Inventory')['items'])->pluck('label')->all())->toContain('Pengaturan Inventory');

    $akunting = penggunaShell('accounting');
    expect(collect(Navigation::sidebar('accounting', $akunting))->firstWhere('label', 'Setup Awal')['items'])->toHaveCount(1); // adjustment hanya owner
});

it('memakai satu pintu masuk, beranda peran, dan mengarahkan path lama', function () {
    $this->get('/inventory/inventory-items')->assertRedirect(route('login'));
    $this->get('/admin')->assertRedirect('/inventory');
    $this->get('/admin/recipes/157/hpp')->assertRedirect('/inventory/recipes/157/hpp');
    expect(Route::has('filament.admin.auth.login'))->toBeFalse();

    $akunting = penggunaShell('accounting');
    expect(Navigation::dashboardUrl($akunting))->toBe(route('accountingapp.dashboard'));
    $this->actingAs($akunting)->get('/inventory')->assertRedirect(route('accountingapp.dashboard'));
});

it('tidak lagi punya dua halaman untuk satu domain', function () {
    foreach (['purchase-orders', 'spks', 'delivery-orders', 'customers', 'products', 'users'] as $slug) {
        expect(Route::has("filament.admin.resources.{$slug}.index"))->toBeFalse("Resource Filament '{$slug}' masih ada.");
    }

    $akunting = penggunaShell('accounting');
    $this->actingAs($akunting)->get(route('accountingapp.inventory-items.index'))->assertRedirect(route('filament.admin.resources.inventory-items.index'));
    $this->actingAs($akunting)->get(route('accountingapp.stock-opnames.index'))->assertRedirect(route('filament.admin.resources.stock-opnames.index'));
    $this->actingAs($akunting)->get(route('accountingapp.inventory-openings.index'))->assertRedirect(route('filament.admin.resources.inventory-openings.index'));
});
