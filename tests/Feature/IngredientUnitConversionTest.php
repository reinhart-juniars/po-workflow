<?php

use App\Models\InventoryItem;
use App\Models\InventoryUnitConversion;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Services\IngredientUnitConverter;
use App\Services\MissingUnitConversionScanner;
use App\Services\RecipeCostService;
use App\Support\Units\PackSizeHint;

/**
 * Aturan konversi satuan per bahan.
 *
 * Ini penghambat terbesar perhitungan HPP: dari 6.466 baris resep yang sudah
 * tertaut ke bahan, 1.496 gagal karena resep menulis takaran (gr, ml) sementara
 * bahannya dihargai per kemasan (pcs, pack, dus). Yang dijaga di sini bukan
 * sekadar "konversinya jalan", melainkan bahwa aturan sebuah bahan tidak pernah
 * bocor ke bahan lain -- 1 pcs ayam bukan 1 pcs telur, dan tertukarnya dua
 * angka itu menghasilkan HPP yang salah tanpa satu pun peringatan.
 */
function bahanKonversi(string $nama, string $unit, ?float $unitPrice = null): InventoryItem
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
        'unit_price' => $unitPrice,
        'is_active' => true,
    ]);
}

function aturan(?InventoryItem $item, string $from, float $factor, string $to): InventoryUnitConversion
{
    return InventoryUnitConversion::query()->create([
        'inventory_item_id' => $item?->id,
        'from_unit' => $from,
        'to_unit' => $to,
        'factor' => $factor,
    ]);
}

function resepKonversi(string $nama, array $extra = []): Recipe
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

function barisKonversi(Recipe $recipe, InventoryItem $item, float $qty, string $unit): RecipeItem
{
    return RecipeItem::query()->create([
        'recipe_id' => $recipe->id,
        'inventory_item_id' => $item->id,
        'raw_name' => mb_strtolower($item->name),
        'qty' => $qty,
        'unit' => $unit,
    ]);
}

it('menjembatani satuan beda besaran lewat aturan milik bahan', function () {
    $ayam = bahanKonversi('Ayam Fillet', 'pcs', 15000);
    aturan($ayam, 'pcs', 250, 'gram');

    $converter = app(IngredientUnitConverter::class);

    // 500 gr = 2 pcs.
    expect($converter->convert(500, 'gr', 'pcs', $ayam->id))->toBe(2.0)
        // Aturan berlaku dua arah: 3 pcs = 750 gr.
        ->and($converter->convert(3, 'pcs', 'gr', $ayam->id))->toBe(750.0)
        // Kontrol positif: konversi sebesaran tetap jalan tanpa aturan apa pun.
        ->and($converter->convert(2, 'kg', 'gram'))->toBe(2000.0);
});

it('tidak membocorkan aturan sebuah bahan ke bahan lain', function () {
    $ayam = bahanKonversi('Ayam Fillet', 'pcs', 15000);
    $telur = bahanKonversi('Telur', 'pcs', 2500);

    aturan($ayam, 'pcs', 250, 'gram');

    $converter = app(IngredientUnitConverter::class);

    // Kontrol positif: bahan yang punya aturan berhasil dikonversi.
    expect($converter->convert(500, 'gr', 'pcs', $ayam->id))->toBe(2.0)
        // Bahan tanpa aturan tetap ditolak, bukan diam-diam memakai 250 gr.
        ->and($converter->convert(500, 'gr', 'pcs', $telur->id))->toBeNull()
        // Begitu pula bila tidak ada bahan yang disebut sama sekali.
        ->and($converter->convert(500, 'gr', 'pcs'))->toBeNull();
});

it('menyambung aturan dengan konversi registri di kedua ujungnya', function () {
    $ayam = bahanKonversi('Ayam Fillet', 'kg', 60000);
    // Aturannya ditulis dalam gram, permintaannya datang dalam kg.
    aturan($ayam, 'pcs', 250, 'gram');

    $converter = app(IngredientUnitConverter::class);

    // 2 pcs = 500 gr = 0,5 kg.
    expect($converter->convert(2, 'pcs', 'kg', $ayam->id))->toBe(0.5)
        // Dan sebaliknya: 1 kg = 1000 gr = 4 pcs.
        ->and($converter->convert(1, 'kg', 'pcs', $ayam->id))->toBe(4.0);
});

it('memakai aturan umum sebagai cadangan, dan aturan bahan yang menang', function () {
    $besar = bahanKonversi('Telur Ayam Besar', 'kg', 30000);
    $biasa = bahanKonversi('Telur Ayam', 'kg', 28000);

    aturan(null, 'butir', 60, 'gram');       // aturan umum
    aturan($besar, 'butir', 70, 'gram');     // aturan khusus bahan ini

    $converter = app(IngredientUnitConverter::class);

    // Bahan tanpa aturan sendiri jatuh ke aturan umum: 10 butir = 600 gr = 0,6 kg.
    expect($converter->convert(10, 'butir', 'kg', $biasa->id))->toBe(0.6)
        // Bahan yang punya aturan sendiri tidak ikut aturan umum.
        ->and($converter->convert(10, 'butir', 'kg', $besar->id))->toBe(0.7);
});

it('mengabaikan aturan berfaktor nol alih-alih membagi nol', function () {
    $ayam = bahanKonversi('Ayam Fillet', 'pcs', 15000);
    aturan($ayam, 'pcs', 0, 'gram');

    // Faktor nol tidak punya arti, dan dipakai dari arah sebaliknya akan
    // membagi nol. Aturannya dilewati, bukan dipaksakan.
    expect(app(IngredientUnitConverter::class)->convert(500, 'gr', 'pcs', $ayam->id))->toBeNull();

    // Kontrol positif: aturan berfaktor wajar pada bahan yang sama tetap dipakai.
    aturan($ayam, 'gram', 0.004, 'pcs');

    expect(app(IngredientUnitConverter::class)->convert(500, 'gr', 'pcs', $ayam->id))->toBe(2.0);
});

it('membuat hpp resep bisa dihitung setelah aturannya diisi', function () {
    $ayam = bahanKonversi('Ayam Fillet', 'pcs', 15000);

    $resep = resepKonversi('Ayam Bakar', ['yield_qty' => 4, 'yield_unit' => 'porsi']);
    barisKonversi($resep, $ayam, 1000, 'gr');

    $sebelum = app(RecipeCostService::class)->cost($resep->fresh());

    // Tanpa aturan, barisnya tidak dihitung -- dan itu harus terlihat, bukan
    // menghasilkan HPP nol yang tampak wajar.
    expect($sebelum['total_raw'])->toBe(0.0)
        ->and($sebelum['issues'])->not->toBe([]);

    aturan($ayam, 'pcs', 250, 'gram');

    // Instance baru: aturan dimuat sekali per instance penerjemah satuan.
    $sesudah = app(RecipeCostService::class)->cost($resep->fresh());

    // 1000 gr = 4 pcs x Rp 15.000 = Rp 60.000 untuk 4 porsi.
    expect($sesudah['total_raw'])->toBe(60000.0)
        ->and($sesudah['hpp_per_yield'])->toBe(15000.0)
        ->and($sesudah['issues'])->toBe([]);
});

it('mendaftar pasangan satuan yang menahan resep dan melepasnya setelah diatur', function () {
    $ayam = bahanKonversi('Ayam Fillet', 'pcs', 15000);
    $tepung = bahanKonversi('Tepung Terigu', 'kg', 12000);

    $satu = resepKonversi('Ayam Bakar');
    $dua = resepKonversi('Ayam Goreng');

    barisKonversi($satu, $ayam, 1000, 'gr');
    barisKonversi($dua, $ayam, 500, 'gr');
    // Baris ini sudah bisa dikonversi registri, jadi tidak boleh ikut terdaftar.
    barisKonversi($satu, $tepung, 250, 'gr');

    $rows = app(MissingUnitConversionScanner::class)->scan();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['item_name'])->toBe('Ayam Fillet')
        // Ejaan baku: model menyimpan "gr" sebagai "gram".
        ->and($rows[0]['from_unit'])->toBe('gram')
        ->and($rows[0]['to_unit'])->toBe('pcs')
        ->and($rows[0]['line_count'])->toBe(2)
        ->and($rows[0]['recipe_count'])->toBe(2);

    aturan($ayam, 'pcs', 250, 'gram');

    expect(app(MissingUnitConversionScanner::class)->scan())->toHaveCount(0);
});

it('mengusulkan faktor dari isi kemasan yang tertulis di nama bahan', function () {
    // Nama-nama ini apa adanya dari data klien.
    expect(PackSizeHint::suggest('telur @1kg isi 16butir', 'kg', 'butir'))
        ->toBe(['from_unit' => 'kg', 'to_unit' => 'butir', 'factor' => 16.0])
        ->and(PackSizeHint::suggest('mie burung dara 36pack', 'dus', 'pack'))
        ->toBe(['from_unit' => 'dus', 'to_unit' => 'pack', 'factor' => 36.0])
        ->and(PackSizeHint::suggest('sendok makan trp 100pcs', 'pack', 'pc'))
        ->toBe(['from_unit' => 'pack', 'to_unit' => 'pcs', 'factor' => 100.0])
        // "@500gr" menjembatani satuan resep "gr" lewat registri satuan.
        ->and(PackSizeHint::suggest('tepung beras rose brand @500gr', 'pack', 'gr'))
        ->toBe(['from_unit' => 'pack', 'to_unit' => 'gram', 'factor' => 500.0]);
});

it('diam ketika nama bahan menyebut kemasan bertingkat', function () {
    // Satu dus berisi 12 pack berisi 200 ml. Angka mana pun yang diambil
    // sendirian akan salah, dan angka salah yang tampil sebagai usulan lebih
    // berbahaya daripada kolom kosong -- orang cenderung menyimpannya begitu saja.
    expect(PackSizeHint::suggest('santan kara 200ml @12pack 200ml', 'dus', 'ml'))->toBeNull()
        // Dua satuan isi yang berbeda juga bertingkat: 500 ml itu ukuran wadah,
        // 500 pcs itu isi dusnya.
        ->and(PackSizeHint::suggest('thinwall kotak 500ml @500pcs', 'dus', 'pc'))->toBeNull()
        // Satu satuan dengan dua angka berbeda sama ambigunya.
        ->and(PackSizeHint::suggest('minyak 1lt isi 5lt', 'jirigen', 'ml'))->toBeNull();
});

it('tidak mengusulkan apa pun ketika angkanya tidak menyelesaikan pasangannya', function () {
    // Tidak ada angka sama sekali di namanya.
    expect(PackSizeHint::suggest('Sereh', 'pcs', 'gr'))->toBeNull()
        // Ada angka, tetapi satuannya tidak sepadan dengan satuan resep: 500 ml
        // tidak menjelaskan berapa pcs isi satu dus.
        ->and(PackSizeHint::suggest('thinwall kotak 500ml', 'dus', 'pc'))->toBeNull()
        // Angka yang satuannya sama dengan satuan harga adalah ukuran kemasan
        // itu sendiri, bukan isinya.
        ->and(PackSizeHint::suggest('beras @25kg', 'kg', 'kg'))->toBeNull()
        // Bacaan yang mustahil: satu gram tidak berisi sepuluh ikat. Muncul dari
        // bahan yang satuan harganya keliru, dan usulan seperti ini hanya akan
        // menularkan kekeliruan itu ke HPP.
        ->and(PackSizeHint::suggest('sawi @10ikat', 'gram', 'ikat'))->toBeNull()
        // Kontrol positif: bacaan sejenis yang masuk akal tetap diusulkan --
        // satu kilogram berisi 16 butir berarti ±62 gram per butir.
        ->and(PackSizeHint::suggest('telur @1kg isi 16butir', 'kg', 'butir'))
        ->toBe(['from_unit' => 'kg', 'to_unit' => 'butir', 'factor' => 16.0]);
});
