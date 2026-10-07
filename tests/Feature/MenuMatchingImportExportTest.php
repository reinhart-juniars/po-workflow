<?php

use App\Exports\MenuMatchingExport;
use App\Exports\RecipesExport;
use App\Filament\Menu\Pages\MenuMatching;
use App\Imports\MenuMatchingImport;
use App\Imports\RecipesImport;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Lembar kerja Excel Pencocokan Menu: ditarik, digarap staf pemilik, diunggah
 * kembali. Yang dijaga: berkas boleh diunggah separuh jalan (baris kosong =
 * tidak berubah), import tidak pernah melepas tautan, dan satu kesalahan
 * membatalkan seluruh berkas.
 */
function resepCocok(string $name, bool $active = true): Recipe
{
    return Recipe::query()->create([
        'name' => $name, 'jenis' => Recipe::JENIS_UTAMA, 'yield_qty' => 1, 'yield_unit' => 'porsi',
        'ohc_pct' => 0.4, 'profit_pct' => 0.25, 'is_active' => $active,
    ]);
}

function produkCocok(string $name, array $extra = []): Product
{
    return Product::query()->create(array_merge(['name' => $name, 'unit' => 'porsi', 'base_price' => 10000, 'active' => true], $extra))->fresh();
}

/** @return Illuminate\Support\Collection<int, Illuminate\Support\Collection<string, mixed>> */
function berkasCocok(array $rows)
{
    return collect($rows)->map(fn (array $row) => collect($row));
}

it('mengekspor satu baris per produk dengan usulan dan sheet rujukan resep', function () {
    $solaria = resepCocok('Nasi Goreng Ala Solaria');
    resepCocok('Sambal Matah', false);
    $tertaut = produkCocok('NASI GORENG SOLARIA 10K', ['recipe_id' => $solaria->id]);
    $belum = produkCocok('NASI GORENG SOLARIA 12K');
    $tanpa = produkCocok('EXTRA 1K', ['needs_recipe' => false]);

    $export = new MenuMatchingExport;
    $rows = $export->rows()->keyBy('produk_id');
    $sheets = $export->sheets();

    expect($rows)->toHaveCount(3)
        ->and(array_keys($rows->first()))->toBe(MenuMatchingExport::HEADINGS)
        ->and($rows[$tertaut->id])->toMatchArray(['status_sekarang' => 'Sudah tertaut', 'resep_id' => $solaria->id, 'nama_resep' => 'Nasi Goreng Ala Solaria', 'usulan_resep_id' => null])
        ->and($rows[$belum->id])->toMatchArray(['status_sekarang' => 'Belum dicocokkan', 'resep_id' => null, 'usulan_resep_id' => $solaria->id, 'terima_usulan' => null, 'tanpa_resep' => null])
        ->and($rows[$tanpa->id]['status_sekarang'])->toBe('Tanpa resep')
        ->and($sheets)->toHaveCount(2)
        ->and($sheets[1]->title())->toBe('Daftar Resep')
        ->and($export->recipes()->pluck('nama_resep')->all())->toBe(['Nasi Goreng Ala Solaria', 'Sambal Matah']);
});

it('menerapkan keputusan per baris: terima usulan, resep tertentu, tanpa resep, dan melewati yang kosong', function () {
    $solaria = resepCocok('Nasi Goreng Ala Solaria');
    $capjay = resepCocok('Nasi Capjay');
    $a = produkCocok('NASI GORENG SOLARIA 10K');
    $b = produkCocok('NASI CAPJAY 12K');
    $c = produkCocok('NASI CAPJAY 15K');
    $d = produkCocok('EXTRA 1K');
    $e = produkCocok('NASI KATSU 12K');
    $f = produkCocok('NASI CAPJAY 10K', ['recipe_id' => $capjay->id]);

    $import = new MenuMatchingImport;
    $import->collection(berkasCocok([
        ['produk_id' => $a->id, 'usulan_resep_id' => $solaria->id, 'terima_usulan' => 'ya'],
        ['produk_id' => $b->id, 'resep_id' => $capjay->id],
        ['produk_id' => $c->id, 'nama_resep' => 'nasi capjay'],       // nama, tanpa peduli huruf besar
        ['produk_id' => $d->id, 'tanpa_resep' => 'YA'],
        ['produk_id' => $e->id],                                       // kosong: tidak diubah
        ['produk_id' => $f->id, 'resep_id' => $capjay->id, 'nama_resep' => 'Nasi Capjay'], // sama dengan sekarang
    ]));

    expect($import->hasErrors())->toBeFalse()
        ->and($import->linked())->toBe(3)
        ->and($import->withoutRecipe())->toBe(1)
        ->and($import->skipped())->toBe(2)
        ->and($a->fresh()->recipe_id)->toBe($solaria->id)
        ->and($b->fresh()->recipe_id)->toBe($capjay->id)
        ->and($c->fresh()->recipe_id)->toBe($capjay->id)
        ->and($d->fresh())->needs_recipe->toBeFalse()->recipe_id->toBeNull()
        ->and($e->fresh())->recipe_id->toBeNull()->needs_recipe->toBeTrue()
        ->and($f->fresh()->recipe_id)->toBe($capjay->id);

    // Mengosongkan resep_id pada produk yang sudah tertaut TIDAK melepasnya.
    $ulang = new MenuMatchingImport;
    $ulang->collection(berkasCocok([['produk_id' => $f->id, 'resep_id' => null, 'nama_resep' => null]]));
    expect($ulang->hasErrors())->toBeFalse()->and($f->fresh()->recipe_id)->toBe($capjay->id);
});

it('membatalkan seluruh berkas bila ada baris yang salah', function () {
    $capjay = resepCocok('Nasi Capjay');
    $lama = resepCocok('Resep Lama', false);
    $a = produkCocok('NASI CAPJAY 12K');
    $b = produkCocok('NASI CAPJAY 15K');

    $kasus = [
        'produk tidak ada' => [['produk_id' => $a->id, 'resep_id' => $capjay->id], ['produk_id' => 999999, 'resep_id' => $capjay->id]],
        'resep tidak ada' => [['produk_id' => $a->id, 'resep_id' => $capjay->id], ['produk_id' => $b->id, 'resep_id' => 999999]],
        'nama resep tidak dikenal' => [['produk_id' => $a->id, 'resep_id' => $capjay->id], ['produk_id' => $b->id, 'nama_resep' => 'Nasi Ajaib']],
        'resep nonaktif' => [['produk_id' => $a->id, 'resep_id' => $capjay->id], ['produk_id' => $b->id, 'resep_id' => $lama->id]],
        'dua keputusan' => [['produk_id' => $a->id, 'resep_id' => $capjay->id], ['produk_id' => $b->id, 'resep_id' => $capjay->id, 'tanpa_resep' => 'ya']],
        'terima usulan tanpa usulan' => [['produk_id' => $a->id, 'resep_id' => $capjay->id], ['produk_id' => $b->id, 'terima_usulan' => 'ya']],
        'produk ganda' => [['produk_id' => $a->id, 'resep_id' => $capjay->id], ['produk_id' => $a->id, 'tanpa_resep' => 'ya']],
    ];

    foreach ($kasus as $nama => $rows) {
        $import = new MenuMatchingImport;
        $import->collection(berkasCocok($rows));

        expect($import->hasErrors())->toBeTrue($nama)
            ->and($import->errors()[0])->toStartWith('Baris 3', $nama)
            // Baris pertama yang benar pun tidak tersimpan.
            ->and($a->fresh()->recipe_id)->toBeNull($nama);
    }

    // Kontrol positif: tanpa baris yang salah, baris pertama tersimpan.
    $import = new MenuMatchingImport;
    $import->collection(berkasCocok([['produk_id' => $a->id, 'resep_id' => $capjay->id]]));
    expect($import->hasErrors())->toBeFalse()->and($a->fresh()->recipe_id)->toBe($capjay->id);
});

it('menyediakan tombol export dan import di halaman, import hanya untuk pemegang izin kelola', function () {
    Role::findOrCreate('accounting', 'web');
    Role::findOrCreate('menu', 'web');

    $accounting = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $accounting->assignRole('accounting');
    $this->actingAs($accounting);

    Livewire::test(MenuMatching::class)
        ->assertActionVisible('export')
        ->assertActionHidden('import');

    $admin = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $admin->assignRole('menu');
    $this->actingAs($admin);

    produkCocok('NASI CAPJAY 12K');

    Livewire::test(MenuMatching::class)
        ->assertActionVisible('import')
        ->callAction('export')
        ->assertFileDownloaded();
});

it('membawa daftar produk sebuah resep (dipisah koma) pada export resep dan menambah tautan saat import', function () {
    $resep = resepCocok('Nasi Capjay');
    $p10 = produkCocok('NASI CAPJAY 10K', ['recipe_id' => $resep->id]);
    $p12 = produkCocok('NASI CAPJAY 12K', ['recipe_id' => $resep->id]);
    $p15 = produkCocok('NASI CAPJAY 15K');

    $export = new RecipesExport;
    $rows = $export->collection()->map(fn (array $row) => array_combine($export->headings(), $row));

    expect($rows[0]['produk_id'])->toBe($p10->id.','.$p12->id);

    // Menambah 15K; kolom kosong pada berkas lama tidak melepas apa pun.
    $import = new RecipesImport;
    $import->collection(berkasCocok([
        ['resep_id' => $resep->id, 'nama_resep' => 'Nasi Capjay', 'produk_id' => $p10->id.', '.$p15->id, 'hasil_qty' => 10, 'hasil_satuan' => 'porsi', 'ohc_persen' => 40, 'profit_persen' => 25],
    ]));

    expect($import->hasErrors())->toBeFalse()
        ->and($resep->products()->pluck('id')->sort()->values()->all())->toBe([$p10->id, $p12->id, $p15->id]);

    $kosong = new RecipesImport;
    $kosong->collection(berkasCocok([
        ['resep_id' => $resep->id, 'nama_resep' => 'Nasi Capjay', 'produk_id' => null, 'hasil_qty' => 10, 'hasil_satuan' => 'porsi', 'ohc_persen' => 40, 'profit_persen' => 25],
    ]));
    expect($kosong->hasErrors())->toBeFalse()->and($resep->products()->count())->toBe(3);

    // Id produk yang tidak ada membatalkan berkas.
    $gagal = new RecipesImport;
    $gagal->collection(berkasCocok([
        ['resep_id' => $resep->id, 'nama_resep' => 'Nasi Capjay', 'produk_id' => '999999', 'hasil_qty' => 10, 'hasil_satuan' => 'porsi', 'ohc_persen' => 40, 'profit_persen' => 25],
    ]));
    expect($gagal->hasErrors())->toBeTrue()->and($gagal->errors()[0])->toContain('999999');
});
