<?php

use App\Models\Area;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Spk;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Rantai Admin -> Production -> Inventory: slot SPK yang dibuat admin
 * langsung menjadi SPK Produksi (modul inventory) lengkap dengan barisnya,
 * Production App hanya menampilkan detail PO (Form Kebutuhan dipegang tim
 * inventory), dan SPK Produksi menautkan balik ke PO di Admin App.
 */
function pengguna(string $role): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

/** @return array{po: PurchaseOrder, product: Product} */
function poDraft(): array
{
    $bucket = InventoryItem::query()->create(['name' => 'Bahan Baku', 'unit' => 'All', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);
    $tepung = InventoryItem::query()->create(['name' => 'Tepung', 'unit' => 'kg', 'unit_price' => 12000, 'parent_id' => $bucket->id, 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);
    $product = Product::query()->create(['name' => 'Gorengan 10K', 'unit' => 'porsi', 'base_price' => 10000, 'active' => true]);
    $recipe = Recipe::query()->create(['name' => 'Gorengan', 'yield_qty' => 10, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25, 'is_active' => true]);
    $product->update(['recipe_id' => $recipe->id]);
    RecipeItem::query()->create(['recipe_id' => $recipe->id, 'inventory_item_id' => $tepung->id, 'raw_name' => 'tepung', 'qty' => 250, 'unit' => 'gr']);

    $area = Area::query()->create(['name' => 'Area', 'code' => 'AR']);
    $customer = Customer::query()->create(['name' => 'Pelanggan', 'area_id' => $area->id, 'is_lapak' => false]);
    $admin = pengguna('admin');

    $po = PurchaseOrder::query()->create([
        'customer_id' => $customer->id, 'recipient_name' => 'Pelanggan', 'shipping_address' => 'Jl.',
        'area_id' => $area->id, 'delivery_date' => '2026-09-20', 'delivery_time' => '09:00:00',
        'payment_type' => 'cash', 'status' => 'draft', 'created_by' => $admin->id, 'updated_by' => $admin->id,
    ]);
    PurchaseOrderItem::query()->create(['purchase_order_id' => $po->id, 'product_id' => $product->id, 'qty' => 40, 'unit' => 'porsi']);

    return ['po' => $po, 'product' => $product, 'admin' => $admin];
}

it('menyusun spk produksi otomatis saat admin membuat slot spk', function () {
    ['po' => $po, 'admin' => $admin] = poDraft();

    $this->actingAs($admin)
        ->post(route('adminapp.spk.store'), ['po_ids' => [$po->id], 'schedule_date' => '2026-09-20', 'slot_type' => 'fixed_07'])
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $pesan) => str_contains($pesan, 'SPK Produksi') && str_contains($pesan, 'ikut tersusun'));

    $spk = Spk::query()->latest('id')->first();
    $order = $spk->productionOrder;

    expect($order)->not->toBeNull()
        ->and($order->lines)->toHaveCount(1)
        ->and($order->lines->first()->purchase_order_item_id)->toBe($po->items()->first()->id)
        ->and((float) $order->lines->first()->qty)->toBe(40.0)
        ->and($order->production_date->toDateString())->toBe('2026-09-20');
});

it('hanya memberi detail PO di production app, tanpa form kebutuhan', function () {
    ['po' => $po, 'admin' => $admin] = poDraft();
    $produksi = pengguna('production');

    // Slot lama (dibuat sebelum integrasi): belum punya SPK Produksi.
    $spk = Spk::query()->create(['scheduled_at' => '2026-09-20 07:00:00', 'slot_type' => 'fixed_07', 'status' => 'in_process', 'responsible_user_id' => $admin->id]);
    $spk->purchaseOrders()->attach($po->id);
    $po->update(['status' => 'in_progress']);

    // Form Kebutuhan dipegang tim inventory (Inventory App), bukan produksi.
    $this->actingAs($produksi)->get(route('productionapp.dashboard'))
        ->assertOk()
        ->assertSee($po->po_number)
        ->assertSee(route('productionapp.orders.show', $po), false)
        ->assertDontSee('Form Kebutuhan');

    // Jalan pintas lama untuk menyusun SPK Produksi dari production app sudah tidak ada.
    $this->actingAs($produksi)->post('/production-app/spk/'.$spk->id.'/production-order')->assertNotFound();
    expect(ProductionOrder::query()->where('spk_id', $spk->id)->exists())->toBeFalse();

    // Setelah SPK Produksinya tersusun pun, dashboard tetap hanya menautkan ke detail PO.
    $order = app(\App\Services\ProductionOrderService::class)->generateFromSpk($spk, $admin->id);

    $this->actingAs($produksi)->get(route('productionapp.dashboard'))
        ->assertOk()
        ->assertSee(route('productionapp.orders.show', $po), false)
        ->assertDontSee('Form Kebutuhan')
        ->assertDontSee(route('filament.admin.resources.production-orders.kebutuhan', ['record' => $order]), false);
});

it('menautkan spk produksi balik ke po di admin app', function () {
    ['po' => $po, 'admin' => $admin] = poDraft();
    $spk = Spk::query()->create(['scheduled_at' => '2026-09-20 07:00:00', 'slot_type' => 'fixed_07', 'status' => 'in_process', 'responsible_user_id' => $admin->id]);
    $spk->purchaseOrders()->attach($po->id);
    $order = app(\App\Services\ProductionOrderService::class)->generateFromSpk($spk, $admin->id);

    $this->actingAs($admin)
        ->get(route('filament.admin.resources.production-orders.edit', ['record' => $order]))
        ->assertOk()
        ->assertSee($spk->spk_code)
        ->assertSee($po->po_number)
        ->assertSee(route('adminapp.orders.show', $po), false);
});
