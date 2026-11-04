<?php

use App\Imports\ProductsImport;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\User;
use App\Services\ProductRecipeCostSync;
use App\Services\ProfitGuardService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Profit & margin menu dihitung dari Harga Lapak (revisi user 4 Nov 2026).
 *
 * Harga Non Lapak diisi admin untuk customer lain dan tidak boleh menggeser
 * profit. Menu tanpa harga lapak memakai Harga Non Lapak, sama seperti aturan
 * harga di PO.
 */
function adminProfit(): User
{
    Role::findOrCreate('admin', 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole('admin');

    return $user;
}

function menuProfit(array $extra = []): Product
{
    return Product::query()->create($extra + [
        'name' => 'NASI UJI PROFIT', 'sku' => 'NASI-UJI-PROFIT', 'unit' => 'porsi', 'active' => true,
        'base_price' => 20000, 'lapak_price' => 20000, 'raw_material_cost' => 9000, 'overhead_cost' => 3000,
    ]);
}

function ubahMenu(Product $menu, array $harga): void
{
    test()->put(route('adminapp.products.update', $menu), $harga + [
        'sku' => $menu->sku, 'name' => $menu->name, 'unit' => $menu->unit, 'base_price' => $menu->base_price,
        'lapak_price' => $menu->lapak_price, 'raw_material_cost' => $menu->raw_material_cost,
        'overhead_cost' => $menu->overhead_cost, 'active' => 1,
    ])->assertSessionHasNoErrors()->assertRedirect();
}

it('tidak menggeser profit saat Harga Non Lapak diganti, tetapi mengikuti Harga Lapak', function () {
    $this->actingAs(adminProfit());
    $menu = menuProfit();
    expect((float) $menu->fresh()->profit)->toBe(8000.0);

    ubahMenu($menu->fresh(), ['base_price' => 30000]);
    expect((float) $menu->fresh()->base_price)->toBe(30000.0)
        ->and((float) $menu->fresh()->profit)->toBe(8000.0);

    // Kontrol positif: Harga Lapak yang diganti menggeser profit.
    ubahMenu($menu->fresh(), ['lapak_price' => 22000]);
    expect((float) $menu->fresh()->profit)->toBe(10000.0)
        ->and((float) $menu->fresh()->base_price)->toBe(30000.0);

    // Riwayat mencatat Harga Lapak, karena angka itu yang menentukan profit.
    $riwayat = $menu->priceHistories()->orderByDesc('id')->first();
    expect((float) $riwayat->lapak_price)->toBe(22000.0)
        ->and((float) $riwayat->profit)->toBe(10000.0);
});

it('memakai Harga Non Lapak sebagai dasar profit bila Harga Lapak kosong', function () {
    $this->actingAs(adminProfit());
    $menu = menuProfit(['lapak_price' => null]);

    expect((float) $menu->fresh()->profit)->toBe(8000.0);

    ubahMenu($menu->fresh(), ['base_price' => 25000]);
    expect((float) $menu->fresh()->profit)->toBe(13000.0);
});

it('menghitung margin daftar Master Menu dari Harga Lapak', function () {
    $this->actingAs(adminProfit());
    // Profit 8.000 dari lapak 20.000 = margin 40%; dari non lapak 40.000 akan jadi 20%.
    menuProfit(['base_price' => 40000]);

    $this->get(route('adminapp.products.index'))->assertOk()
        ->assertSee('40,00%')
        ->assertDontSee('20,00%');
});

it('menghitung ulang profit saat Harga Lapak terisi dari target resep yang HPP-nya belum lengkap', function () {
    $resep = Recipe::query()->create(['name' => 'Resep Uji', 'jenis' => Recipe::JENIS_UTAMA, 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25, 'target_price' => 26000, 'is_active' => true]);
    RecipeItem::query()->create(['recipe_id' => $resep->id, 'raw_name' => 'bahan misterius', 'qty' => 1, 'unit' => 'gram']);
    $menu = menuProfit(['recipe_id' => $resep->id]);

    app(ProductRecipeCostSync::class)->flush();

    expect($menu->fresh())
        ->cost_source->toBe(Product::COST_MANUAL)
        ->and((float) $menu->fresh()->lapak_price)->toBe(26000.0)
        ->and((float) $menu->fresh()->profit)->toBe(14000.0)
        ->and($menu->fresh()->priceHistories()->orderByDesc('id')->value('reason'))->toContain('Harga Jual Target');
});

it('memakai Harga Lapak untuk batas profit keseluruhan', function () {
    $bucket = InventoryItem::query()->create(['name' => 'Bahan Baku', 'unit' => 'All', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);
    $bahan = InventoryItem::query()->create(['parent_id' => $bucket->id, 'name' => 'Bahan', 'unit' => 'kg', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'unit_price' => 10000, 'is_active' => true]);
    $resep = Recipe::query()->create(['name' => 'Resep Guard', 'jenis' => Recipe::JENIS_UTAMA, 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0, 'profit_pct' => 0.25, 'is_active' => true]);
    RecipeItem::query()->create(['recipe_id' => $resep->id, 'inventory_item_id' => $bahan->id, 'raw_name' => 'bahan', 'qty' => 1, 'unit' => 'kg']);

    // Biaya 10.000: lapak 12.000 = 20%, sedangkan non lapak 30.000 akan jadi 200%.
    menuProfit(['recipe_id' => $resep->id, 'base_price' => 30000, 'lapak_price' => 12000]);

    expect(app(ProfitGuardService::class)->check()['profit_pct'])->toBe(20.0);
});

it('membunyikan lonceng perubahan Harga Lapak dan Harga Non Lapak dengan namanya masing-masing', function () {
    $this->actingAs(adminProfit());
    Role::findOrCreate('owner', 'web');
    $owner = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $owner->assignRole('owner');
    $menu = menuProfit();

    $menu->fresh()->update(['lapak_price' => 22000]);
    $menu->fresh()->update(['base_price' => 30000]);

    $judul = DatabaseNotification::query()->where('notifiable_id', $owner->id)->get()->map(fn ($n) => $n->data['title'])->all();

    expect($judul)->toContain('Harga lapak NASI UJI PROFIT naik 10%', 'Harga non lapak NASI UJI PROFIT naik 50%');
});

it('menghitung profit pratinjau impor dari Harga Lapak', function () {
    $menu = menuProfit();

    $pratinjau = function (array $baris) use ($menu) {
        $import = new ProductsImport(commit: false);
        $import->collection(collect([collect($baris + ['id' => $menu->id, 'nama_menu' => $menu->name, 'satuan' => 'porsi', 'bahan_baku' => 9000, 'overhead_cost' => 3000])]));

        return $import->previewRows()[0]['profit'];
    };

    expect($pratinjau(['harga_non_lapak' => 30000, 'harga_lapak' => 21000]))->toBe(9000.0)
        // Berkas tanpa kolom lapak: harga lapak tersimpan yang dipakai.
        ->and($pratinjau(['harga_non_lapak' => 30000]))->toBe(8000.0);
});

it('menghitung ulang profit menu yang sudah ada lewat migrasi', function () {
    $menu = menuProfit(['base_price' => 30000]);
    DB::table('products')->whereKey($menu->id)->update(['profit' => 18000]);
    $tanpaLapak = menuProfit(['name' => 'TANPA LAPAK', 'sku' => 'TANPA-LAPAK', 'lapak_price' => null, 'base_price' => 15000]);
    DB::table('products')->whereKey($tanpaLapak->id)->update(['profit' => 1]);

    $migration = require database_path('migrations/2026_11_04_100002_base_product_profit_on_lapak_price.php');
    $migration->down();
    expect((float) $menu->fresh()->profit)->toBe(18000.0);

    $migration->up();
    expect((float) $menu->fresh()->profit)->toBe(8000.0)
        ->and((float) $tanpaLapak->fresh()->profit)->toBe(3000.0);
});
