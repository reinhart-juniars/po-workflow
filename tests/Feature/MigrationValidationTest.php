<?php

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ProductionOrder;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Requisition;
use App\Services\MasterMenu\MigrationValidationService;

/**
 * Validasi pasca migrasi: setiap pemeriksaan harus menyala pada data yang
 * memang rusak dan diam pada data yang bersih (positive control), dan
 * pembersihan --fix hanya menyentuh yang aman.
 */
beforeEach(function () {
    // Pembanding app.db milik mesin pengembang tidak boleh ikut menilai data uji.
    config(['master_menu.database' => null]);
});

function bucketUji(): InventoryItem
{
    return InventoryItem::query()->create(['name' => 'Bahan Baku', 'unit' => 'All', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);
}

function bahanUji(InventoryItem $bucket, string $name, array $extra = []): InventoryItem
{
    return InventoryItem::query()->create(array_merge([
        'name' => $name, 'unit' => 'kg', 'unit_price' => 10000, 'parent_id' => $bucket->id,
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true,
    ], $extra));
}

function temuan(string $check): array
{
    $found = app(MigrationValidationService::class)->run()->firstWhere('check', $check);
    expect($found)->not->toBeNull("Pemeriksaan '{$check}' tidak ada di laporan.");

    return $found;
}

it('melaporkan semua info pada data yang bersih', function () {
    $bucket = bucketUji();
    $beras = bahanUji($bucket, 'Beras');
    $resep = Recipe::query()->create(['name' => 'Nasi Putih', 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25, 'is_active' => true]);
    $resep->items()->create(['inventory_item_id' => $beras->id, 'raw_name' => 'Beras', 'qty' => 0.1, 'unit' => 'kg']);

    $laporan = app(MigrationValidationService::class)->run();

    expect($laporan->where('level', MigrationValidationService::ERROR))->toBeEmpty()
        ->and($laporan->where('level', MigrationValidationService::WARN))->toBeEmpty()
        ->and($laporan)->not->toBeEmpty();

    $this->artisan('inventory:validate-migration', ['--fail-on' => 'warn'])->assertSuccessful();
});

it('menandai bahan tanpa satuan, tanpa harga, duplikat, dan satuan asing', function () {
    $bucket = bucketUji();
    bahanUji($bucket, 'Garam', ['unit' => '']);
    bahanUji($bucket, 'Gula', ['unit_price' => null]);
    bahanUji($bucket, 'Terasi');
    bahanUji($bucket, ' terasi ');
    bahanUji($bucket, 'Bawang', ['unit' => 'bnggl']);

    expect(temuan('Bahan tanpa satuan'))->toMatchArray(['level' => 'error', 'count' => 1])
        ->and(temuan('Bahan aktif tanpa harga satuan'))->toMatchArray(['level' => 'warn', 'count' => 1])
        ->and(temuan('Bahan bernama sama dalam satu bucket'))->toMatchArray(['level' => 'error', 'count' => 1])
        ->and(temuan('Satuan bahan di luar registri'))->toMatchArray(['level' => 'warn', 'count' => 1]);

    // Error membuat perintah gagal pada --fail-on bawaan.
    $this->artisan('inventory:validate-migration')->assertFailed();
    $this->artisan('inventory:validate-migration', ['--fail-on' => 'none'])->assertSuccessful();
});

it('menandai resep tanpa bahan, baris belum tertaut, rujukan diri, dan produk ganda', function () {
    $bucket = bucketUji();
    $beras = bahanUji($bucket, 'Beras');
    $product = \App\Models\Product::query()->create(['name' => 'NASI', 'price' => 10000, 'is_active' => true]);

    $kosong = Recipe::query()->create(['name' => 'Kosong', 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25, 'is_active' => true, 'product_id' => $product->id]);
    $putar = Recipe::query()->create(['name' => 'Putar', 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25, 'is_active' => true, 'product_id' => $product->id]);
    $putar->items()->create(['ref_recipe_id' => $putar->id, 'raw_name' => 'Putar', 'qty' => 1, 'unit' => 'porsi']);
    $putar->items()->create(['raw_name' => 'kecap misterius', 'qty' => 1, 'unit' => 'ml']);
    $putar->items()->create(['inventory_item_id' => $beras->id, 'raw_name' => 'Beras', 'qty' => 1, 'unit' => 'kg']);

    expect(temuan('Resep aktif tanpa satu pun bahan'))->toMatchArray(['level' => 'warn', 'count' => 1])
        ->and(temuan('Baris resep belum tertaut ke bahan'))->toMatchArray(['level' => 'warn', 'count' => 1])
        ->and(temuan('Resep merujuk dirinya sendiri'))->toMatchArray(['level' => 'error', 'count' => 1])
        ->and(temuan('Produk dipetakan ke lebih dari satu resep'))->toMatchArray(['level' => 'error', 'count' => 1])
        ->and(temuan('Resep aktif terpetakan ke produk penjualan'))->toMatchArray(['level' => 'info', 'count' => 2]);
});

it('menandai saldo ledger negatif dan form kebutuhan yang menggantung', function () {
    $bucket = bucketUji();
    $beras = bahanUji($bucket, 'Beras');
    $order = ProductionOrder::query()->create(['production_date' => now()->toDateString(), 'status' => ProductionOrder::STATUS_CANCELLED]);
    Requisition::query()->create(['production_order_id' => $order->id, 'status' => Requisition::STATUS_APPROVED]);

    InventoryMovement::query()->create([
        'inventory_item_id' => $beras->id, 'type' => InventoryMovement::TYPE_USAGE, 'qty' => -3, 'unit' => 'kg',
        'unit_price' => 10000, 'total_value' => -30000, 'moved_at' => now()->toDateString(),
    ]);

    expect(temuan('Bahan bersaldo ledger negatif'))->toMatchArray(['level' => 'warn', 'count' => 1])
        ->and(temuan('Form Kebutuhan menggantung pada SPK selesai/batal'))->toMatchArray(['level' => 'warn', 'count' => 1]);
});

it('merapikan nama, mengkanonkan satuan bahan, dan menghapus baris kosong dengan --fix', function () {
    $bucket = bucketUji();
    $item = bahanUji($bucket, "Tepung   Terigu\t", ['unit' => 'Kg']);
    $resep = Recipe::query()->create(['name' => 'Roti  Tawar', 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25, 'is_active' => true]);
    $resep->items()->create(['inventory_item_id' => $item->id, 'raw_name' => 'Tepung', 'qty' => 2, 'unit' => 'gr']);
    $kosong = $resep->items()->create(['raw_name' => '', 'qty' => 0, 'unit' => null]);

    expect(temuan('Nama dengan spasi berlebih (bisa dirapikan --fix)')['count'])->toBe(2)
        ->and(temuan('Satuan bahan ditulis dengan alias (bisa dikanonkan --fix)')['count'])->toBe(1)
        ->and(temuan('Baris resep kosong (bisa dihapus --fix)')['count'])->toBe(1);

    $this->artisan('inventory:validate-migration', ['--fix' => true, '--fail-on' => 'warn'])
        ->expectsOutputToContain('nama_dirapikan=2')
        ->assertSuccessful();

    expect($item->fresh()->name)->toBe('Tepung Terigu')
        ->and($item->fresh()->unit)->toBe('kg')
        ->and($resep->fresh()->name)->toBe('Roti Tawar')
        ->and($resep->fresh()->name_norm)->toBe(Recipe::normalizeName('Roti Tawar'))
        ->and(RecipeItem::query()->whereKey($kosong->id)->exists())->toBeFalse()
        // Satuan baris resep dibiarkan seperti ditulis; pengonversi paham aliasnya.
        ->and($resep->items()->first()->unit)->toBe('gr');

    // Positive control: setelah --fix laporan bersih dari peringatan tersebut.
    expect(temuan('Nama dengan spasi berlebih (bisa dirapikan --fix)')['count'])->toBe(0)
        ->and(temuan('Satuan bahan ditulis dengan alias (bisa dikanonkan --fix)')['count'])->toBe(0);
});
