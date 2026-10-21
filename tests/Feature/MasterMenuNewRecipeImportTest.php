<?php

use App\Filament\Menu\Pages\ImportMasterMenuRecipes;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeMismatch;
use App\Models\User;
use App\Services\MasterMenu\MasterMenuNewRecipeImporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Impor resep baru dari Master Menu Revamp.
 *
 * Tim menu sudah bekerja di 3S ONE (menautkan bahan, membetulkan satuan),
 * sementara klien masih menulis resep baru di aplikasi Master Menu. Yang
 * dijaga di sini: resep baru masuk lengkap, dan tidak satu pun hasil kerja di
 * 3S ONE tertimpa -- itu yang membuat migrasi penuh tidak bisa dipakai lagi.
 */
function berkasMasterMenu(): string
{
    $path = tempnam(sys_get_temp_dir(), 'mmr_').'.db';
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('
        CREATE TABLE ingredients (
            id INTEGER PRIMARY KEY, name TEXT, category TEXT, pack_unit TEXT, pack_qty REAL,
            pack_price REAL, unit_price REAL, is_prepared INTEGER DEFAULT 0, notes TEXT
        );
        CREATE TABLE recipes (
            id INTEGER PRIMARY KEY, name TEXT, yield_qty REAL, yield_unit TEXT, ohc_pct REAL, profit_pct REAL,
            target_price REAL, notes TEXT, source_sheet TEXT, jenis TEXT, kategori TEXT,
            snapshot_hpp REAL, snapshot_ohc REAL, snapshot_profit REAL
        );
        CREATE TABLE recipe_items (
            id INTEGER PRIMARY KEY, recipe_id INTEGER, sort_order INTEGER, section TEXT, ingredient_id INTEGER,
            ref_recipe_id INTEGER, raw_name TEXT, qty REAL, unit TEXT, unit_price_snapshot REAL, notes TEXT
        );
    ');
    $pdo->exec("
        INSERT INTO ingredients (id, name, category, pack_unit, pack_qty, pack_price, unit_price) VALUES
            (10, 'Sereh', NULL, NULL, 1, 0, 8.75),
            (11, 'Kemangi', 'sayur', NULL, 1, 0, 30),
            (12, 'Cabe Rawit', 'sayur', 'kg', 1, 60000, 60000);
        INSERT INTO recipes (id, name, yield_qty, yield_unit, ohc_pct, profit_pct, jenis) VALUES
            (1, 'Bumbu Kuning (versi MMR terbaru)', 1, 'porsi', 0.4, 0.25, 'sub'),
            (2, 'Pepes Ikan', 1, 'porsi', 0.4, 0.25, 'utama');
        INSERT INTO recipe_items (id, recipe_id, sort_order, ingredient_id, ref_recipe_id, raw_name, qty, unit) VALUES
            (100, 1, 1, 10, NULL, 'sereh', 99, 'gram'),
            (200, 2, 1, 10, NULL, 'sereh', 5, 'gram'),
            (201, 2, 2, 11, NULL, 'kemangi', 3, 'gram'),
            (202, 2, 3, 12, NULL, 'rawit', 10, 'gram'),
            (203, 2, 4, NULL, 1, 'bumbu kuning', 1, 'porsi'),
            (204, 2, 5, NULL, NULL, 'garam', 2, 'gram');
    ");

    return $path;
}

beforeEach(function () {
    Role::findOrCreate('menu', 'web');
    $this->user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $this->user->assignRole('menu');
    $this->actingAs($this->user);

    $bucket = InventoryItem::query()->create(['name' => 'Bahan Baku', 'unit' => 'All', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);

    // Keadaan 3S ONE: Sereh sudah dibetulkan tim menu (pcs -> gram), resep
    // Bumbu Kuning sudah diubah di sini, dan "garam" sudah diputuskan.
    $this->sereh = InventoryItem::query()->create(['parent_id' => $bucket->id, 'name' => 'Sereh', 'unit' => 'gram', 'unit_price' => 8.75, 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true, 'source_ingredient_id' => 10]);
    $this->garam = InventoryItem::query()->create(['parent_id' => $bucket->id, 'name' => 'Garam', 'unit' => 'gram', 'unit_price' => 5, 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);

    $this->bumbu = Recipe::query()->create(['name' => 'Bumbu Kuning', 'jenis' => Recipe::JENIS_SUB, 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25, 'is_active' => true, 'source_recipe_id' => 1]);
    $this->bumbuLine = $this->bumbu->items()->create(['raw_name' => 'sereh', 'qty' => 15, 'unit' => 'gram', 'inventory_item_id' => $this->sereh->id]);

    RecipeMismatch::query()->create([
        'raw_name' => 'garam', 'raw_name_norm' => 'garam', 'occurrence_count' => 1, 'recipe_count' => 1,
        'status' => RecipeMismatch::STATUS_LINKED, 'resolved_inventory_item_id' => $this->garam->id,
    ]);
});

it('menambahkan resep baru tanpa menyentuh resep dan bahan yang sudah dikerjakan di 3S ONE', function () {
    $importer = MasterMenuNewRecipeImporter::forFile(berkasMasterMenu());

    // Pratinjau tidak menyimpan apa pun.
    $preview = $importer->run(dryRun: true);
    expect($preview['resep_baru'])->toBe(['Pepes Ikan'])
        ->and($preview['resep_sudah_ada'])->toBe(1)
        ->and(Recipe::query()->count())->toBe(1)
        ->and(InventoryItem::query()->count())->toBe(3);

    $result = $importer->run(userId: $this->user->id);

    // Kontrol positif: resep baru masuk lengkap dengan tautan bahan, sub-resep,
    // dan keputusan Bahan Belum Cocok yang sudah ada.
    $pepes = Recipe::query()->firstWhere('source_recipe_id', 2);
    $lines = $pepes->items()->orderBy('sort_order')->get();
    $kemangi = InventoryItem::query()->firstWhere('source_ingredient_id', 11);
    $rawit = InventoryItem::query()->firstWhere('source_ingredient_id', 12);

    expect($pepes->name)->toBe('Pepes Ikan')
        ->and($pepes->created_by)->toBe($this->user->id)
        ->and($lines->pluck('inventory_item_id')->all())->toBe([$this->sereh->id, $kemangi->id, $rawit->id, null, $this->garam->id])
        ->and($lines[3]->ref_recipe_id)->toBe($this->bumbu->id)
        ->and($result['baris_tertaut_keputusan'])->toBe(1)
        ->and($result['baris_belum_tertaut'])->toBe(0);

    // Bahan baru tanpa satuan di Master Menu memakai satuan baris resepnya,
    // bukan "pcs" (asal kasus Sereh yang dihargai per gram).
    expect($kemangi->unit)->toBe('gram')
        ->and($rawit->unit)->toBe('kg')
        ->and(collect($result['bahan_baru'])->pluck('perlu_cek')->all())->toBe([false, false]);

    // Yang sudah ada tidak disentuh, walau isinya di Master Menu berbeda.
    expect($this->bumbu->fresh()->name)->toBe('Bumbu Kuning')
        ->and((float) $this->bumbuLine->fresh()->qty)->toBe(15.0)
        ->and($this->bumbu->items()->count())->toBe(1)
        ->and($this->sereh->fresh()->unit)->toBe('gram');

    // Dijalankan lagi: tidak ada yang berlipat.
    $again = $importer->run();
    expect($again['resep_baru'])->toBe([])
        ->and(Recipe::query()->count())->toBe(2)
        ->and(RecipeItem::query()->where('recipe_id', $pepes->id)->count())->toBe(5);
});

it('menandai bahan baru yang satuannya tidak bisa ditebak', function () {
    $path = berkasMasterMenu();
    (new PDO('sqlite:'.$path))->exec("INSERT INTO recipe_items (id, recipe_id, sort_order, ingredient_id, raw_name, qty, unit) VALUES (205, 2, 6, 11, 'kemangi', 1, 'ikat')");

    $result = MasterMenuNewRecipeImporter::forFile($path)->run();
    $kemangi = collect($result['bahan_baru'])->firstWhere('name', 'Kemangi');

    expect($kemangi)->toBe(['name' => 'Kemangi', 'unit' => 'pcs', 'perlu_cek' => true]);
});

it('menolak berkas yang bukan database Master Menu', function () {
    $bukan = tempnam(sys_get_temp_dir(), 'x_');
    file_put_contents($bukan, 'ini bukan sqlite');

    expect(fn () => MasterMenuNewRecipeImporter::forFile($bukan)->run(dryRun: true))
        ->toThrow(RuntimeException::class, 'bukan database Master Menu');

    $sqliteLain = tempnam(sys_get_temp_dir(), 'y_').'.db';
    (new PDO('sqlite:'.$sqliteLain))->exec('CREATE TABLE produk (id INTEGER)');

    expect(fn () => MasterMenuNewRecipeImporter::forFile($sqliteLain)->run(dryRun: true))
        ->toThrow(RuntimeException::class, 'tabel ingredients, recipes, recipe_items tidak ada');
});

it('mengimpor lewat halaman unggah: pratinjau dulu, simpan saat dikonfirmasi', function () {
    Storage::fake('local');
    $file = UploadedFile::fake()->createWithContent('app.db', file_get_contents(berkasMasterMenu()));

    $page = Livewire::test(ImportMasterMenuRecipes::class)
        ->set('upload', $file)
        ->call('readFile')
        ->assertHasNoErrors()
        ->assertSee('Pepes Ikan')
        ->assertSee('Impor 1 Resep Baru');

    expect(Recipe::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles(ImportMasterMenuRecipes::FOLDER))->toHaveCount(1);

    $page->call('import')->assertNotified('1 resep baru masuk');

    // Berkas sementara dibuang setelah dipakai.
    expect(Recipe::query()->where('source_recipe_id', 2)->exists())->toBeTrue()
        ->and(Storage::disk('local')->allFiles(ImportMasterMenuRecipes::FOLDER))->toBe([]);
});

it('hanya pemegang izin kelola resep yang bisa membuka halaman impor', function () {
    Livewire::test(ImportMasterMenuRecipes::class)->assertOk();

    Role::findOrCreate('production', 'web');
    $produksi = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $produksi->assignRole('production');
    $this->actingAs($produksi);

    Livewire::test(ImportMasterMenuRecipes::class)->assertForbidden();
});
