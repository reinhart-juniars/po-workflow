<?php

use App\Models\User;
use App\Support\Navigation;
use Spatie\Permission\Models\Role;

/**
 * Cangkang 3S ONE: bilah aplikasi di atas + sidebar menu aplikasi, satu
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

it('memakai istilah Indonesia di menu dan halaman kartu stok, bukan "ledger"', function () {
    // User gudang mengenal "Kartu Stok"; "ledger" hanya nama internal
    // (izin ledger.view, InventoryLedgerService).
    $labels = collect(Navigation::menus())->flatMap(fn ($sections) => collect($sections)->flatten(1))->pluck('label');
    expect($labels->filter(fn ($l) => stripos($l, 'ledger') !== false)->all())->toBe([]);
    expect($labels->all())->toContain('Kartu Stok');

    expect(collect(\App\Support\Access\ModuleAccess::PERMISSIONS)->filter(fn ($l) => stripos($l, 'ledger') !== false)->all())->toBe([]);

    $this->actingAs(penggunaShell('owner'))->get('/inventory/inventory-movements')
        ->assertOk()->assertSee('Kartu Stok')->assertDontSee('Ledger');
});

it('mendaftarkan setiap resource dan halaman filament ke menu aplikasinya dengan grup yang sama', function () {
    // Panel Filament => aplikasi di Navigation. Panel baru wajib masuk di sini.
    $panels = ['admin' => 'inventory', 'menu' => 'menu'];

    expect(array_keys(\Filament\Facades\Filament::getPanels()))->toEqualCanonicalizing(array_keys($panels));

    foreach ($panels as $panelId => $app) {
        $panel = \Filament\Facades\Filament::getPanel($panelId);
        $menu = Navigation::menus()[$app];
        $routes = collect($menu)->flatten(1)->pluck('route')->all();
        $groups = array_keys($menu);

        expect($panel->getResources())->not->toBeEmpty();

        foreach ($panel->getResources() as $resource) {
            expect(in_array($resource::getRouteBaseName().'.index', $routes, true))->toBeTrue("Resource {$resource} belum ada di menu {$app}.");
            expect(in_array($resource::getNavigationGroup(), $groups, true))->toBeTrue("Grup '{$resource::getNavigationGroup()}' pada {$resource} tidak ada di menu {$app}.");
        }

        foreach ($panel->getPages() as $page) {
            expect(in_array($page::getRouteName(), $routes, true))->toBeTrue("Halaman {$page} belum ada di menu {$app}.");
            expect(in_array($page::getNavigationGroup(), $groups, true))->toBeTrue("Grup '{$page::getNavigationGroup()}' pada {$page} tidak ada di menu {$app}.");
        }
    }
});

it('menampilkan bilah aplikasi yang sama di halaman blade dan halaman filament', function () {
    $owner = penggunaShell('owner');

    $blade = $this->actingAs($owner)->get(route('accountingapp.expenses.index'))->assertOk();
    $filament = $this->actingAs($owner)->get('/inventory/inventory-items')->assertOk();

    foreach ([$blade, $filament] as $response) {
        $response
            ->assertSee('3S ONE')
            ->assertSee('Business Control System')
            // Menu pengguna yang sama: avatar inisial + nama + Profil Akun / Keluar.
            ->assertSee('class="sh-user"', false)
            ->assertSee('class="sh-avatar"', false)
            ->assertSee($owner->name)
            ->assertSee('Profil Akun')
            ->assertSee('Keluar')
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

    // Aplikasi Menu hanya menampilkan menunya sendiri, bukan menu Inventory.
    $this->actingAs($owner)->get('/menu/recipes')->assertOk()
        ->assertSee('Menu Utama')
        ->assertSee('Sub Menu')
        ->assertSee('Pencocokan Menu')
        ->assertDontSee('SPK Produksi')
        ->assertDontSee('Pengeluaran');
});

it('tidak menampilkan menu aplikasi Menu di sidebar Inventory', function () {
    // `it` terpisah: navigasi Filament dipasang sekali per proses tes, jadi
    // dua panel dalam satu `it` saling mewarisi sidebar.
    $this->actingAs(penggunaShell('owner'))->get('/inventory/production-orders')->assertOk()
        ->assertSee('SPK Produksi')
        ->assertDontSee('Pencocokan Menu')
        ->assertDontSee('Konversi Satuan');
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
    expect(collect(Navigation::tabs($owner))->pluck('key')->all())->toBe(['owner', 'admin', 'accounting', 'inventory', 'menu', 'sales', 'marketing', 'production', 'delivery']);
    expect(collect(Navigation::sidebar('inventory', $owner))->pluck('label')->all())->toBe(['Ringkasan', 'Inventory', 'Produksi']);
    expect(collect(Navigation::sidebar('menu', $owner))->pluck('label')->all())->toBe(['Ringkasan', 'Menu & Resep', 'HPP & OHC']);
    expect(collect(collect(Navigation::sidebar('inventory', $owner))->firstWhere('label', 'Inventory')['items'])->pluck('label')->all())->toContain('Pengaturan Inventory');

    $akunting = penggunaShell('accounting');
    expect(collect(Navigation::sidebar('accounting', $akunting))->firstWhere('label', 'Setup Awal')['items'])->toHaveCount(1); // adjustment hanya owner
});

it('memakai satu pintu masuk, beranda peran, dan mengarahkan path lama', function () {
    $this->get('/inventory/inventory-items')->assertRedirect(route('login'));
    $this->get('/admin')->assertRedirect('/inventory');
    $this->get('/admin/recipes/157/hpp')->assertRedirect('/inventory/recipes/157/hpp');
    // Resep pindah ke aplikasi Menu: tautan lama /inventory/... ikut diarahkan.
    $this->get('/inventory/recipes/157/hpp')->assertRedirect('/menu/recipes/157/hpp');
    $this->get('/inventory/pencocokan-menu')->assertRedirect('/menu/pencocokan-menu');
    $this->get('/inventory/production-orders')->assertRedirect(route('login')); // kontrol: modul Inventory tidak ikut dialihkan
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
