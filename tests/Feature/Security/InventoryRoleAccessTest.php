<?php

use App\Models\User;
use App\Support\Navigation;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;

/**
 * Peran `inventory` (staf inventory/gudang) dipilih Owner di Master User dan
 * hanya membuka aplikasi Inventory -- bukan aplikasi Blade manapun. Sebelum
 * peran ini ada, akses Inventory hanya bisa diberikan dengan "menumpangkan"
 * peran owner/admin/accounting/production ke akun staf gudang.
 */
// penggunaBerperan() dipakai bersama ModuleAccessTest (Pest memuat semua file tes).

it('membiarkan owner memberi peran inventory dari master user, dan menolak peran yang tidak dikenal', function () {
    actingAs(penggunaBerperan('owner'));

    get(route('ownerapp.users.index'))->assertOk()->assertSee('value="inventory"', false);

    from(route('ownerapp.users.index'))->post(route('ownerapp.users.store'), [
        'name' => 'Staf Gudang', 'email' => 'gudang@example.com', 'roles' => ['inventory'], 'is_active' => '1',
    ])->assertRedirect(route('ownerapp.users.index'))->assertSessionHasNoErrors();

    expect(User::where('email', 'gudang@example.com')->firstOrFail()->getRoleNames()->all())->toBe(['inventory']);

    from(route('ownerapp.users.index'))->post(route('ownerapp.users.store'), [
        'name' => 'Peran Palsu', 'email' => 'palsu@example.com', 'roles' => ['gudang'], 'is_active' => '1',
    ])->assertSessionHasErrors('roles.0');

    expect(User::where('email', 'palsu@example.com')->exists())->toBeFalse();
});

it('membuka aplikasi inventory untuk peran inventory sesuai izin bawaannya, dan menutupnya untuk sales', function () {
    $gudang = penggunaBerperan('inventory');

    actingAs($gudang);

    // Beranda peran = dashboard Inventory; tab aplikasi hanya Inventory.
    get('/dashboard')->assertRedirect(route('filament.admin.pages.dashboard'));
    get('/inventory')->assertRedirect(route('filament.admin.pages.dashboard'));
    expect(collect(Navigation::tabs($gudang))->pluck('key')->all())->toBe(['inventory']);

    get('/inventory/dashboard')->assertOk();
    get('/inventory/inventory-items')->assertOk();
    get('/inventory/recipes')->assertOk();
    get('/inventory/inventory-movements')->assertOk();
    get('/inventory/production-orders')->assertOk();

    // Tidak punya settings.manage, dan tidak punya aplikasi Blade manapun.
    get('/inventory/pengaturan-inventory')->assertForbidden();
    get('/owner-app')->assertForbidden();
    get('/admin-app')->assertForbidden();
    get('/accounting-app')->assertForbidden();
    get('/production-app')->assertForbidden();

    // Izin bawaan: kelola inventory & resep, periksa form kebutuhan, tanpa
    // menyusun/menyetujui/menutup SPK produksi.
    expect($gudang->can('inventory.manage'))->toBeTrue()
        ->and($gudang->can('recipe.manage'))->toBeTrue()
        ->and($gudang->can('requisition.check'))->toBeTrue()
        ->and($gudang->can('production.manage'))->toBeFalse()
        ->and($gudang->can('requisition.approve'))->toBeFalse()
        ->and($gudang->can('production.complete'))->toBeFalse();

    // Kontrol negatif: sales tetap tidak bisa membuka panel yang sama.
    actingAs(penggunaBerperan('sales'));
    get('/inventory/dashboard')->assertForbidden();
    get('/inventory/inventory-items')->assertForbidden();
});
