<?php

use App\Models\Product;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Bagian C: menu yang dicentang Tampil di website tetapi tidak akan ikut terbit
 * diperingatkan di aplikasi Marketing (kotak kuning + saran perbaikan), dan
 * marketing diberi lonceng saat tim menu memindah halaman / menonaktifkan menu
 * yang sedang tampil di website.
 */
function penggunaPeran(string $role): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

function menuTampil(array $attributes = []): Product
{
    return Product::query()->create(array_merge([
        'name' => 'NASI AYAM TERIYAKI 12K',
        'sku' => 'Nasi Ayam Teriyaki',
        'unit' => 'porsi',
        'base_price' => 12000,
        'active' => true,
        'is_3s' => false,
        'show_on_website' => true,
        'website_category' => 'ayam',
    ], $attributes));
}

it('menampilkan kotak peringatan dengan saran perbaikan di dashboard, katalog, dan pratinjau', function () {
    $marketing = penggunaPeran('marketing');
    menuTampil();

    // Kontrol: semua menu siap terbit, kotak peringatan tidak muncul.
    $this->actingAs($marketing)->get(route('marketingapp.dashboard'))
        ->assertOk()
        ->assertDontSee('tidak akan ikut terbit');

    $salahHalaman = menuTampil(['name' => 'RAWON 120K', 'sku' => 'Rawon Daging', 'is_3s' => true, 'website_category' => 'ayam']);
    menuTampil(['name' => 'NASI JINGGO 12K', 'sku' => 'Nasi Jinggo', 'website_category' => null]);
    menuTampil(['name' => 'NASI KUNING 12K', 'sku' => 'Nasi Kuning', 'active' => false]);

    foreach (['marketingapp.dashboard', 'marketingapp.catalog.index', 'marketingapp.website.preview'] as $route) {
        $this->actingAs($marketing)->get(route($route))
            ->assertOk()
            ->assertSee('3 menu dicentang Tampil di website, tetapi tidak akan ikut terbit')
            ->assertSee('Kategori milik halaman lain')
            ->assertSee('Rawon Daging')
            ->assertSee('Pilih kategori baru di Katalog')
            ->assertSee('Belum diberi kategori website')
            ->assertSee('Menu nonaktif di Master Menu')
            ->assertSee('Lepas centang Tampil di website');
    }

    // Saringan dari tombol "Tampilkan menunya di Katalog" memuat ketiganya, termasuk yang nonaktif.
    $this->actingAs($marketing)->get(route('marketingapp.catalog.index', ['website' => 'tanpa-kategori', 'aktif' => 0]))
        ->assertSee('Rawon Daging')->assertSee('Nasi Jinggo')->assertSee('Nasi Kuning')
        ->assertDontSee('NASI AYAM TERIYAKI 12K')
        // Kategori lama tidak ada di pilihan halaman barunya, jadi disebut di kartunya.
        ->assertSee('Kategori lama: Menu Ayam, milik halaman lain.');

    // Setelah dibetulkan (kategori 3S dipilih di Katalog), jenis masalah itu hilang dari kotak.
    $this->actingAs($marketing)
        ->patchJson(route('marketingapp.catalog.website', $salahHalaman), ['show_on_website' => 1, 'website_category' => 'premium'])
        ->assertOk();

    $this->actingAs($marketing)->get(route('marketingapp.dashboard'))
        ->assertSee('2 menu dicentang Tampil di website, tetapi tidak akan ikut terbit')
        ->assertDontSee('Kategori milik halaman lain');
});

it('memberi lonceng ke marketing saat tim menu memindah halaman atau menonaktifkan menu website', function () {
    $admin = penggunaPeran('admin');
    $marketing = penggunaPeran('marketing');
    $menu = menuTampil();
    $belumTampil = menuTampil(['name' => 'NASI JINGGO 12K', 'sku' => 'Nasi Jinggo', 'show_on_website' => false]);

    $form = fn (Product $product, array $overrides) => array_merge([
        'sku' => $product->sku, 'name' => $product->name, 'unit' => 'porsi', 'base_price' => 12000, 'active' => 1,
    ], $overrides);

    // Menu yang tidak tampil di website tidak memicu lonceng.
    $this->actingAs($admin)->put(route('adminapp.products.update', $belumTampil), $form($belumTampil, ['is_3s' => 1]))
        ->assertSessionHasNoErrors();
    expect($marketing->notifications()->count())->toBe(0);

    // Tanda 3S diubah di Master Menu: kategori lamanya milik halaman lain.
    $this->actingAs($admin)->put(route('adminapp.products.update', $menu), $form($menu, ['is_3s' => 1]))
        ->assertSessionHasNoErrors();

    $menu->refresh();
    expect($menu->is_3s)->toBeTrue()
        ->and(Product::query()->onWebsite()->count())->toBe(0)
        ->and($marketing->notifications()->count())->toBe(1);

    $data = $marketing->notifications()->first()->data;
    expect($data['title'])->toBe('Menu website pindah halaman: Nasi Ayam Teriyaki')
        ->and($data['body'])->toContain('Kuliner Tradisional 3S')->toContain('Menu Ayam')->toContain($admin->name)
        // Pelaku sendiri tidak diberi lonceng.
        ->and($admin->notifications()->count())->toBe(0);

    // Dinonaktifkan: lonceng kedua.
    $this->actingAs($admin)->put(route('adminapp.products.update', $menu), $form($menu, ['is_3s' => 1, 'active' => 0]))
        ->assertSessionHasNoErrors();
    expect($marketing->notifications()->count())->toBe(2)
        // Dua lonceng bisa berstempel waktu sama, jadi dicek isinya, bukan urutannya.
        ->and($marketing->notifications()->get()->pluck('data.title')->all())->toContain('Menu website dinonaktifkan: Nasi Ayam Teriyaki');
});
