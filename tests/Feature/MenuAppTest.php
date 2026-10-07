<?php

use App\Filament\Menu\Pages\MenuMatching;
use App\Filament\Menu\Resources\RecipeResource;
use App\Filament\Menu\Resources\RecipeResource\Pages\CreateRecipe;
use App\Filament\Menu\Resources\RecipeResource\Pages\EditRecipe;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Navigation;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Aplikasi Menu (revisi pasca presentasi 7 Okt 2026): resep, menu utama, sub
 * menu, HPP & OHC dipegang tim menu di panel /menu. Admin dan gudang hanya
 * membaca; harga jual & profit ke customer tetap di Admin › Master Menu.
 */
function penggunaMenuApp(string $role): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

it('membuka aplikasi Menu untuk peran menu dengan dashboard dan sidebar sendiri', function () {
    $menu = penggunaMenuApp('menu');
    Recipe::query()->create(['name' => 'Nasi Ayam', 'jenis' => Recipe::JENIS_UTAMA, 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0, 'profit_pct' => 0]);

    $this->actingAs($menu)->get('/dashboard')->assertRedirect(route('filament.menu.pages.dashboard'));
    $this->actingAs($menu)->get('/menu')->assertRedirect(route('filament.menu.pages.dashboard'));

    $this->actingAs($menu)->get('/menu/dashboard')->assertOk()->assertSee('Dashboard Menu');

    // Widget dashboard dimuat lazy oleh Filament; isinya diuji sebagai komponen.
    Livewire::actingAs($menu)->test(\App\Filament\Menu\Widgets\MenuOverviewWidget::class)
        ->assertSee('Menu utama aktif')
        ->assertSee('Produk dijual tanpa resep');

    // Tim menu hanya membuka aplikasi Menu, bukan Inventory.
    expect(collect(Navigation::tabs($menu))->pluck('key')->all())->toBe(['menu']);
    $this->actingAs($menu)->get('/inventory/inventory-items')->assertForbidden();
});

it('memisahkan Menu Utama dan Sub Menu di sidebar, menyala sesuai jenis resep', function () {
    $owner = penggunaMenuApp('owner');
    $sub = Recipe::query()->create(['name' => 'Sambal Dasar', 'jenis' => Recipe::JENIS_SUB, 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0, 'profit_pct' => 0]);

    $this->actingAs($owner)->get(RecipeResource::getUrl('index', ['activeTab' => 'sub']))->assertOk()
        ->assertSee('Sambal Dasar')
        ->assertSee('Menu Utama')
        ->assertSee('Sub Menu');

    // Membuka resep sub menu menyalakan "Sub Menu", bukan "Menu Utama".
    $html = $this->actingAs($owner)->get(RecipeResource::getUrl('edit', ['record' => $sub]))->assertOk()->getContent();
    expect($html)->toMatch('/fi-sidebar-item-active[^>]*>.*?Sub Menu/s');
});

it('membuat admin dan gudang hanya bisa membaca resep, tim menu bisa mengubah', function () {
    $resep = Recipe::query()->create(['name' => 'Ayam Bakar', 'jenis' => Recipe::JENIS_UTAMA, 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0, 'profit_pct' => 0]);

    foreach (['admin', 'inventory', 'inventory-supervisor'] as $role) {
        $user = penggunaMenuApp($role);
        $this->actingAs($user)->get(RecipeResource::getUrl('index'))->assertOk()->assertSee('Ayam Bakar');
        $this->actingAs($user)->get(RecipeResource::getUrl('create'))->assertForbidden();
        $this->actingAs($user)->get(RecipeResource::getUrl('edit', ['record' => $resep]))->assertForbidden();
        expect($user->can('recipe.manage'))->toBeFalse("{$role} masih boleh mengubah resep.");
    }

    // Kontrol positif: tim menu benar-benar bisa menyimpan perubahan.
    $this->actingAs(penggunaMenuApp('menu'));
    Livewire::test(EditRecipe::class, ['record' => $resep->getRouteKey()])
        ->fillForm(['name' => 'Ayam Bakar Madu'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($resep->fresh()->name)->toBe('Ayam Bakar Madu');

    Livewire::test(CreateRecipe::class)->assertOk();
    Livewire::test(MenuMatching::class)->assertOk();
});

it('menolak aplikasi Menu untuk peran yang tidak membaca resep', function () {
    foreach (['sales', 'delivery', 'marketing'] as $role) {
        $this->actingAs(penggunaMenuApp($role))->get('/menu/recipes')->assertForbidden();
    }

    // Kontrol positif di tes yang sama.
    $this->actingAs(penggunaMenuApp('accounting'))->get('/menu/recipes')->assertOk();
});

it('membangun tautan resep ke /menu walau dibuat dari halaman Inventory', function () {
    // Tanpa penyemat panel, URL resource mengikuti panel yang sedang dibuka
    // (Inventory) dan route-nya tidak ada.
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));

    expect(RecipeResource::getUrl('index'))->toEndWith('/menu/recipes')
        ->and(MenuMatching::getUrl())->toEndWith('/menu/pencocokan-menu')
        ->and(\App\Filament\Resources\ProductionOrderResource::getUrl('index'))->toEndWith('/inventory/production-orders');

    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('menu'));

    expect(\App\Filament\Resources\InventoryItemResource::getUrl('index'))->toEndWith('/inventory/inventory-items')
        ->and(RecipeResource::getUrl('index'))->toEndWith('/menu/recipes');
});
