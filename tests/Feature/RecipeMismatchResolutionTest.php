<?php

use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeMismatch;
use App\Services\RecipeCostService;
use App\Services\RecipeMismatchResolver;

/**
 * Rekonsiliasi bahan resep yang belum punya padanan.
 *
 * Keputusannya diambil per nama, bukan per baris: sekali "garam" ditautkan,
 * ratusan baris di puluhan resep ikut selesai. Yang paling perlu dijaga adalah
 * keputusan itu bertahan -- perpindahan data dari Master Menu menyusun ulang
 * seluruh baris resep tiap kali dijalankan, dan tanpa penerapan ulang, seluruh
 * kerja rekonsiliasi bersama klien hilang pada penyegaran data berikutnya.
 */
function bahanMismatch(string $nama, string $unit = 'kg', ?float $harga = 8000): InventoryItem
{
    $bucket = InventoryItem::query()->firstOrCreate(
        ['name' => 'Bahan Baku'],
        ['unit' => 'All', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]
    );

    return InventoryItem::query()->create([
        'parent_id' => $bucket->id,
        'name' => $nama,
        'unit' => $unit,
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'unit_price' => $harga,
        'is_active' => true,
    ]);
}

function resepMismatch(string $nama): Recipe
{
    return Recipe::query()->create([
        'name' => $nama,
        'jenis' => Recipe::JENIS_UTAMA,
        'yield_qty' => 1,
        'yield_unit' => 'porsi',
        'ohc_pct' => 0.40,
        'profit_pct' => 0.25,
    ]);
}

function barisYatim(Recipe $recipe, string $rawName, float $qty = 10, string $unit = 'gr'): RecipeItem
{
    return RecipeItem::query()->create([
        'recipe_id' => $recipe->id,
        'raw_name' => $rawName,
        'qty' => $qty,
        'unit' => $unit,
    ]);
}

function mismatchGaram(string $nama = 'Garam', string $norm = 'garam'): RecipeMismatch
{
    return RecipeMismatch::query()->create([
        'raw_name' => $nama,
        'raw_name_norm' => $norm,
        'occurrence_count' => 0,
        'recipe_count' => 0,
        'sample_unit' => 'gr',
        'status' => RecipeMismatch::STATUS_OPEN,
    ]);
}

beforeEach(function () {
    $this->resolver = app(RecipeMismatchResolver::class);
});

it('menautkan seluruh baris bernama sama dalam satu keputusan', function () {
    $satu = resepMismatch('Sop Ayam');
    $dua = resepMismatch('Nasi Goreng');

    barisYatim($satu, 'garam');
    // Ejaan yang berbeda spasi dan huruf besarnya tetap satu nama yang sama.
    barisYatim($dua, '  Garam  ');
    barisYatim($dua, 'gula');

    $garam = bahanMismatch('Garam Dapur');
    $affected = $this->resolver->linkToItem(mismatchGaram(), $garam);

    expect($affected)->toBe(2)
        ->and(RecipeItem::query()->where('inventory_item_id', $garam->id)->count())->toBe(2)
        // Baris bernama lain tidak ikut tersentuh.
        ->and(RecipeItem::query()->unmatched()->count())->toBe(1);
});

it('membuat bahan baru di bawah bucket yang benar lalu menautkannya', function () {
    $resep = resepMismatch('Sop Ayam');
    barisYatim($resep, 'garam');

    // Bucket harus sudah ada supaya bahan baru bergabung, bukan berdiri sendiri.
    bahanMismatch('Tepung');

    $hasil = $this->resolver->createItem(mismatchGaram(), [
        'name' => 'Garam Dapur',
        'unit' => 'kg',
        'unit_price' => 8000,
    ]);

    $mismatch = RecipeMismatch::query()->firstWhere('raw_name_norm', 'garam');

    expect($hasil['affected'])->toBe(1)
        ->and($hasil['item']->parent->category)->toBe(InventoryItem::CATEGORY_RAW_MATERIAL)
        ->and($hasil['item']->isBucket())->toBeFalse()
        ->and($mismatch->status)->toBe(RecipeMismatch::STATUS_CREATED)
        ->and($mismatch->resolved_inventory_item_id)->toBe($hasil['item']->id);
});

it('tidak menyentuh baris resep ketika sebuah nama diabaikan', function () {
    $resep = resepMismatch('Sop Ayam');
    barisYatim($resep, 'secukupnya');

    $mismatch = mismatchGaram('Secukupnya', 'secukupnya');
    $this->resolver->ignore($mismatch, 'Keterangan takaran, bukan bahan.');

    expect($mismatch->fresh()->status)->toBe(RecipeMismatch::STATUS_IGNORED)
        // Yang diabaikan keputusannya, bukan datanya: barisnya tetap apa adanya.
        ->and(RecipeItem::query()->unmatched()->count())->toBe(1);
});

it('melepas kembali tautan ketika keputusan dibuka ulang', function () {
    $resep = resepMismatch('Sop Ayam');
    barisYatim($resep, 'garam');

    $garam = bahanMismatch('Garam Dapur');
    $mismatch = mismatchGaram();

    $this->resolver->linkToItem($mismatch, $garam);
    expect(RecipeItem::query()->unmatched()->count())->toBe(0);

    $this->resolver->reopen($mismatch->fresh());

    expect(RecipeItem::query()->unmatched()->count())->toBe(1)
        ->and($mismatch->fresh()->status)->toBe(RecipeMismatch::STATUS_OPEN)
        ->and($mismatch->fresh()->resolved_inventory_item_id)->toBeNull();
});

it('memasang kembali keputusan setelah baris resep disusun ulang', function () {
    $resep = resepMismatch('Sop Ayam');
    barisYatim($resep, 'garam');

    $garam = bahanMismatch('Garam Dapur');
    $mismatch = mismatchGaram();

    $this->resolver->linkToItem($mismatch, $garam);

    // Meniru perpindahan data: seluruh baris resep dihapus lalu disusun ulang
    // dari sumber, sehingga tautannya hilang.
    RecipeItem::query()->delete();
    barisYatim($resep, 'garam');

    expect(RecipeItem::query()->unmatched()->count())->toBe(1);

    $hasil = $this->resolver->reapplyAll();

    expect($hasil['keputusan'])->toBe(1)
        ->and($hasil['baris'])->toBe(1)
        ->and(RecipeItem::query()->unmatched()->count())->toBe(0)
        ->and(RecipeItem::query()->first()->inventory_item_id)->toBe($garam->id);
});

it('tidak memasang kembali keputusan yang diabaikan', function () {
    $resep = resepMismatch('Sop Ayam');
    barisYatim($resep, 'secukupnya');

    $this->resolver->ignore(mismatchGaram('Secukupnya', 'secukupnya'));

    // Kontrol positif: keputusan yang menautkan bahan tetap ikut dipasang.
    barisYatim($resep, 'garam');
    $this->resolver->linkToItem(mismatchGaram(), bahanMismatch('Garam Dapur'));

    $hasil = $this->resolver->reapplyAll();

    expect($hasil['keputusan'])->toBe(1)
        ->and(RecipeItem::query()->unmatched()->count())->toBe(1);
});

it('membuat hpp resep terisi setelah bahannya ditautkan', function () {
    $resep = resepMismatch('Sop Ayam');
    barisYatim($resep, 'garam', 100, 'gr');

    $sebelum = app(RecipeCostService::class)->cost($resep->fresh());

    expect($sebelum['total_raw'])->toBe(0.0)
        ->and($sebelum['has_unmatched'])->toBeTrue();

    $this->resolver->linkToItem(mismatchGaram(), bahanMismatch('Garam Dapur', 'kg', 8000));

    $sesudah = app(RecipeCostService::class)->cost($resep->fresh());

    // 100 gr x Rp 8.000/kg = Rp 800.
    expect($sesudah['total_raw'])->toBe(800.0)
        ->and($sesudah['has_unmatched'])->toBeFalse()
        ->and($sesudah['issues'])->toBe([]);
});
