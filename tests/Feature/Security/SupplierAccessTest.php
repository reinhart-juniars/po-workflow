<?php

use App\Filament\Resources\InventoryPurchaseResource\Pages\EditInventoryPurchase;
use App\Filament\Resources\ProductionOrderResource\Pages\RequisitionForm;
use App\Filament\Resources\SupplierResource\Pages\CreateSupplier;
use App\Filament\Resources\SupplierResource\Pages\EditSupplier;
use App\Models\InventoryItem;
use App\Models\InventoryPurchase;
use App\Models\ProductionOrder;
use App\Models\Requisition;
use App\Models\Supplier;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Hak akses Master Supplier: dijaga izin modul inventory lewat
 * SupplierPolicy. Lihat = inventory.view, tambah/ubah/hapus = inventory.manage,
 * termasuk tombol "tambah supplier" di dalam form transaksi.
 */
function penggunaSupplier(string $role): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

it('membuka daftar supplier hanya untuk peran berizin lihat inventory', function () {
    Supplier::query()->create(['name' => 'Toko Makmur', 'is_active' => true]);

    $this->actingAs(penggunaSupplier('sales'))->get('/inventory/suppliers')->assertForbidden();
    $this->actingAs(penggunaSupplier('delivery'))->get('/inventory/suppliers')->assertForbidden();

    // Positive control: produksi boleh melihat, akunting juga.
    $this->actingAs(penggunaSupplier('production'))->get('/inventory/suppliers')->assertOk()->assertSee('Toko Makmur');
    $this->actingAs(penggunaSupplier('accounting'))->get('/inventory/suppliers')->assertOk()->assertSee('Toko Makmur');
});

it('menolak tambah dan ubah supplier oleh peran yang hanya boleh melihat', function () {
    $supplier = Supplier::query()->create(['name' => 'Toko Makmur', 'is_active' => true]);

    $this->actingAs(penggunaSupplier('production'));
    Livewire::test(CreateSupplier::class)->assertForbidden();
    Livewire::test(EditSupplier::class, ['record' => $supplier->getRouteKey()])->assertForbidden();

    // Positive control: peran berizin kelola benar-benar menyimpan.
    $this->actingAs(penggunaSupplier('inventory'));
    Livewire::test(CreateSupplier::class)
        ->fillForm(['name' => 'Toko Sentosa', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Supplier::query()->where('name', 'Toko Sentosa')->exists())->toBeTrue();
});

it('menyembunyikan tombol tambah supplier di form transaksi bagi yang tidak berizin kelola supplier', function () {
    $item = InventoryItem::query()->create(['name' => 'Gula', 'unit' => 'kg', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);
    $purchase = InventoryPurchase::query()->create([
        'inventory_item_id' => $item->id,
        'transaction_date' => now()->toDateString(),
        'qty' => 1,
        'unit_cost' => 1000,
        'payment_type' => 'cash',
    ]);

    // Positive control: akunting (inventory.manage) melihat dan bisa memakai tombolnya.
    Livewire::actingAs(penggunaSupplier('accounting'))
        ->test(EditInventoryPurchase::class, ['record' => $purchase->getRouteKey()])
        ->assertFormComponentActionVisible('supplier_id', 'createOption')
        ->callFormComponentAction('supplier_id', 'createOption', ['name' => 'Toko Cepat'])
        ->assertHasNoFormComponentActionErrors();

    expect(Supplier::query()->where('name', 'Toko Cepat')->exists())->toBeTrue();

    // Pemilik mencabut izin kelola dari akunting: halamannya tertutup
    // sepenuhnya (policy update), jadi tombolnya ikut tidak terjangkau.
    Role::findByName('accounting', 'web')->revokePermissionTo('inventory.manage');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Livewire::actingAs(penggunaSupplier('accounting'))
        ->test(EditInventoryPurchase::class, ['record' => $purchase->getRouteKey()])
        ->assertForbidden();
});

it('menyembunyikan tombol tambah supplier di form kebutuhan bagi pemeriksa tanpa izin kelola inventory', function () {
    $order = ProductionOrder::query()->create(['production_date' => now()->toDateString(), 'status' => ProductionOrder::STATUS_PLANNED]);
    Requisition::query()->create(['production_order_id' => $order->id, 'status' => Requisition::STATUS_APPROVED, 'payment_type' => 'payable']);

    // Positive control: staf gudang (requisition.check + inventory.manage) melihat tombolnya.
    Livewire::actingAs(penggunaSupplier('inventory'))
        ->test(RequisitionForm::class, ['record' => $order->id])
        ->assertFormFieldExists('supplier_id')
        ->assertFormComponentActionVisible('supplier_id', 'createOption');

    // Izin kelola dicabut, izin Periksa tetap: form masih bisa diisi, tetapi
    // tidak bisa menambah supplier baru dari situ.
    Role::findByName('inventory', 'web')->revokePermissionTo('inventory.manage');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Livewire::actingAs(penggunaSupplier('inventory'))
        ->test(RequisitionForm::class, ['record' => $order->id])
        ->assertFormFieldExists('supplier_id')
        ->assertFormComponentActionHidden('supplier_id', 'createOption');
});
