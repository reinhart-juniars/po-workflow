<?php

use App\Filament\Menu\Resources\RecipeResource;
use App\Models\Recipe;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Halaman tidak boleh harus digeser ke samping di layar laptop.
 *
 * Lebar sebenarnya hanya bisa diukur di browser (sudah dicek per halaman di
 * 1280px dan 1536px); yang dijaga di sini adalah keputusan tata letak yang
 * membuatnya muat, supaya tidak kembali diam-diam.
 */
function penggunaLayout(string $role): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

it('memberi dashboard produksi lebar penuh, sementara delivery tetap kolom sempit', function () {
    $this->actingAs(penggunaLayout('owner'));

    // Dashboard produksi tampil sebagai tabel di desktop: kolom 768px (max-w-3xl)
    // membuat tabelnya harus digeser.
    $this->get(route('productionapp.dashboard'))
        ->assertOk()
        ->assertSee('max-w-7xl', false)
        ->assertDontSee('max-w-3xl', false);

    // Kontrol: layout sempit untuk layar harian masih bekerja di delivery.
    $this->get(route('deliveryapp.dashboard'))
        ->assertOk()
        ->assertSee('max-w-3xl', false);
});

it('memberi nama aplikasi pada tab bilah aplikasi untuk mode ikon saja', function () {
    $this->actingAs(penggunaLayout('owner'));

    // Saat tab tidak muat, label tab lain disembunyikan; nama tetap ada di title.
    $this->get(route('productionapp.dashboard'))
        ->assertOk()
        ->assertSee('title="Production"', false)
        ->assertSee('title="Accounting"', false);
});

it('menyembunyikan kolom Jenis di tab yang isinya satu jenis dan menandai sub-menu di tab campuran', function () {
    $this->actingAs(penggunaLayout('menu'));

    $utama = Recipe::query()->create([
        'name' => 'Ayam Bakar', 'jenis' => Recipe::JENIS_UTAMA, 'kategori' => 'Ayam',
        'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25,
    ]);
    $sub = Recipe::query()->create([
        'name' => 'Bumbu Rujak', 'jenis' => Recipe::JENIS_SUB, 'kategori' => 'Bumbu',
        'yield_qty' => 1, 'yield_unit' => 'kg', 'ohc_pct' => 0.4, 'profit_pct' => 0.25,
    ]);

    // Tab Menu Utama: semua barisnya menu utama, kolom Jenis hanya makan tempat.
    Livewire::test(RecipeResource\Pages\ListRecipes::class)
        ->set('activeTab', 'utama')
        ->assertTableColumnHidden('jenis')
        ->assertTableColumnHasDescription('name', 'Ayam', $utama);

    // Kontrol: di tab Semua kolomnya tetap ada (tampil di layar lebar), dan
    // sub-menu tetap terbedakan lewat keterangan di bawah nama untuk layar laptop.
    Livewire::test(RecipeResource\Pages\ListRecipes::class)
        ->set('activeTab', 'semua')
        ->assertTableColumnVisible('jenis')
        ->assertTableColumnHasDescription('name', 'Sub-Menu · Bumbu', $sub)
        ->assertTableColumnHasDescription('name', 'Ayam', $utama);
});
