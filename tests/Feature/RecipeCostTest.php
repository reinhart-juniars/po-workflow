<?php

use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Services\RecipeCostService;

/**
 * Mesin HPP berbasis resep.
 *
 * Yang dijaga di sini bukan hanya angkanya benar, tetapi juga bahwa angka yang
 * TIDAK bisa dihitung tidak pernah menyamar sebagai nol yang wajar -- HPP yang
 * diam-diam kekecilan akan terbawa ke Laba Rugi tanpa ada yang curiga.
 */
function bahan(string $nama, string $unit, ?float $unitPrice, array $extra = []): InventoryItem
{
    $bucket = InventoryItem::query()->firstOrCreate(
        ['name' => 'Bahan Baku'],
        ['unit' => 'All', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]
    );

    return InventoryItem::query()->create(array_merge([
        'parent_id' => $bucket->id,
        'name' => $nama,
        'unit' => $unit,
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'unit_price' => $unitPrice,
        'is_active' => true,
    ], $extra));
}

function resep(string $nama, array $extra = []): Recipe
{
    return Recipe::query()->create(array_merge([
        'name' => $nama,
        'jenis' => Recipe::JENIS_UTAMA,
        'yield_qty' => 1,
        'yield_unit' => 'porsi',
        'ohc_pct' => 0.40,
        'profit_pct' => 0.25,
    ], $extra));
}

function baris(Recipe $recipe, array $attributes): RecipeItem
{
    return RecipeItem::query()->create(array_merge([
        'recipe_id' => $recipe->id,
        'raw_name' => 'bahan',
        'qty' => 1,
        'unit' => 'gram',
    ], $attributes));
}

it('menghitung biaya baris dengan konversi satuan ke satuan harga bahan', function () {
    // Tepung dihargai per kg, resep menulisnya dalam gram.
    $tepung = bahan('Tepung Terigu', 'kg', 12000);
    $minyak = bahan('Minyak Goreng', 'liter', 20000);

    $r = resep('Gorengan', ['yield_qty' => 10, 'yield_unit' => 'porsi']);

    baris($r, ['inventory_item_id' => $tepung->id, 'raw_name' => 'tepung', 'qty' => 250, 'unit' => 'gr']);
    baris($r, ['inventory_item_id' => $minyak->id, 'raw_name' => 'minyak', 'qty' => 150, 'unit' => 'ml']);

    $cost = app(RecipeCostService::class)->cost($r);

    // 250 gr x Rp 12.000/kg = 3.000 ; 150 ml x Rp 20.000/liter = 3.000
    expect($cost['total_raw'])->toBe(6000.0)
        ->and($cost['hpp_per_yield'])->toBe(600.0)
        ->and($cost['has_unmatched'])->toBeFalse()
        ->and($cost['issues'])->toBe([]);
});

it('menurunkan ohc, profit, dan harga jual dari hpp', function () {
    $tepung = bahan('Tepung Terigu', 'kg', 12000);
    $r = resep('Gorengan', ['yield_qty' => 1, 'ohc_pct' => 0.40, 'profit_pct' => 0.25]);
    baris($r, ['inventory_item_id' => $tepung->id, 'qty' => 1000, 'unit' => 'gr']);

    $cost = app(RecipeCostService::class)->cost($r);

    // HPP 12.000 ; OHC 40% = 4.800 ; Profit 25% x (12.000+4.800) = 4.200
    expect($cost['hpp_per_yield'])->toBe(12000.0)
        ->and($cost['ohc'])->toBe(4800.0)
        ->and($cost['profit'])->toBe(4200.0)
        ->and($cost['harga_jual'])->toBe(21000.0)
        ->and($cost['total_biaya'])->toBe(16800.0);
});

it('mengukur profit terhadap harga jual target bila targetnya diisi', function () {
    $tepung = bahan('Tepung Terigu', 'kg', 12000);
    $r = resep('Gorengan', ['yield_qty' => 1, 'target_price' => 18000]);
    baris($r, ['inventory_item_id' => $tepung->id, 'qty' => 1000, 'unit' => 'gr']);

    $cost = app(RecipeCostService::class)->cost($r);

    // Biaya 16.800, dijual 18.000 -> profit nyata 1.200, jauh di bawah target 25%.
    expect($cost['pakai_target'])->toBeTrue()
        ->and($cost['harga_jual_dipakai'])->toBe(18000.0)
        ->and($cost['profit_aktual'])->toBe(1200.0)
        ->and($cost['profit_ok'])->toBeFalse();
});

it('menghitung sub-resep berdasarkan hpp per hasilnya', function () {
    $cabai = bahan('Cabai Merah', 'kg', 40000);

    // Sambal: 1 kg cabai menghasilkan 20 porsi -> HPP 2.000 per porsi.
    $sambal = resep('Sambal Matah', ['jenis' => Recipe::JENIS_SUB, 'yield_qty' => 20, 'yield_unit' => 'porsi']);
    baris($sambal, ['inventory_item_id' => $cabai->id, 'raw_name' => 'cabai', 'qty' => 1, 'unit' => 'kg']);

    $nasi = resep('Nasi Sambal Matah', ['yield_qty' => 1]);
    baris($nasi, ['ref_recipe_id' => $sambal->id, 'raw_name' => 'sambal matah', 'qty' => 2, 'unit' => 'porsi']);

    $cost = app(RecipeCostService::class)->cost($nasi);

    expect($cost['total_raw'])->toBe(4000.0)
        ->and($cost['lines'][0]['source'])->toBe('recipe')
        ->and($cost['lines'][0]['unit_price'])->toBe(2000.0);
});

it('menandai baris yang belum ditautkan alih-alih menganggapnya gratis', function () {
    $r = resep('Menu Setengah Jadi');
    baris($r, ['raw_name' => 'garam', 'qty' => 10, 'unit' => 'gr']);

    $cost = app(RecipeCostService::class)->cost($r);

    expect($cost['has_unmatched'])->toBeTrue()
        ->and($cost['total_raw'])->toBe(0.0)
        ->and($cost['issues'][0])->toContain('garam')
        ->and($cost['lines'][0]['unmatched'])->toBeTrue();
});

it('memakai harga cadangan untuk baris belum tertaut yang punya snapshot', function () {
    $r = resep('Menu Setengah Jadi');
    baris($r, ['raw_name' => 'garam', 'qty' => 10, 'unit' => 'gr', 'unit_price_snapshot' => 50]);

    $cost = app(RecipeCostService::class)->cost($r);

    expect($cost['total_raw'])->toBe(500.0)
        ->and($cost['lines'][0]['source'])->toBe('snapshot')
        // Tetap ditandai walau ada angkanya: harga cadangan bukan harga sungguhan.
        ->and($cost['has_unmatched'])->toBeTrue()
        ->and($cost['issues'][0])->toContain('harga cadangan');
});

it('menandai satuan yang tidak sepadan alih-alih menebak konversinya', function () {
    // Telur dihargai per kg, tetapi resep menulisnya dalam butir. Berapa gram
    // satu butir berbeda-beda per bahan, jadi konversinya tidak boleh ditebak.
    $telur = bahan('Telur Ayam', 'kg', 28000);
    $r = resep('Telur Dadar');
    baris($r, ['inventory_item_id' => $telur->id, 'raw_name' => 'telur', 'qty' => 2, 'unit' => 'butir']);

    $cost = app(RecipeCostService::class)->cost($r);

    expect($cost['total_raw'])->toBe(0.0)
        ->and($cost['issues'][0])->toContain('tidak bisa dikonversi');

    // Positive control: dengan satuan yang sepadan, baris yang sama terhitung.
    $r2 = resep('Telur Dadar Gram');
    baris($r2, ['inventory_item_id' => $telur->id, 'raw_name' => 'telur', 'qty' => 120, 'unit' => 'gr']);

    expect(app(RecipeCostService::class)->cost($r2)['total_raw'])->toBe(3360.0);
});

it('menerima satuan tak dikenal asalkan kedua sisi memakai satuan yang sama', function () {
    // "sdb" tidak ada di registri satuan, tetapi bila bahan dan resep sama-sama
    // memakainya tidak ada konversi yang perlu dilakukan.
    $bumbu = bahan('Bumbu Racikan', 'sdb', 500);
    $r = resep('Menu Bumbu');
    baris($r, ['inventory_item_id' => $bumbu->id, 'raw_name' => 'bumbu', 'qty' => 3, 'unit' => 'sdb']);

    $cost = app(RecipeCostService::class)->cost($r);

    expect($cost['total_raw'])->toBe(1500.0)
        ->and($cost['issues'])->toBe([]);
});

it('menghentikan resep yang saling memanggil dan menandainya', function () {
    $a = resep('Resep A');
    $b = resep('Resep B', ['jenis' => Recipe::JENIS_SUB]);

    baris($a, ['ref_recipe_id' => $b->id, 'raw_name' => 'resep b', 'qty' => 1, 'unit' => 'porsi']);
    baris($b, ['ref_recipe_id' => $a->id, 'raw_name' => 'resep a', 'qty' => 1, 'unit' => 'porsi']);

    $cost = app(RecipeCostService::class)->cost($a);

    expect($cost['has_cycle'])->toBeTrue()
        ->and(collect($cost['issues'])->implode(' '))->toContain('berputar');
});

it('memakai angka snapshot untuk menu yang tidak punya rincian bahan', function () {
    $r = resep('Menu Warisan Excel', [
        'yield_qty' => 1,
        'snapshot_hpp' => 9000,
        'snapshot_ohc' => 3600,
        'snapshot_profit' => 3150,
    ]);

    $cost = app(RecipeCostService::class)->cost($r);

    expect($cost['uses_snapshot'])->toBeTrue()
        ->and($cost['hpp_per_yield'])->toBe(9000.0)
        ->and($cost['ohc'])->toBe(3600.0)
        ->and($cost['harga_jual'])->toBe(15750.0);
});

it('menjumlahkan kebutuhan bahan lintas sub-resep untuk sejumlah produksi', function () {
    $cabai = bahan('Cabai Merah', 'kg', 40000);
    $bawang = bahan('Bawang Merah', 'kg', 30000);

    $sambal = resep('Sambal', ['jenis' => Recipe::JENIS_SUB, 'yield_qty' => 10, 'yield_unit' => 'porsi']);
    baris($sambal, ['inventory_item_id' => $cabai->id, 'raw_name' => 'cabai', 'qty' => 500, 'unit' => 'gr']);
    baris($sambal, ['inventory_item_id' => $bawang->id, 'raw_name' => 'bawang', 'qty' => 200, 'unit' => 'gr']);

    $nasi = resep('Nasi Sambal', ['yield_qty' => 1, 'yield_unit' => 'porsi']);
    baris($nasi, ['ref_recipe_id' => $sambal->id, 'raw_name' => 'sambal', 'qty' => 1, 'unit' => 'porsi']);
    // Cabai juga dipakai langsung, jadi kebutuhannya harus menyatu dengan yang
    // datang lewat sub-resep, bukan menjadi dua baris terpisah.
    baris($nasi, ['inventory_item_id' => $cabai->id, 'raw_name' => 'cabai iris', 'qty' => 10, 'unit' => 'gr']);

    $req = app(RecipeCostService::class)->requirements($nasi, 100);

    $cabaiRow = $req['rows']->firstWhere('inventory_item_id', $cabai->id);
    $bawangRow = $req['rows']->firstWhere('inventory_item_id', $bawang->id);

    // 100 porsi -> sambal 100/10 = 10 batch: cabai 500 gr x 10 = 5.000 gr,
    // ditambah 10 gr x 100 porsi = 1.000 gr -> 6.000 gr = 6 kg.
    expect($req['rows'])->toHaveCount(2)
        ->and((float) $cabaiRow['qty'])->toBe(6.0)
        ->and($cabaiRow['unit'])->toBe('kg')
        ->and((float) $bawangRow['qty'])->toBe(2.0)
        ->and($req['total_cost'])->toBe(300000.0)
        ->and($req['has_cycle'])->toBeFalse();
});

it('menolak menghitung kebutuhan dari resep tanpa jumlah hasil', function () {
    $tepung = bahan('Tepung Terigu', 'kg', 12000);
    $r = resep('Resep Tanpa Yield', ['yield_qty' => 0]);
    baris($r, ['inventory_item_id' => $tepung->id, 'qty' => 100, 'unit' => 'gr']);

    $req = app(RecipeCostService::class)->requirements($r, 50);

    expect($req['rows'])->toBeEmpty()
        ->and($req['issues'][0])->toContain('tidak punya jumlah hasil');

    // Positive control: begitu yield diisi, kebutuhan yang sama terhitung.
    $r->update(['yield_qty' => 10]);

    expect(app(RecipeCostService::class)->requirements($r, 50)['rows'])->toHaveCount(1);
});
