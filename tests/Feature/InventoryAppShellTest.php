<?php

use App\Models\User;
use App\Support\AppSwitcher;
use Spatie\Permission\Models\Role;

/**
 * Inventory App: panel Filament yang tampil sebagai salah satu aplikasi 3S BCS.
 * Yang dijaga: path baru, pengalih aplikasi yang sama di kedua sisi (header
 * Blade & topbar panel), path lama yang tetap mengantar, dan menu lawas yang
 * tidak muncul untuk peran biasa.
 */
function penggunaApp(string ...$roles): User
{
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);

    foreach ($roles as $role) {
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);
    }

    return $user;
}

it('mengarahkan path lama /admin ke /inventory-app tanpa mengganggu /admin-app', function () {
    $this->get('/admin')->assertRedirect('/inventory-app');
    $this->get('/admin/recipes/157/hpp')->assertRedirect('/inventory-app/recipes/157/hpp');

    // Aplikasi Blade admin tetap di tempatnya, tidak ikut tertangkap pola /admin/{path}.
    $tujuan = $this->get('/admin-app')->headers->get('Location');
    expect($tujuan)->not->toContain('inventory-app');
});

it('menampilkan inventory sebagai aplikasi di pengalih untuk peran panel, dan tidak untuk sales', function () {
    $akunting = penggunaApp('accounting');
    expect($akunting->accessibleAppKeys())->toBe(['accounting', 'inventory']);

    $produksi = penggunaApp('production');
    expect($produksi->accessibleAppKeys())->toBe(['production', 'inventory']);

    $sales = penggunaApp('sales');
    expect($sales->accessibleAppKeys())->toBe(['sales']);

    $owner = penggunaApp('owner');
    expect($owner->accessibleAppKeys())->toContain('inventory')
        ->and(array_search('inventory', $owner->accessibleAppKeys(), true))
        ->toBe(array_search('accounting', $owner->accessibleAppKeys(), true) + 1);
});

it('memasang tautan inventory app di header aplikasi blade dan tautan balik di topbar panel', function () {
    $akunting = penggunaApp('accounting');

    $this->actingAs($akunting)
        ->get(route('accountingapp.dashboard'))
        ->assertOk()
        ->assertSee('Accounting App')
        ->assertSee(route('filament.admin.pages.dashboard'), false)
        ->assertSee('Inventory')
        // Menu duplikat di Accounting App menunjuk ke Inventory App.
        ->assertSee(route('filament.admin.resources.stock-opnames.index'), false)
        ->assertSee(route('filament.admin.resources.inventory-items.index'), false);

    $this->actingAs($akunting)
        ->get('/inventory-app/inventory-items')
        ->assertOk()
        ->assertSee('Inventory App')
        ->assertSee('3S Business Control System')
        ->assertSee(route('accountingapp.dashboard'), false)
        ->assertSee('Accounting');

    // Aplikasi yang sedang dibuka tidak ditawarkan lagi di pengalihnya.
    $this->actingAs($akunting)->get('/inventory-app/inventory-items');
    expect(collect(AppSwitcher::linksFor($akunting, 'inventory'))->pluck('key')->all())->toBe(['accounting']);
});

// Navigasi Filament dipasang sekali per proses, jadi dua peran diuji terpisah.
it('menyembunyikan resource lawas dari menu inventory app untuk peran biasa', function () {
    $this->actingAs(penggunaApp('owner'))
        ->get('/inventory-app/inventory-items')
        ->assertOk()
        ->assertSee('Item Inventaris')
        ->assertDontSee('Purchase Orders')
        ->assertDontSee('Delivery Orders')
        ->assertDontSee('Slot SPK');
});

it('tetap menampilkan resource lawas di menu untuk superadmin', function () {
    $this->actingAs(penggunaApp('superadmin'))
        ->get('/inventory-app/inventory-items')
        ->assertOk()
        ->assertSee('Purchase Orders')
        ->assertSee('Slot SPK');
});

it('menyediakan satu daftar aplikasi yang dipakai kedua header', function () {
    $keys = array_keys(AppSwitcher::apps());

    expect($keys)->toBe(['superadmin', 'owner', 'admin', 'accounting', 'inventory', 'sales', 'production', 'delivery'])
        ->and(AppSwitcher::linksFor(null))->toBe([]);
});
