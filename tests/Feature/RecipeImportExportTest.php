<?php

use App\Exports\RecipesExport;
use App\Imports\RecipesImport;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeMismatch;

/**
 * Import/export resep.
 *
 * Berkasnya mendatar -- satu baris per bahan -- karena itu bentuk yang bisa
 * dikerjakan orang di Excel. Import mengganti rincian sebuah resep seluruhnya,
 * jadi yang paling perlu dijaga adalah: bahan yang dihapus di Excel benar-benar
 * hilang, dan satu kesalahan membatalkan seluruh berkas alih-alih menyimpan
 * separuh resep.
 */
function bahanImpor(string $nama, string $unit = 'kg', float $harga = 12000): InventoryItem
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

function resepImpor(string $nama, array $extra = []): Recipe
{
    return Recipe::query()->create(array_merge([
        'name' => $nama,
        'jenis' => Recipe::JENIS_UTAMA,
        'yield_qty' => 10,
        'yield_unit' => 'porsi',
        'ohc_pct' => 0.40,
        'profit_pct' => 0.25,
    ], $extra));
}

/** @return Illuminate\Support\Collection<int, Illuminate\Support\Collection<string, mixed>> */
function berkasResep(array $rows)
{
    return collect($rows)->map(fn (array $row) => collect($row));
}

it('mengekspor satu baris per bahan dengan kolom resep yang diulang', function () {
    $tepung = bahanImpor('Tepung Terigu');
    $resep = resepImpor('Gorengan');

    RecipeItem::query()->create([
        'recipe_id' => $resep->id, 'inventory_item_id' => $tepung->id,
        'raw_name' => 'tepung terigu', 'qty' => 250, 'unit' => 'gr', 'sort_order' => 0,
    ]);
    RecipeItem::query()->create([
        'recipe_id' => $resep->id, 'raw_name' => 'garam', 'qty' => 10, 'unit' => 'gr', 'sort_order' => 1,
    ]);

    $export = new RecipesExport;
    $rows = $export->collection()->map(fn (array $row) => array_combine($export->headings(), $row));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['nama_resep'])->toBe('Gorengan')
        ->and($rows[1]['nama_resep'])->toBe('Gorengan')
        // Persentase ditulis sebagai persen, bukan pecahan.
        ->and($rows[0]['ohc_persen'])->toBe(40.0)
        ->and($rows[0]['profit_persen'])->toBe(25.0)
        // Tanpa bahan_id, mengunggah balik hasil export akan melepas seluruh
        // tautan bahan dan mengembalikan resepnya ke daftar bahan belum cocok.
        ->and($rows[0]['bahan_id'])->toBe($tepung->id)
        ->and($rows[1]['bahan_id'])->toBeNull();
});

it('mengikutkan resep yang belum punya rincian bahan', function () {
    resepImpor('Menu Tanpa Rincian', ['snapshot_hpp' => 15000]);

    $export = new RecipesExport;
    $rows = $export->collection()->map(fn (array $row) => array_combine($export->headings(), $row));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['nama_resep'])->toBe('Menu Tanpa Rincian')
        ->and($rows[0]['nama_bahan'])->toBeNull();
});

it('membuat resep baru beserta rinciannya dari berkas', function () {
    $tepung = bahanImpor('Tepung Terigu');

    $import = new RecipesImport;
    $import->collection(berkasResep([
        [
            'resep_id' => null, 'nama_resep' => 'Gorengan', 'jenis' => 'utama',
            'hasil_qty' => 10, 'hasil_satuan' => 'porsi', 'ohc_persen' => 40, 'profit_persen' => 25,
            'nama_bahan' => 'tepung terigu', 'jumlah' => 250, 'satuan' => 'gr', 'bahan_id' => $tepung->id, 'urut' => 0,
        ],
        [
            'resep_id' => null, 'nama_resep' => 'Gorengan', 'jenis' => 'utama',
            'hasil_qty' => 10, 'hasil_satuan' => 'porsi', 'ohc_persen' => 40, 'profit_persen' => 25,
            'nama_bahan' => 'garam', 'jumlah' => 10, 'satuan' => 'gr', 'urut' => 1,
        ],
    ]));

    $resep = Recipe::query()->firstWhere('name', 'Gorengan');

    expect($import->hasErrors())->toBeFalse()
        ->and($import->created())->toBe(1)
        ->and($import->lines())->toBe(2)
        ->and((float) $resep->ohc_pct)->toBe(0.4)
        ->and($resep->items)->toHaveCount(2)
        ->and($resep->items[0]->inventory_item_id)->toBe($tepung->id);
});

it('mengganti rincian resep seluruhnya, bukan menggabungnya', function () {
    $tepung = bahanImpor('Tepung Terigu');
    $resep = resepImpor('Gorengan');

    RecipeItem::query()->create([
        'recipe_id' => $resep->id, 'raw_name' => 'bahan lama', 'qty' => 1, 'unit' => 'gr',
    ]);

    $import = new RecipesImport;
    $import->collection(berkasResep([
        [
            'resep_id' => $resep->id, 'nama_resep' => 'Gorengan', 'jenis' => 'utama',
            'hasil_qty' => 10, 'hasil_satuan' => 'porsi', 'ohc_persen' => 40, 'profit_persen' => 25,
            'nama_bahan' => 'tepung terigu', 'jumlah' => 250, 'satuan' => 'gr', 'bahan_id' => $tepung->id,
        ],
    ]));

    // Bahan yang dihapus di Excel harus ikut hilang; kalau tertinggal, ia terus
    // menambah HPP tanpa ada yang menyadarinya.
    expect($import->updated())->toBe(1)
        ->and($resep->fresh()->items)->toHaveCount(1)
        ->and(RecipeItem::query()->where('raw_name', 'bahan lama')->exists())->toBeFalse();
});

it('membatalkan seluruh berkas ketika ada satu baris yang salah', function () {
    $tepung = bahanImpor('Tepung Terigu');

    $import = new RecipesImport;
    $import->collection(berkasResep([
        [
            'resep_id' => null, 'nama_resep' => 'Gorengan', 'jenis' => 'utama',
            'hasil_qty' => 10, 'hasil_satuan' => 'porsi',
            'nama_bahan' => 'tepung terigu', 'jumlah' => 250, 'satuan' => 'gr', 'bahan_id' => $tepung->id,
        ],
        [
            'resep_id' => null, 'nama_resep' => 'Nasi Goreng', 'jenis' => 'utama',
            'hasil_qty' => 1, 'hasil_satuan' => 'porsi',
            'nama_bahan' => 'bumbu', 'jumlah' => 1, 'satuan' => 'pcs', 'bahan_id' => 999999,
        ],
    ]));

    // Resep yang tersimpan separuh lebih sulit dibereskan daripada yang tidak
    // tersimpan sama sekali.
    expect($import->hasErrors())->toBeTrue()
        ->and($import->errors()[0])->toContain('999999')
        ->and(Recipe::query()->count())->toBe(0);
});

it('menolak baris yang menunjuk bahan sekaligus sub-resep', function () {
    $tepung = bahanImpor('Tepung Terigu');
    $sub = resepImpor('Sambal Matah', ['jenis' => Recipe::JENIS_SUB]);

    $import = new RecipesImport;
    $import->collection(berkasResep([
        [
            'nama_resep' => 'Nasi Sambal', 'jenis' => 'utama', 'hasil_qty' => 1, 'hasil_satuan' => 'porsi',
            'nama_bahan' => 'campuran', 'jumlah' => 1, 'satuan' => 'porsi',
            'bahan_id' => $tepung->id, 'sub_resep_id' => $sub->id,
        ],
    ]));

    expect($import->hasErrors())->toBeTrue()
        ->and($import->errors()[0])->toContain('dihitung dua kali')
        ->and(Recipe::query()->where('name', 'Nasi Sambal')->exists())->toBeFalse();
});

it('menolak resep tanpa jumlah hasil', function () {
    $import = new RecipesImport;
    $import->collection(berkasResep([
        ['nama_resep' => 'Gorengan', 'jenis' => 'utama', 'hasil_qty' => 0, 'nama_bahan' => 'garam', 'jumlah' => 1],
    ]));

    // Resep tanpa hasil membuat pembagian HPP menjadi tak hingga.
    expect($import->hasErrors())->toBeTrue()
        ->and($import->errors()[0])->toContain('jumlah hasil');
});

it('menyegarkan daftar bahan belum cocok setelah import', function () {
    $import = new RecipesImport;
    $import->collection(berkasResep([
        [
            'nama_resep' => 'Gorengan', 'jenis' => 'utama', 'hasil_qty' => 10, 'hasil_satuan' => 'porsi',
            'nama_bahan' => 'garam', 'jumlah' => 10, 'satuan' => 'gr',
        ],
    ]));

    // Import adalah jalan masuk kedua ke data resep; kalau daftar kerjanya tidak
    // ikut disegarkan, bahan baru yang belum punya padanan tidak akan pernah
    // muncul untuk direkonsiliasi.
    expect($import->hasErrors())->toBeFalse()
        ->and(RecipeMismatch::query()->firstWhere('raw_name_norm', 'garam'))->not->toBeNull()
        ->and(RecipeMismatch::query()->firstWhere('raw_name_norm', 'garam')->occurrence_count)->toBe(1);
});

it('memasang kembali tautan hasil rekonsiliasi pada baris yang diimpor', function () {
    $garam = bahanImpor('Garam Dapur', 'kg', 8000);

    RecipeMismatch::query()->create([
        'raw_name' => 'garam', 'raw_name_norm' => 'garam',
        'occurrence_count' => 1, 'recipe_count' => 1,
        'status' => RecipeMismatch::STATUS_LINKED,
        'resolved_inventory_item_id' => $garam->id,
    ]);

    $import = new RecipesImport;
    $import->collection(berkasResep([
        [
            'nama_resep' => 'Gorengan', 'jenis' => 'utama', 'hasil_qty' => 10, 'hasil_satuan' => 'porsi',
            // Berkas tidak membawa bahan_id, seperti berkas yang disusun tangan.
            'nama_bahan' => 'garam', 'jumlah' => 10, 'satuan' => 'gr',
        ],
    ]));

    // Keputusan yang sudah pernah diambil tidak boleh perlu diulang hanya
    // karena resepnya masuk lewat Excel.
    expect(RecipeItem::query()->first()->inventory_item_id)->toBe($garam->id)
        ->and(RecipeItem::query()->unmatched()->count())->toBe(0);
});
