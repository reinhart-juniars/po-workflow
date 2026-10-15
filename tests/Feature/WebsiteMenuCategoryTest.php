<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Bagian C: tiap menu website punya kategori (kelompok di halaman brand-nya).
 * Brand diturunkan dari is_3s -- menu 3S hanya boleh masuk kategori 3S, dan
 * menu yang dicentang tanpa kategori yang cocok tidak terbit.
 */
function marketingWebsite(): User
{
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    Role::findOrCreate('marketing', 'web');
    $user->assignRole('marketing');

    return $user;
}

function menuWebsite(array $attributes = []): Product
{
    return Product::query()->create(array_merge([
        'name' => 'NASI AYAM TERIYAKI 12K',
        'sku' => 'Nasi Ayam Teriyaki',
        'unit' => 'porsi',
        'base_price' => 12000,
        'active' => true,
        'is_3s' => false,
    ], $attributes));
}

it('menerbitkan menu hanya bila kategorinya cocok dengan brand menu', function () {
    $wwp = menuWebsite(['show_on_website' => true, 'website_category' => 'ayam']);
    $tanpaKategori = menuWebsite(['name' => 'NASI JINGGO 12K', 'sku' => 'Nasi Jinggo', 'show_on_website' => true]);
    $salahBrand = menuWebsite(['name' => 'RAWON 120K', 'sku' => 'Rawon', 'is_3s' => true, 'show_on_website' => true, 'website_category' => 'ayam']);
    $tigaS = menuWebsite(['name' => 'GALANTINE 150K', 'sku' => 'Galantine Classic', 'is_3s' => true, 'show_on_website' => true, 'website_category' => 'tumpeng']);

    expect(Product::query()->onWebsite()->orderBy('id')->pluck('id')->all())->toBe([$wwp->id, $tigaS->id]);

    // Menu dipindah brand di Master Menu: kategori lamanya tidak lagi cocok, menu turun dari website.
    $wwp->update(['is_3s' => true]);
    expect(Product::query()->onWebsite()->pluck('id')->all())->toBe([$tigaS->id])
        ->and($tanpaKategori->fresh()->show_on_website)->toBeTrue()
        ->and($salahBrand->fresh()->show_on_website)->toBeTrue();
});

it('menyimpan kategori website dari katalog dan menolak kategori milik brand lain', function () {
    $marketing = marketingWebsite();
    $menu = menuWebsite();
    $menu3s = menuWebsite(['name' => 'RAWON 120K', 'sku' => 'Rawon', 'is_3s' => true]);

    // Centang tanpa kategori ditolak: website tidak tahu letak menunya.
    $this->actingAs($marketing)
        ->patchJson(route('marketingapp.catalog.website', $menu), ['show_on_website' => 1, 'website_category' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('show_on_website');
    expect($menu->fresh()->show_on_website)->toBeFalse();

    // Kategori 3S untuk menu Warung Wuenak Poll ditolak.
    $this->actingAs($marketing)
        ->patchJson(route('marketingapp.catalog.website', $menu), ['show_on_website' => 1, 'website_category' => 'tumpeng'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('website_category');
    expect($menu->fresh()->website_category)->toBeNull();

    // Kontrol positif: kategori brand sendiri lolos dan tercatat.
    $this->actingAs($marketing)
        ->patchJson(route('marketingapp.catalog.website', $menu), ['show_on_website' => 1, 'website_category' => 'nasi-box'])
        ->assertOk()
        ->assertJson(['show_on_website' => true, 'website_category' => 'nasi-box', 'on_website' => 1]);
    expect(AuditLog::query()->where('action', 'website_shown')->where('entity_id', $menu->id)->value('message'))
        ->toBe('Menu NASI AYAM TERIYAKI 12K (Nasi Ayam Teriyaki) tampil di website, kategori Nasi Box Wuenak Poll');

    // Ganti kategori saja tercatat sebagai perubahan kategori.
    $this->actingAs($marketing)
        ->patchJson(route('marketingapp.catalog.website', $menu), ['show_on_website' => 1, 'website_category' => 'ayam'])
        ->assertOk();
    expect($menu->fresh()->website_category)->toBe('ayam')
        ->and(AuditLog::query()->where('action', 'website_category_changed')->where('entity_id', $menu->id)->exists())->toBeTrue();

    // Menu 3S menerima kategori 3S.
    $this->actingAs($marketing)
        ->patchJson(route('marketingapp.catalog.website', $menu3s), ['show_on_website' => 1, 'website_category' => 'premium'])
        ->assertOk();
    expect(Product::query()->onWebsite()->count())->toBe(2);

    // Form lama tanpa kolom kategori tidak menghapus kategori yang sudah ada.
    $this->actingAs($marketing)
        ->patch(route('marketingapp.catalog.website', $menu), ['show_on_website' => 0])
        ->assertRedirect();
    expect($menu->fresh())->show_on_website->toBeFalse()->website_category->toBe('ayam');
});

it('menawarkan kategori sesuai brand di kartu katalog dan menyaring menu yang belum berkategori', function () {
    $marketing = marketingWebsite();
    menuWebsite(['show_on_website' => true, 'website_category' => 'ayam']);
    menuWebsite(['name' => 'NASI JINGGO 12K', 'sku' => 'Nasi Jinggo', 'show_on_website' => true]);
    menuWebsite(['name' => 'RAWON 120K', 'sku' => 'Rawon', 'is_3s' => true]);

    $this->actingAs($marketing)->get(route('marketingapp.catalog.index'))
        ->assertOk()
        ->assertSee('Menu Ayam')
        ->assertSee('Tumpeng &amp; Hidangan Acara', false);

    $this->actingAs($marketing)->get(route('marketingapp.catalog.index', ['website' => 'tanpa-kategori']))
        ->assertOk()
        ->assertSee('Nasi Jinggo')
        ->assertDontSee('Nasi Ayam Teriyaki')
        ->assertDontSee('Rawon');
});
