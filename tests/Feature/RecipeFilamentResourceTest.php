<?php

use App\Filament\Resources\RecipeMismatchResource;
use App\Filament\Resources\RecipeResource;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeMismatch;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Modul Resep & Menu dan modul Bahan Belum Cocok.
 *
 * Keduanya adalah tempat manusia menyentuh angka yang menentukan HPP, jadi yang
 * dijaga adalah dampaknya ke data: satu keputusan rekonsiliasi harus benar-benar
 * mengubah baris resep, dan baris resep yang tidak masuk akal harus ditolak
 * sebelum tersimpan.
 */
beforeEach(function () {
    Role::findOrCreate('admin', 'web');

    $this->user = User::factory()->create([
        'is_active' => true,
        'force_password_change' => false,
    ]);

    $this->user->assignRole('admin');
    $this->actingAs($this->user);

    $this->bucket = InventoryItem::query()->create([
        'name' => 'Bahan Baku',
        'unit' => 'All',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'is_active' => true,
    ]);

    $this->tepung = InventoryItem::query()->create([
        'parent_id' => $this->bucket->id,
        'name' => 'Tepung Terigu',
        'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'unit_price' => 12000,
        'is_active' => true,
    ]);
});

it('menyimpan resep beserta rincian bahannya', function () {
    Livewire::test(RecipeResource\Pages\CreateRecipe::class)
        ->fillForm([
            'name' => 'Gorengan',
            'jenis' => Recipe::JENIS_UTAMA,
            'yield_qty' => 10,
            'yield_unit' => 'porsi',
            'ohc_pct' => 40,
            'profit_pct' => 25,
            'items' => [
                [
                    'raw_name' => 'tepung terigu',
                    'inventory_item_id' => $this->tepung->id,
                    'qty' => 250,
                    'unit' => 'gram',
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $recipe = Recipe::query()->firstWhere('name', 'Gorengan');

    // Persentase diisi sebagai persen tetapi disimpan sebagai pecahan; kalau
    // tertukar, OHC menjadi 40x HPP alih-alih 40%.
    expect($recipe)->not->toBeNull()
        ->and((float) $recipe->ohc_pct)->toBe(0.4)
        ->and((float) $recipe->profit_pct)->toBe(0.25)
        ->and($recipe->name_norm)->toBe('gorengan')
        ->and($recipe->items)->toHaveCount(1)
        ->and($recipe->items->first()->inventory_item_id)->toBe($this->tepung->id);
});

it('menolak baris resep yang menunjuk bahan sekaligus sub-menu', function () {
    $sub = Recipe::query()->create([
        'name' => 'Sambal Matah',
        'jenis' => Recipe::JENIS_SUB,
        'yield_qty' => 10,
        'yield_unit' => 'porsi',
        'ohc_pct' => 0.40,
        'profit_pct' => 0.25,
    ]);

    Livewire::test(RecipeResource\Pages\CreateRecipe::class)
        ->fillForm([
            'name' => 'Nasi Sambal',
            'jenis' => Recipe::JENIS_UTAMA,
            'yield_qty' => 1,
            'yield_unit' => 'porsi',
            'ohc_pct' => 40,
            'profit_pct' => 25,
            'items' => [
                [
                    'raw_name' => 'campuran',
                    'inventory_item_id' => $this->tepung->id,
                    'ref_recipe_id' => $sub->id,
                    'qty' => 1,
                    'unit' => 'porsi',
                ],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['items.0.ref_recipe_id']);

    // Biayanya tidak bisa dihitung dua kali, jadi resepnya tidak boleh tersimpan.
    expect(Recipe::query()->where('name', 'Nasi Sambal')->exists())->toBeFalse();
});

it('menampilkan rincian hpp beserta hal yang menahannya', function () {
    $recipe = Recipe::query()->create([
        'name' => 'Gorengan',
        'jenis' => Recipe::JENIS_UTAMA,
        'yield_qty' => 10,
        'yield_unit' => 'porsi',
        'ohc_pct' => 0.40,
        'profit_pct' => 0.25,
    ]);

    RecipeItem::query()->create([
        'recipe_id' => $recipe->id,
        'inventory_item_id' => $this->tepung->id,
        'raw_name' => 'tepung terigu',
        'qty' => 250,
        'unit' => 'gr',
    ]);

    RecipeItem::query()->create([
        'recipe_id' => $recipe->id,
        'raw_name' => 'garam',
        'qty' => 10,
        'unit' => 'gr',
    ]);

    // 250 gr x Rp 12.000/kg = Rp 3.000 untuk 10 porsi = Rp 300 per porsi.
    Livewire::test(RecipeResource\Pages\RecipeCostBreakdown::class, ['record' => $recipe->id])
        ->assertSee('Rp 300,00')
        // Baris yang tidak terhitung harus terlihat, bukan diam-diam nol.
        ->assertSee('belum ditautkan')
        ->assertSee('1 baris menahan perhitungan')
        // Ringkasan memakai strip KPI satu baris (CSS Filament tidak memuat
        // md:grid-cols-* aplikasi, jadi grid Tailwind jatuh ke satu kolom).
        ->assertSeeHtml('class="sh-kpi" style="--cols: 4"')
        ->assertDontSeeHtml('md:grid-cols');
});

it('menghitung kebutuhan bahan untuk jumlah produksi yang diminta', function () {
    $recipe = Recipe::query()->create([
        'name' => 'Gorengan',
        'jenis' => Recipe::JENIS_UTAMA,
        'yield_qty' => 10,
        'yield_unit' => 'porsi',
        'ohc_pct' => 0.40,
        'profit_pct' => 0.25,
    ]);

    RecipeItem::query()->create([
        'recipe_id' => $recipe->id,
        'inventory_item_id' => $this->tepung->id,
        'raw_name' => 'tepung terigu',
        'qty' => 250,
        'unit' => 'gr',
    ]);

    // 30 porsi = 3x resep = 750 gr = 0,75 kg tepung.
    Livewire::test(RecipeResource\Pages\RecipeCostBreakdown::class, ['record' => $recipe->id])
        ->fillForm(['jumlah_produksi' => 30])
        ->assertSee('0,75 kg');
});

it('menyediakan export dan import resep pada daftar', function () {
    Recipe::query()->create([
        'name' => 'Gorengan', 'jenis' => Recipe::JENIS_UTAMA,
        'yield_qty' => 10, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25,
    ]);

    // Tombolnya terpasang lewat nama aksi; salah ketik nama kelas Export atau
    // Import baru ketahuan saat diklik, bukan saat halamannya dibuka.
    Livewire::test(RecipeResource\Pages\ListRecipes::class)
        ->assertActionExists('export')
        ->assertActionExists('import')
        ->callAction('export')
        ->assertHasNoActionErrors();
});

it('menautkan seluruh baris sebuah nama lewat daftar bahan belum cocok', function () {
    $satu = Recipe::query()->create([
        'name' => 'Sop Ayam', 'jenis' => Recipe::JENIS_UTAMA,
        'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25,
    ]);
    $dua = Recipe::query()->create([
        'name' => 'Nasi Goreng', 'jenis' => Recipe::JENIS_UTAMA,
        'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25,
    ]);

    foreach ([$satu, $dua] as $recipe) {
        RecipeItem::query()->create([
            'recipe_id' => $recipe->id, 'raw_name' => 'garam', 'qty' => 5, 'unit' => 'gr',
        ]);
    }

    $mismatch = RecipeMismatch::query()->create([
        'raw_name' => 'garam',
        'raw_name_norm' => 'garam',
        'occurrence_count' => 2,
        'recipe_count' => 2,
        'sample_unit' => 'gr',
        'status' => RecipeMismatch::STATUS_OPEN,
    ]);

    $garam = InventoryItem::query()->create([
        'parent_id' => $this->bucket->id,
        'name' => 'Garam Dapur',
        'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'unit_price' => 8000,
        'is_active' => true,
    ]);

    Livewire::test(RecipeMismatchResource\Pages\ListRecipeMismatches::class)
        ->callTableAction('tautkan', $mismatch, ['inventory_item_id' => $garam->id])
        ->assertHasNoTableActionErrors();

    // Satu keputusan harus menyentuh seluruh barisnya, bukan hanya satu.
    expect(RecipeItem::query()->where('inventory_item_id', $garam->id)->count())->toBe(2)
        ->and($mismatch->fresh()->status)->toBe(RecipeMismatch::STATUS_LINKED)
        ->and($mismatch->fresh()->resolved_by)->toBe($this->user->id);
});

it('membuat bahan baru dari daftar bahan belum cocok lalu menautkannya', function () {
    $recipe = Recipe::query()->create([
        'name' => 'Sop Ayam', 'jenis' => Recipe::JENIS_UTAMA,
        'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25,
    ]);

    RecipeItem::query()->create([
        'recipe_id' => $recipe->id, 'raw_name' => 'garam', 'qty' => 5, 'unit' => 'gr',
    ]);

    $mismatch = RecipeMismatch::query()->create([
        'raw_name' => 'garam', 'raw_name_norm' => 'garam',
        'occurrence_count' => 1, 'recipe_count' => 1, 'sample_unit' => 'gr',
        'status' => RecipeMismatch::STATUS_OPEN,
    ]);

    Livewire::test(RecipeMismatchResource\Pages\ListRecipeMismatches::class)
        ->callTableAction('buat_bahan', $mismatch, [
            'name' => 'Garam Dapur',
            'unit' => 'kg',
            'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
            'unit_price' => 8000,
        ])
        ->assertHasNoTableActionErrors();

    $garam = InventoryItem::query()->firstWhere('name', 'Garam Dapur');

    // Bahan baru harus bergabung ke bucket lama, bukan berdiri sendiri: bucket
    // itulah yang memegang histori pembelian dan menjadi sumber angka Laba Rugi.
    expect($garam)->not->toBeNull()
        ->and($garam->parent_id)->toBe($this->bucket->id)
        ->and(RecipeItem::query()->unmatched()->count())->toBe(0)
        ->and($mismatch->fresh()->status)->toBe(RecipeMismatch::STATUS_CREATED);
});

it('melepas kembali tautan ketika keputusan dibuka ulang dari daftar', function () {
    $recipe = Recipe::query()->create([
        'name' => 'Sop Ayam', 'jenis' => Recipe::JENIS_UTAMA,
        'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25,
    ]);

    RecipeItem::query()->create([
        'recipe_id' => $recipe->id,
        'inventory_item_id' => $this->tepung->id,
        'raw_name' => 'tepung', 'qty' => 5, 'unit' => 'gr',
    ]);

    $mismatch = RecipeMismatch::query()->create([
        'raw_name' => 'tepung', 'raw_name_norm' => 'tepung',
        'occurrence_count' => 1, 'recipe_count' => 1,
        'status' => RecipeMismatch::STATUS_LINKED,
        'resolved_inventory_item_id' => $this->tepung->id,
    ]);

    // Daftar bawaannya hanya menampilkan yang belum diputuskan, jadi keputusan
    // yang sudah selesai baru terlihat setelah saringannya diubah.
    Livewire::test(RecipeMismatchResource\Pages\ListRecipeMismatches::class)
        ->filterTable('status', RecipeMismatch::STATUS_LINKED)
        ->callTableAction('buka_ulang', $mismatch)
        ->assertHasNoTableActionErrors();

    expect(RecipeItem::query()->unmatched()->count())->toBe(1)
        ->and($mismatch->fresh()->status)->toBe(RecipeMismatch::STATUS_OPEN);
});
