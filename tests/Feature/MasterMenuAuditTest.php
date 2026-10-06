<?php

use App\Models\InventoryItem;
use App\Models\Product;
use App\Services\MasterMenu\MasterMenuAuditService;
use App\Services\MasterMenu\MasterMenuSource;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\artisan;

/** File sumber sementara yang dibuat tiap test, dibersihkan di afterEach. */
$sumberSementara = [];

/**
 * Membuat file SQLite tiruan Master Menu Revamp dengan skema minimal yang
 * dipakai audit. Dibuat sebagai file nyata (bukan mock) supaya jalur baca
 * lintas-database benar-benar teruji.
 */
function buatSumberMasterMenu(array $overrides = []): string
{
    global $sumberSementara;

    $path = tempnam(sys_get_temp_dir(), 'mm_').'.sqlite';
    touch($path);
    $sumberSementara[] = $path;

    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('CREATE TABLE ingredients (id INTEGER PRIMARY KEY, name TEXT, category TEXT, pack_unit TEXT, pack_qty REAL DEFAULT 1, pack_price REAL DEFAULT 0, unit_price REAL DEFAULT 0)');
    $pdo->exec('CREATE TABLE recipes (id INTEGER PRIMARY KEY, name TEXT, jenis TEXT, kategori TEXT, target_price REAL)');
    $pdo->exec('CREATE TABLE recipe_items (id INTEGER PRIMARY KEY, recipe_id INTEGER, ingredient_id INTEGER, ref_recipe_id INTEGER, raw_name TEXT, qty REAL, unit TEXT)');
    $pdo->exec('CREATE TABLE unmatched_items (id INTEGER PRIMARY KEY, raw_name TEXT)');
    $pdo->exec('CREATE TABLE ingredient_price_history (id INTEGER PRIMARY KEY, ingredient_id INTEGER)');
    $pdo->exec('CREATE TABLE menu_tasks (id INTEGER PRIMARY KEY, recipe_id INTEGER)');
    $pdo->exec('CREATE TABLE spk (id INTEGER PRIMARY KEY, title TEXT)');
    $pdo->exec('CREATE TABLE spk_orders (id INTEGER PRIMARY KEY, spk_id INTEGER)');
    $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, title TEXT)');
    $pdo->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY, order_id INTEGER)');
    $pdo->exec('CREATE TABLE produksi (id INTEGER PRIMARY KEY, title TEXT)');
    $pdo->exec('CREATE TABLE produksi_rows (id INTEGER PRIMARY KEY, produksi_id INTEGER)');

    $pdo->exec("INSERT INTO ingredients (id, name, category, pack_unit, pack_qty, pack_price, unit_price) VALUES
        (1, 'Bawang Putih', 'bumbu', 'kg', 1, 30000, 30),
        (2, 'Plastik Mika', 'kemasan', 'pcs', 100, 50000, 500)");

    $pdo->exec("INSERT INTO recipes (id, name, jenis, kategori, target_price) VALUES
        (1, 'NASI CAPJAY 12K', 'utama', 'karbo', 12000),
        (2, 'Nasi Capjay', 'utama', 'karbo', NULL),
        (3, 'Menu Yang Tidak Ada Di PO', 'utama', NULL, NULL)");

    // Resep 1 & 3 memakai bahan tak terdaftar 'garam' -> yatim di 2 resep.
    $pdo->exec("INSERT INTO recipe_items (recipe_id, ingredient_id, ref_recipe_id, raw_name, qty, unit) VALUES
        (1, 1, NULL, 'Bawang Putih', 10, 'gr'),
        (1, NULL, NULL, 'garam', 5, 'gr'),
        (3, NULL, NULL, 'Garam', 3, 'gr'),
        (3, NULL, 2, 'Nasi Capjay', 1, 'porsi')");

    $pdo->exec("INSERT INTO unmatched_items (raw_name) VALUES ('garam')");

    foreach ($overrides as $sql) {
        $pdo->exec($sql);
    }

    return $path;
}

beforeEach(function () {
    InventoryItem::query()->create([
        'name' => 'Bahan Baku',
        'unit' => 'All',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'is_active' => true,
    ]);

    InventoryItem::query()->create([
        'name' => 'Packaging',
        'unit' => 'Pack',
        'category' => InventoryItem::CATEGORY_PACKAGING,
        'is_active' => true,
    ]);
});

afterEach(function () {
    global $sumberSementara;

    // Windows mengunci file selama koneksi PDO masih hidup, jadi putuskan dulu.
    DB::purge(MasterMenuSource::CONNECTION);

    foreach ($sumberSementara as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    $sumberSementara = [];
});

it('memetakan resep ke produk lewat pencocokan persis dan normalisasi', function () {
    $path = buatSumberMasterMenu();

    // Produk PO-Workflow menyimpan harga di dalam nama; resep tidak.
    Product::query()->create([
        'sku' => 'NASI-CAPJAY-12K',
        'name' => 'NASI CAPJAY 12K',
        'unit' => 'PORSI',
        'base_price' => 12000,
        'active' => true,
    ]);

    $audit = new MasterMenuAuditService(new MasterMenuSource($path));
    $mapping = $audit->menuMapping()->keyBy('nama_resep');

    // Positive control: nama identik harus cocok persis.
    expect($mapping['NASI CAPJAY 12K']['status'])->toBe('cocok_persis')
        ->and($mapping['NASI CAPJAY 12K']['product_sku'])->toBe('NASI-CAPJAY-12K');

    // Token harga dibuang, jadi "Nasi Capjay" tetap menemukan produknya.
    expect($mapping['Nasi Capjay']['status'])->toBe('cocok_normalisasi')
        ->and($mapping['Nasi Capjay']['product_sku'])->toBe('NASI-CAPJAY-12K');

    // Yang benar-benar tidak ada padanannya harus dilaporkan, bukan dipaksa cocok.
    expect($mapping['Menu Yang Tidak Ada Di PO']['status'])->toBe('belum_cocok')
        ->and($mapping['Menu Yang Tidak Ada Di PO']['product_id'])->toBeNull();

});

it('mengagregasi baris resep yatim per nama bahan mentah', function () {
    $path = buatSumberMasterMenu();

    $orphans = new MasterMenuAuditService(new MasterMenuSource($path));
    $rows = $orphans->orphanRecipeItems();

    // 'garam' dan 'Garam' adalah nama yang sama setelah normalisasi.
    expect($rows)->toHaveCount(1);
    expect($rows->first()['jumlah_baris'])->toBe(2)
        ->and($rows->first()['jumlah_resep'])->toBe(2);

    // Baris yang sudah menunjuk ke ingredient atau sub-resep tidak dihitung yatim.
    expect($rows->pluck('nama_mentah'))->not->toContain('Bawang Putih');

});

it('mengarahkan bahan kemasan ke bucket packaging dan sisanya ke bahan baku', function () {
    $path = buatSumberMasterMenu();

    $mapping = (new MasterMenuAuditService(new MasterMenuSource($path)))
        ->ingredientMapping()
        ->keyBy('nama_bahan');

    expect($mapping['Plastik Mika']['kategori_tujuan'])->toBe(InventoryItem::CATEGORY_PACKAGING)
        ->and($mapping['Plastik Mika']['bucket_tujuan'])->toBe('Packaging');

    expect($mapping['Bawang Putih']['kategori_tujuan'])->toBe(InventoryItem::CATEGORY_RAW_MATERIAL)
        ->and($mapping['Bawang Putih']['bucket_tujuan'])->toBe('Bahan Baku');

});

it('menjalankan command audit tanpa menulis file dan melaporkan angka rekonsiliasi', function () {
    $path = buatSumberMasterMenu();

    Product::query()->create([
        'sku' => 'NASI-CAPJAY-12K',
        'name' => 'NASI CAPJAY 12K',
        'unit' => 'PORSI',
        'base_price' => 12000,
        'active' => true,
    ]);

    artisan('inventory:audit-master-menu', ['--db' => $path, '--no-export' => true])
        ->expectsOutputToContain('Resep belum cocok (perlu mapping manual)')
        ->assertSuccessful();

});

it('gagal dengan pesan jelas ketika file sumber tidak ada', function () {
    artisan('inventory:audit-master-menu', [
        '--db' => sys_get_temp_dir().'/tidak-ada-file-ini.sqlite',
        '--no-export' => true,
    ])->assertFailed();
});
