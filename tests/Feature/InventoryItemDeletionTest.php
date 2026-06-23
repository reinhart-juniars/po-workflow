<?php

use App\Models\InventoryItem;
use App\Models\InventoryPurchase;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;

beforeEach(function () {
    Role::findOrCreate('accounting', 'web');
});

it('deletes an unused inventory item', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('accounting');

    $item = InventoryItem::query()->create([
        'name' => 'Salah Input',
        'unit' => 'pcs',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'is_active' => true,
    ]);

    actingAs($user);

    delete(route('accountingapp.inventory-items.destroy', $item))
        ->assertRedirect()
        ->assertSessionHas('success', 'Item berhasil dihapus');

    expect(InventoryItem::query()->whereKey($item->id)->exists())->toBeFalse();
});

it('refuses to delete an inventory item used by stock purchase records', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('accounting');

    $item = InventoryItem::query()->create([
        'name' => 'Ayam Utuh',
        'unit' => 'Ekor',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'is_active' => true,
    ]);

    InventoryPurchase::query()->create([
        'inventory_item_id' => $item->id,
        'transaction_date' => '2026-05-01',
        'qty' => 1,
        'unit_cost' => 50000,
        'total_value' => 50000,
        'payment_type' => 'cash',
    ]);

    actingAs($user);

    delete(route('accountingapp.inventory-items.destroy', $item))
        ->assertRedirect()
        ->assertSessionHas('error', 'Item tidak bisa dihapus karena sudah dipakai di pembelian stok (1). Hapus atau koreksi transaksi terkait dulu.');

    expect(InventoryItem::query()->whereKey($item->id)->exists())->toBeTrue();
});
