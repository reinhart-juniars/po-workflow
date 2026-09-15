<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    // Policy modul membaca izin spatie; tanpa sinkron ini setiap peran di tes
    // akan kosong izin dan seluruh panel membalas 403.
    ->beforeEach(fn () => App\Support\Access\ModuleAccess::sync())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Data produksi lengkap untuk tes alur SPK Produksi -> Form Kebutuhan ->
 * kartu stok: dua bahan berharga, satu resep (10 porsi), satu SPK dengan dua
 * PO (30 + 50 porsi) dan satu produk tanpa resep.
 *
 * @return array{bucket: App\Models\InventoryItem, tepung: App\Models\InventoryItem, minyak: App\Models\InventoryItem, recipe: App\Models\Recipe, spk: App\Models\Spk, pos: list<App\Models\PurchaseOrder>, product: App\Models\Product, tanpaResep: App\Models\Product}
 */
function siapkanProduksi(): array
{
    $bucket = App\Models\InventoryItem::query()->create([
        'name' => 'Bahan Baku', 'unit' => 'All',
        'category' => App\Models\InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true,
    ]);

    $tepung = App\Models\InventoryItem::query()->create([
        'parent_id' => $bucket->id, 'name' => 'Tepung Terigu', 'unit' => 'kg',
        'category' => App\Models\InventoryItem::CATEGORY_RAW_MATERIAL, 'unit_price' => 12000, 'is_active' => true,
    ]);

    $minyak = App\Models\InventoryItem::query()->create([
        'parent_id' => $bucket->id, 'name' => 'Minyak Goreng', 'unit' => 'liter',
        'category' => App\Models\InventoryItem::CATEGORY_RAW_MATERIAL, 'unit_price' => 20000, 'is_active' => true,
    ]);

    $product = App\Models\Product::query()->create(['name' => 'Gorengan 10K', 'unit' => 'porsi', 'base_price' => 10000, 'active' => true]);
    $tanpaResep = App\Models\Product::query()->create(['name' => 'Es Teh', 'unit' => 'cup', 'base_price' => 5000, 'active' => true]);

    // Resep untuk 10 porsi: 250 gr tepung + 150 ml minyak.
    $recipe = App\Models\Recipe::query()->create([
        'name' => 'Gorengan', 'jenis' => App\Models\Recipe::JENIS_UTAMA, 'product_id' => $product->id,
        'yield_qty' => 10, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25,
    ]);
    App\Models\RecipeItem::query()->create(['recipe_id' => $recipe->id, 'inventory_item_id' => $tepung->id, 'raw_name' => 'tepung', 'qty' => 250, 'unit' => 'gr']);
    App\Models\RecipeItem::query()->create(['recipe_id' => $recipe->id, 'inventory_item_id' => $minyak->id, 'raw_name' => 'minyak', 'qty' => 150, 'unit' => 'ml']);

    $area = App\Models\Area::query()->create(['name' => 'Area Produksi', 'code' => 'APR']);
    $customer = App\Models\Customer::query()->create(['name' => 'Pelanggan Produksi', 'area_id' => $area->id, 'is_lapak' => false]);

    $pj = App\Models\User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $spk = App\Models\Spk::query()->create([
        'scheduled_at' => '2026-09-15 07:00:00', 'slot_type' => 'fixed_07',
        'status' => 'draft', 'responsible_user_id' => $pj->id,
    ]);

    $pos = [];

    foreach ([30, 50] as $qty) {
        $po = App\Models\PurchaseOrder::query()->create([
            'customer_id' => $customer->id, 'recipient_name' => 'Pelanggan', 'shipping_address' => 'Jl.',
            'area_id' => $area->id, 'delivery_date' => '2026-09-15', 'delivery_time' => '09:00:00',
            'payment_type' => 'cash', 'status' => 'pending',
            'created_by' => $pj->id, 'updated_by' => $pj->id,
        ]);
        App\Models\PurchaseOrderItem::query()->create(['purchase_order_id' => $po->id, 'product_id' => $product->id, 'qty' => $qty, 'unit' => 'porsi']);
        $spk->purchaseOrders()->attach($po->id);
        $pos[] = $po;
    }

    // Item yang produknya belum punya resep.
    App\Models\PurchaseOrderItem::query()->create(['purchase_order_id' => $pos[0]->id, 'product_id' => $tanpaResep->id, 'qty' => 20, 'unit' => 'cup']);

    return compact('bucket', 'tepung', 'minyak', 'recipe', 'spk', 'pos', 'product', 'tanpaResep');
}

/**
 * Cara pembayaran belanja untuk Form Kebutuhan yang sudah Disetujui: tunai
 * dari akun kas & kategori Pembelian Stok yang dibuat di sini. Wajib sebelum
 * Periksa bila ada bahan yang dibeli, karena Periksa membuat pembelian bahan
 * baku beserta kas keluarnya.
 *
 * @return array{cash_account: App\Models\CashAccount, category: App\Models\ExpenseCategory}
 */
function bayarTunai(App\Models\Requisition $requisition, ?string $supplier = null): array
{
    $category = App\Models\ExpenseCategory::query()->firstOrCreate(
        ['name' => 'Pembelian Stok (tes)'],
        ['expense_mode' => App\Models\ExpenseCategory::MODE_INVENTORY_PURCHASE, 'is_active' => true],
    );
    $cashAccount = App\Models\CashAccount::query()->firstOrCreate(
        ['name' => 'Kas Tes'],
        ['type' => 'cash', 'is_active' => true],
    );

    app(App\Services\RequisitionService::class)->recordPaymentHeader($requisition, [
        'payment_type' => 'cash',
        'expense_category_id' => $category->id,
        'cash_account_id' => $cashAccount->id,
        'supplier_name' => $supplier,
    ]);

    return ['cash_account' => $cashAccount, 'category' => $category];
}
