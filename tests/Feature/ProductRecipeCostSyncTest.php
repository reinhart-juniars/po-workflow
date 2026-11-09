<?php

use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\User;
use App\Services\ProductRecipeCostSync;
use Spatie\Permission\Models\Role;

/**
 * HPP & OHC di Admin › Master Menu mengikuti resep di aplikasi Menu (revisi
 * 7 Okt 2026), hanya bila hitungan resepnya bersih.
 *
 * Fixture siapkanProduksi(): Gorengan 10 porsi = 250 gr tepung (Rp 12.000/kg)
 * + 150 ml minyak (Rp 20.000/l) = Rp 6.000 -> HPP 600/porsi, OHC 40% = 240.
 */
function adminMenu(): User
{
    Role::findOrCreate('admin', 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole('admin');

    return $user;
}

function sinkron(): array
{
    return app(ProductRecipeCostSync::class)->flush();
}

it('mengisi HPP dan OHC produk dari resep yang bersih dan mencatatnya di riwayat harga', function () {
    $data = siapkanProduksi();
    $product = $data['product'];
    $product->update(['raw_material_cost' => 999, 'overhead_cost' => 111]);

    sinkron();
    $product->refresh();

    expect($product->cost_source)->toBe(Product::COST_RECIPE)
        ->and((float) $product->raw_material_cost)->toBe(600.0)
        ->and((float) $product->overhead_cost)->toBe(240.0)
        ->and((float) $product->profit)->toBe(round((float) $product->base_price - 840, 2))
        ->and($product->cost_synced_at)->not->toBeNull()
        ->and($product->priceHistories()->latest('id')->value('reason'))->toContain('dari resep "Gorengan"');
});

it('membiarkan angka manual bila resep belum lengkap, lalu mengikutinya begitu lengkap', function () {
    $data = siapkanProduksi();
    $orphan = RecipeItem::query()->create(['recipe_id' => $data['recipe']->id, 'raw_name' => 'garam misterius', 'qty' => 5, 'unit' => 'gram']);
    $data['product']->update(['raw_material_cost' => 999, 'overhead_cost' => 111]);

    sinkron();
    $status = app(ProductRecipeCostSync::class)->evaluate($data['product']->fresh());

    expect($data['product']->fresh())->cost_source->toBe(Product::COST_MANUAL)
        ->and((float) $data['product']->fresh()->raw_material_cost)->toBe(999.0)
        ->and($status['state'])->toBe('tertahan')
        ->and($status['reason'])->toContain('belum lengkap');

    // Kontrol positif: baris yatim dihapus -> resep bersih -> produk ikut resep.
    $orphan->delete();
    sinkron();

    expect($data['product']->fresh()->cost_source)->toBe(Product::COST_RECIPE)
        ->and((float) $data['product']->fresh()->raw_material_cost)->toBe(600.0);
});

it('menghitung ulang produk saat harga bahan berubah, termasuk lewat sub menu', function () {
    $data = siapkanProduksi();

    // Menu Paket memakai Gorengan sebagai sub menu: 1 porsi Paket = 2 porsi Gorengan.
    $paket = Recipe::query()->create(['name' => 'Paket Gorengan', 'jenis' => Recipe::JENIS_UTAMA, 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0, 'profit_pct' => 0]);
    RecipeItem::query()->create(['recipe_id' => $paket->id, 'ref_recipe_id' => $data['recipe']->id, 'raw_name' => 'Gorengan', 'qty' => 2, 'unit' => 'porsi']);
    $produkPaket = Product::query()->create(['name' => 'PAKET GORENGAN', 'sku' => 'PAKET-GOR', 'unit' => 'porsi', 'base_price' => 5000, 'active' => true, 'recipe_id' => $paket->id]);
    sinkron();
    expect((float) $produkPaket->fresh()->raw_material_cost)->toBe(1200.0);

    // Tepung naik 12.000 -> 24.000/kg: Gorengan 900/porsi, Paket 1.800.
    $data['tepung']->update(['unit_price' => 24000]);
    sinkron();

    expect((float) $data['product']->fresh()->raw_material_cost)->toBe(900.0)
        ->and((float) $produkPaket->fresh()->raw_material_cost)->toBe(1800.0);
});

it('mengembalikan produk ke manual saat dilepas dari resep', function () {
    $data = siapkanProduksi();
    sinkron();
    expect($data['product']->fresh()->cost_source)->toBe(Product::COST_RECIPE);

    $data['product']->update(['recipe_id' => null]);
    sinkron();

    expect($data['product']->fresh())->cost_source->toBe(Product::COST_MANUAL)
        ->and((float) $data['product']->fresh()->raw_material_cost)->toBe(600.0); // angka terakhir dipertahankan
});

it('menahan sinkron bila satuan produk tidak sepadan dengan satuan hasil resep', function () {
    $data = siapkanProduksi();
    $data['product']->update(['unit' => 'liter', 'raw_material_cost' => 777]);
    sinkron();

    $status = app(ProductRecipeCostSync::class)->evaluate($data['product']->fresh());

    expect($data['product']->fresh()->cost_source)->toBe(Product::COST_MANUAL)
        ->and((float) $data['product']->fresh()->raw_material_cost)->toBe(777.0)
        ->and($status['reason'])->toContain('tidak sepadan');
});

it('mengunci HPP dan OHC di Admin untuk produk yang mengikuti resep, tapi harga jual tetap bisa diubah', function () {
    $data = siapkanProduksi();
    sinkron();
    $product = $data['product']->fresh();
    $admin = adminMenu();

    // Harga Menu admin: HPP & OHC hanya ditampilkan, satu-satunya isian = Harga Non Lapak.
    $this->actingAs($admin)->get(route('adminapp.products.edit', $product))->assertOk()
        ->assertSee('name="base_price"', false)
        ->assertDontSee('name="raw_material_cost"', false);

    $this->actingAs($admin)->put(route('adminapp.products.update', $product), [
        'sku' => 'GORENGAN-10K', 'name' => $product->name, 'unit' => $product->unit,
        'base_price' => 15000, 'raw_material_cost' => 1, 'overhead_cost' => 1, 'active' => 1,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $product->refresh();
    expect((float) $product->base_price)->toBe(15000.0)
        ->and((float) $product->raw_material_cost)->toBe(600.0)
        ->and((float) $product->overhead_cost)->toBe(240.0)
        ->and((float) $product->profit)->toBe(14160.0);

    // Produk manual pun: HPP manual kini diisi tim menu, bukan admin.
    $manual = $data['tanpaResep'];
    $before = $manual->fresh()->raw_material_cost;
    $this->actingAs($admin)->put(route('adminapp.products.update', $manual), [
        'sku' => 'ES-TEH', 'name' => $manual->name, 'unit' => $manual->unit,
        'base_price' => 5000, 'raw_material_cost' => 1500, 'overhead_cost' => 500, 'active' => 1,
    ])->assertSessionHasNoErrors()->assertRedirect();
    expect($manual->fresh()->raw_material_cost)->toBe($before)
        ->and((float) $manual->fresh()->base_price)->toBe(5000.0);
});

it('menyinkronkan otomatis di akhir request saat resep disimpan lewat HTTP', function () {
    $data = siapkanProduksi();
    sinkron();

    // Request apa pun yang selesai menjalankan antrean (app()->terminating).
    $data['recipe']->update(['ohc_pct' => 0.5]);
    $this->actingAs(adminMenu())->get(route('adminapp.dashboard'))->assertOk();

    expect((float) $data['product']->fresh()->overhead_cost)->toBe(300.0);
});

it('menjadikan Harga Jual Target resep sebagai Harga Lapak produk tanpa menyentuh Harga Jual', function () {
    $data = siapkanProduksi();
    $product = $data['product'];
    $product->update(['base_price' => 15000, 'lapak_price' => null]);

    $data['recipe']->update(['target_price' => 12000]);
    sinkron();

    expect((float) $product->fresh()->lapak_price)->toBe(12000.0)
        ->and((float) $product->fresh()->base_price)->toBe(15000.0);

    // Target diubah tim menu -> harga lapak ikut.
    $data['recipe']->update(['target_price' => 13500]);
    sinkron();
    expect((float) $product->fresh()->lapak_price)->toBe(13500.0);
});

it('tidak memakai target resep untuk varian harga yang berbagi resep', function () {
    $data = siapkanProduksi();
    $data['product']->update(['base_price' => 12000, 'lapak_price' => null]);

    // Varian 10K berbagi resep dengan varian 12K; targetnya hanya satu angka (12.000).
    $varian = Product::query()->create(['name' => 'GORENGAN 10K', 'sku' => 'GORENGAN-10K-B', 'unit' => 'porsi', 'base_price' => 10000, 'active' => true, 'recipe_id' => $data['recipe']->id]);
    $data['recipe']->update(['target_price' => 12000]);
    sinkron();

    expect($varian->fresh()->lapak_price)->toBeNull()
        ->and($data['product']->fresh()->lapak_price)->toBeNull()
        ->and($varian->fresh()->lapakFollowsRecipe())->toBeFalse();

    // Kontrol positif: varian dilepas -> resep tinggal satu produk -> harga lapaknya ikut target.
    $varian->update(['recipe_id' => null]);
    sinkron();
    expect((float) $data['product']->fresh()->lapak_price)->toBe(12000.0);
});

it('mengikuti target walau hitungan HPP resep belum lengkap, dan membiarkan harga lapak admin bila resep tanpa target', function () {
    $data = siapkanProduksi();
    RecipeItem::query()->create(['recipe_id' => $data['recipe']->id, 'raw_name' => 'garam misterius', 'qty' => 5, 'unit' => 'gram']);
    $data['product']->update(['lapak_price' => 9000]);

    // Tanpa target: harga lapak isian admin tetap.
    sinkron();
    expect((float) $data['product']->fresh()->lapak_price)->toBe(9000.0)
        ->and($data['product']->fresh()->lapakFollowsRecipe())->toBeFalse();

    // Kontrol positif: begitu target diisi, harga lapak ikut walau HPP masih tertahan.
    $data['recipe']->update(['target_price' => 11000]);
    sinkron();

    expect($data['product']->fresh())
        ->cost_source->toBe(Product::COST_MANUAL)
        ->and((float) $data['product']->fresh()->lapak_price)->toBe(11000.0);
});

it('mengunci Harga Lapak dari resep di Admin, tetapi Harga Non Lapak tetap diisi admin', function () {
    $data = siapkanProduksi();
    $data['recipe']->update(['target_price' => 12000]);
    sinkron();
    $product = $data['product']->fresh();
    $admin = adminMenu();

    $this->actingAs($admin)->get(route('adminapp.products.edit', $product))->assertOk()
        ->assertSee('Dari Harga Jual Target resep', false);

    $this->actingAs($admin)->get(route('adminapp.products.index'))->assertOk()
        ->assertSeeInOrder(['Lapak', '12.000', '· resep', 'Non Lapak']);

    $this->actingAs($admin)->put(route('adminapp.products.update', $product), [
        'sku' => 'GORENGAN-10K', 'name' => $product->name, 'unit' => $product->unit,
        'base_price' => 16000, 'lapak_price' => 1, 'active' => 1,
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect((float) $product->fresh()->base_price)->toBe(16000.0)
        ->and((float) $product->fresh()->lapak_price)->toBe(12000.0);

    // Kontrol positif: menu tanpa resep tetap bebas diisi harga lapaknya (oleh tim menu).
    $manual = $data['tanpaResep'];
    $manual->update(['lapak_price' => 4000]);
    expect((float) $manual->fresh()->lapak_price)->toBe(4000.0);
});

it('menyalin harga menu asal v2 ke Harga Lapak tanpa mengosongkan Harga Jual', function () {
    $migration = require database_path('migrations/2026_11_03_100001_copy_v2_prices_to_lapak_price.php');

    $buat = function (string $sku, float $harga, ?float $lapak, ?string $origin) {
        $product = Product::query()->create(['name' => $sku, 'sku' => $sku, 'unit' => 'porsi', 'base_price' => $harga, 'lapak_price' => $lapak, 'active' => true]);
        $product->forceFill(['origin' => $origin])->saveQuietly();

        return $product;
    };

    $v2 = $buat('ALACARTE-DORI-20K', 20000, null, Product::ORIGIN_V2);
    $v2Berlapak = $buat('V2-BERLAPAK', 15000, 13000, Product::ORIGIN_V2);
    $v2Nol = $buat('V2-NOL', 0, null, Product::ORIGIN_V2);
    $baru = $buat('BARU-3SONE', 25000, null, null);

    $migration->up();

    expect((float) $v2->fresh()->lapak_price)->toBe(20000.0)
        ->and((float) $v2->fresh()->base_price)->toBe(20000.0)
        ->and((float) $v2Berlapak->fresh()->lapak_price)->toBe(13000.0)
        ->and($v2Nol->fresh()->lapak_price)->toBeNull()
        // Menu buatan 3S ONE: harganya memang harga jual.
        ->and($baru->fresh()->lapak_price)->toBeNull();

    $migration->down();
    expect($v2->fresh()->lapak_price)->toBeNull()
        ->and((float) $v2Berlapak->fresh()->lapak_price)->toBe(13000.0);
});
