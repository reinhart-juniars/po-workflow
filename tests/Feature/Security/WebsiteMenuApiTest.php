<?php

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Bagian C: endpoint menu website dibaca skrip build dengan token. Tanpa token
 * yang benar tidak ada data yang keluar, dan foto hanya untuk menu yang terbit.
 */
beforeEach(function () {
    config(['website.api_token' => 'token-rahasia-uji']);
    Storage::fake('public');
    Storage::fake('local');
});

function menuApi(array $attributes = []): Product
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

function fotoMenu(Product $product, int $width = 1600, int $height = 1200): void
{
    $path = 'menu-photos/'.$product->id.'-'.uniqid().'.jpg';
    Storage::disk('public')->put($path, UploadedFile::fake()->image('foto.jpg', $width, $height)->getContent());
    $product->forceFill(['photo_path' => $path, 'photo_updated_at' => now()])->save();
}

it('menolak permintaan tanpa token yang benar, dan menerima token yang benar', function () {
    menuApi();

    $this->getJson(route('api.website.menu'))->assertUnauthorized();
    $this->getJson(route('api.website.menu'), ['Authorization' => 'Bearer salah'])->assertUnauthorized();

    // Kontrol positif: permintaan yang sama dengan token benar lolos.
    $this->getJson(route('api.website.menu'), ['Authorization' => 'Bearer token-rahasia-uji'])
        ->assertOk()
        ->assertJsonPath('total', 1);
});

it('gagal tertutup bila token belum diatur di server', function () {
    config(['website.api_token' => null]);
    menuApi();

    $this->getJson(route('api.website.menu'), ['Authorization' => 'Bearer '])->assertUnauthorized();
    $this->getJson(route('api.website.menu'), ['Authorization' => 'Bearer token-rahasia-uji'])->assertUnauthorized();
});

it('menyusun menu terbit per brand dan kategori dengan SKU sebagai nama', function () {
    $teriyaki = menuApi();
    $acar = menuApi(['name' => 'NASI AYAM ACAR 12K', 'sku' => 'Nasi Ayam Acar']);
    $rawon = menuApi(['name' => 'RAWON 120K', 'sku' => 'Rawon', 'is_3s' => true, 'website_category' => 'premium']);
    fotoMenu($rawon);
    menuApi(['name' => 'MENU TERSEMBUNYI', 'sku' => 'Tersembunyi', 'show_on_website' => false]);
    menuApi(['name' => 'MENU NONAKTIF', 'sku' => 'Nonaktif', 'active' => false]);

    $response = $this->getJson(route('api.website.menu'), ['Authorization' => 'Bearer token-rahasia-uji'])->assertOk();

    expect($response->json('total'))->toBe(3)
        ->and($response->json('brands.*.key'))->toBe(['wwp', '3s'])
        ->and($response->json('brands.0.count'))->toBe(2)
        ->and($response->json('brands.0.categories.*.key'))->toBe(['nasi-box', 'ayam', 'telur', 'ikan', 'goreng', 'inter'])
        ->and($response->json('brands.0.categories.1.items'))->toBe([
            ['id' => $acar->id, 'name' => 'Nasi Ayam Acar', 'photo' => null],
            ['id' => $teriyaki->id, 'name' => 'Nasi Ayam Teriyaki', 'photo' => null],
        ])
        ->and($response->json('brands.1.categories.0.items.0.name'))->toBe('Rawon')
        ->and($response->json('brands.1.categories.0.items.0.photo.url'))->toContain('/api/website/menu/'.$rawon->id.'/photo?v=');

    $content = $response->getContent();
    expect($content)->not->toContain('Tersembunyi')->not->toContain('Nonaktif')
        // Data internal tidak ikut keluar.
        ->not->toContain('base_price')->not->toContain('12000');
});

it('mengubah versi feed hanya saat isi website berubah', function () {
    $menu = menuApi();
    $feed = app(\App\Services\WebsiteMenuFeed::class);
    $first = $feed->build()['version'];

    $menu->update(['base_price' => 15000]); // harga tidak tampil di website
    expect($feed->build()['version'])->toBe($first);

    $menu->update(['sku' => 'Nasi Ayam Teriyaki Spesial']);
    expect($feed->build()['version'])->not->toBe($first);
});

it('memberi foto 560x420 WebP hanya untuk menu yang terbit', function () {
    $terbit = menuApi();
    fotoMenu($terbit, 900, 1200); // foto potret: dipotong tengah, tidak gepeng
    $internal = menuApi(['name' => 'MENU INTERNAL', 'sku' => 'Menu Internal', 'show_on_website' => false]);
    fotoMenu($internal);
    $auth = ['Authorization' => 'Bearer token-rahasia-uji'];

    $response = $this->get(route('api.website.menu.photo', $terbit), $auth)
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp');
    [$width, $height] = getimagesizefromstring($response->getContent());
    expect([$width, $height])->toBe([560, 420]);

    // Menu internal, menu tanpa foto, dan id ngawur sama-sama 404.
    $this->get(route('api.website.menu.photo', $internal), $auth)->assertNotFound();
    $this->get(route('api.website.menu.photo', menuApi(['name' => 'X', 'sku' => 'Tanpa Foto'])), $auth)->assertNotFound();
    $this->get(route('api.website.menu.photo', 999999), $auth)->assertNotFound();
    // Token tetap wajib untuk foto.
    $this->get(route('api.website.menu.photo', $terbit))->assertUnauthorized();

    // Foto diganti: cache versi lama dibuang, versi baru dibuat.
    fotoMenu($terbit->fresh());
    $this->get(route('api.website.menu.photo', $terbit), $auth)->assertOk();
    expect(Storage::disk('local')->files(\App\Services\WebsiteMenuFeed::PHOTO_CACHE_DIR))->toHaveCount(1);
});
