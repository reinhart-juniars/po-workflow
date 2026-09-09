<?php

use App\Models\InventoryItem;
use App\Models\InventoryItemPriceHistory;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeMismatch;
use App\Services\MasterMenu\MasterMenuMigrationService;
use App\Services\MasterMenu\MasterMenuSource;

/**
 * Perpindahan master data dari Master Menu Revamp.
 *
 * Perpindahan ini akan dijalankan berkali-kali sampai hari cutover, karena data
 * di sisi klien masih terus diisi. Karena itu yang paling perlu dijaga bukan
 * hanya "datanya masuk", melainkan "dijalankan dua kali hasilnya tetap sama" --
 * penggandaan diam-diam pada histori harga akan membuat jejak perubahan harga
 * tidak bisa dipercaya.
 */
function sumberMasterMenu(): MasterMenuSource
{
    $path = tempnam(sys_get_temp_dir(), 'mm_').'.sqlite';
    touch($path);

    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('
        CREATE TABLE ingredients (
            id INTEGER PRIMARY KEY, name TEXT, name_norm TEXT, category TEXT,
            pack_unit TEXT, pack_qty REAL, pack_price REAL, unit_price REAL,
            is_prepared INTEGER DEFAULT 0, notes TEXT, updated_at TEXT
        );
        CREATE TABLE ingredient_price_history (
            id INTEGER PRIMARY KEY, ingredient_id INTEGER,
            old_unit_price REAL, new_unit_price REAL,
            old_pack_price REAL, new_pack_price REAL,
            action TEXT, source TEXT, note TEXT, created_at TEXT
        );
        CREATE TABLE recipes (
            id INTEGER PRIMARY KEY, name TEXT, name_norm TEXT, tier TEXT,
            yield_qty REAL, yield_unit TEXT, ohc_pct REAL, profit_pct REAL,
            target_price REAL, notes TEXT, source_sheet TEXT, jenis TEXT,
            kategori TEXT, snapshot_hpp REAL, snapshot_ohc REAL, snapshot_profit REAL
        );
        CREATE TABLE recipe_items (
            id INTEGER PRIMARY KEY, recipe_id INTEGER, sort_order INTEGER,
            section TEXT, ingredient_id INTEGER, ref_recipe_id INTEGER,
            raw_name TEXT, qty REAL, unit TEXT, unit_price_snapshot REAL, notes TEXT
        );
    ');

    $pdo->exec("
        INSERT INTO ingredients (id, name, category, pack_unit, pack_qty, pack_price, unit_price, is_prepared)
        VALUES (1, 'Tepung Terigu', 'karbo', 'kg', 1, 12000, 12000, 0),
               (2, 'Cabai Merah', 'sayur', 'kg', 1, 40000, 40000, 0),
               (3, 'Mika 500ml', 'kemasan', 'pcs', 1, 500, 500, 0);

        INSERT INTO ingredient_price_history (id, ingredient_id, old_unit_price, new_unit_price, action, source, created_at)
        VALUES (1, 1, NULL, 11000, 'set-awal', 'manual', '2026-01-05 08:00:00'),
               (2, 1, 11000, 12000, 'naik', 'manual', '2026-03-10 09:30:00'),
               (3, 2, 40000, 3291.6666666667, 'naik', 'manual', '2026-04-02 07:15:00');

        INSERT INTO recipes (id, name, yield_qty, yield_unit, ohc_pct, profit_pct, jenis, kategori)
        VALUES (1, 'Sambal Matah', 10, 'porsi', 0.4, 0.25, 'sub', 'sambal'),
               (2, 'Nasi Sambal Matah', 1, 'porsi', 0.4, 0.25, 'utama', 'paket');

        INSERT INTO recipe_items (id, recipe_id, sort_order, ingredient_id, ref_recipe_id, raw_name, qty, unit)
        VALUES (1, 1, 0, 2, NULL, 'cabai merah', 500, 'gr'),
               (2, 1, 1, NULL, NULL, 'garam', 10, 'gr'),
               (3, 2, 0, NULL, 1, 'sambal matah', 1, 'porsi'),
               (4, 2, 1, 1, NULL, 'tepung terigu', 50, 'gr'),
               (5, 2, 2, NULL, NULL, 'garam', 5, 'gr');
    ");

    return new MasterMenuSource($path);
}

beforeEach(function () {
    $this->migrator = new MasterMenuMigrationService(sumberMasterMenu());
});

it('memindahkan bahan ke bawah bucket yang sesuai berikut harganya', function () {
    $this->migrator->run();

    $tepung = InventoryItem::query()->firstWhere('source_ingredient_id', 1);
    $mika = InventoryItem::query()->firstWhere('source_ingredient_id', 3);

    expect($tepung->name)->toBe('Tepung Terigu')
        ->and((float) $tepung->unit_price)->toBe(12000.0)
        ->and($tepung->ingredient_group)->toBe('karbo')
        ->and($tepung->unit)->toBe('kg')
        ->and($tepung->parent->category)->toBe(InventoryItem::CATEGORY_RAW_MATERIAL);

    // Bahan berkategori kemasan masuk ke bucket Packaging, bukan Bahan Baku.
    expect($mika->parent->category)->toBe(InventoryItem::CATEGORY_PACKAGING);
});

it('membawa histori harga beserta waktu kejadian aslinya', function () {
    $this->migrator->run();

    $tepung = InventoryItem::query()->firstWhere('source_ingredient_id', 1);
    $histories = InventoryItemPriceHistory::query()
        ->where('inventory_item_id', $tepung->id)
        ->orderBy('created_at')
        ->get();

    // Waktu kejadian adalah bagian dari data histori. Kalau tergantikan waktu
    // impor, perubahan harga tidak bisa lagi ditelusuri ke periodenya.
    expect($histories)->toHaveCount(2)
        ->and($histories[0]->created_at->toDateString())->toBe('2026-01-05')
        ->and($histories[1]->created_at->toDateString())->toBe('2026-03-10')
        ->and((float) $histories[1]->new_unit_price)->toBe(12000.0);
});

it('tidak menggandakan apa pun ketika dijalankan dua kali', function () {
    $pertama = $this->migrator->run();

    expect($pertama['bahan']['baru'])->toBe(3)
        ->and($pertama['histori_harga']['dipindahkan'])->toBe(3)
        ->and($pertama['resep']['baru'])->toBe(2)
        ->and($pertama['baris_resep']['dipindahkan'])->toBe(5);

    $kedua = $this->migrator->run();

    expect($kedua['bahan']['baru'])->toBe(0)
        ->and($kedua['bahan']['diperbarui'])->toBe(3)
        ->and($kedua['resep']['baru'])->toBe(0)
        // Histori harga pernah tergandakan dua kali: waktu kejadiannya tidak ikut
        // tersimpan, dan harga berpresisi panjang (3291,666...) tidak pernah cocok
        // dengan nilai tersimpannya yang dibulatkan ke 4 desimal.
        ->and($kedua['histori_harga']['dipindahkan'])->toBe(0)
        ->and($kedua['histori_harga']['dilewati'])->toBe(3);

    expect(InventoryItem::query()->whereNotNull('source_ingredient_id')->count())->toBe(3)
        ->and(InventoryItemPriceHistory::query()->count())->toBe(3)
        ->and(Recipe::query()->count())->toBe(2)
        ->and(RecipeItem::query()->count())->toBe(5);
});

it('menautkan baris resep ke bahan dan sub-resep yang benar', function () {
    $this->migrator->run();

    $nasi = Recipe::query()->firstWhere('source_recipe_id', 2);
    $sambal = Recipe::query()->firstWhere('source_recipe_id', 1);
    $items = $nasi->items;

    expect($sambal->jenis)->toBe(Recipe::JENIS_SUB)
        ->and($nasi->jenis)->toBe(Recipe::JENIS_UTAMA)
        ->and($items[0]->ref_recipe_id)->toBe($sambal->id)
        ->and($items[1]->inventoryItem->name)->toBe('Tepung Terigu')
        // Baris "garam" tidak punya padanan di bahan maupun resep.
        ->and($items[2]->isUnmatched())->toBeTrue();
});

it('mengelompokkan bahan yang belum cocok per nama, bukan per baris', function () {
    $this->migrator->run();

    // "garam" muncul di dua resep berbeda, dan harus menjadi satu keputusan.
    $mismatch = RecipeMismatch::query()->firstWhere('raw_name_norm', 'garam');

    expect(RecipeMismatch::query()->count())->toBe(1)
        ->and($mismatch->occurrence_count)->toBe(2)
        ->and($mismatch->recipe_count)->toBe(2)
        ->and($mismatch->status)->toBe(RecipeMismatch::STATUS_OPEN);
});

it('mempertahankan keputusan manusia saat perpindahan diulang', function () {
    $this->migrator->run();

    $garam = InventoryItem::query()->create([
        'name' => 'Garam Dapur',
        'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'parent_id' => InventoryItem::query()->whereNull('parent_id')->value('id'),
        'unit_price' => 8000,
    ]);

    RecipeMismatch::query()->where('raw_name_norm', 'garam')->update([
        'status' => RecipeMismatch::STATUS_LINKED,
        'resolved_inventory_item_id' => $garam->id,
    ]);

    $this->migrator->run();

    $mismatch = RecipeMismatch::query()->firstWhere('raw_name_norm', 'garam');

    // Kalau status ini ikut disetel ulang, seluruh kerja rekonsiliasi bersama
    // klien akan hilang setiap kali perpindahan disegarkan.
    expect($mismatch->status)->toBe(RecipeMismatch::STATUS_LINKED)
        ->and($mismatch->resolved_inventory_item_id)->toBe($garam->id)
        ->and($mismatch->occurrence_count)->toBe(2);
});

it('membatalkan seluruh perubahan pada mode uji-jalan', function () {
    $summary = $this->migrator->run(dryRun: true);

    // Angkanya tetap mencerminkan hasil sungguhan supaya bisa dinilai lebih dulu.
    expect($summary['bahan']['baru'])->toBe(3);

    // Tetapi tidak ada satu pun yang tersimpan.
    expect(InventoryItem::query()->whereNotNull('source_ingredient_id')->count())->toBe(0)
        ->and(Recipe::query()->count())->toBe(0)
        ->and(RecipeItem::query()->count())->toBe(0);
});
