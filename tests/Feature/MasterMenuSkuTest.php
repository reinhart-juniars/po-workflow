<?php

use App\Filament\Menu\Resources\MasterMenuResource\Pages\EditMasterMenu;
use App\Models\Product;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use Livewire\Livewire;

/**
 * Revisi klien: SKU Master Menu diketik manual, tidak dibuat dari nama,
 * karena SKU tampil sebagai nama menu di website. Sejak 9 Nov 2026 SKU diatur
 * tim menu di aplikasi Menu › Master Menu (admin hanya mengisi harga).
 */
function timMenuSku(): User
{
    ModuleAccess::sync();
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole('menu');

    return $user;
}

function menuSku(array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'name' => 'Nasi Capjay', 'sku' => 'NASI-CAPJAY-12K', 'unit' => 'porsi', 'base_price' => 12000, 'active' => true,
    ], $overrides));
}

it('menyimpan SKU yang diketik tim menu, dirapikan spasinya tanpa mengubah huruf', function () {
    $product = menuSku();

    Livewire::actingAs(timMenuSku())
        ->test(EditMasterMenu::class, ['record' => $product->getRouteKey()])
        ->fillForm(['sku' => '  Nasi Capjay   Seafood '])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->sku)->toBe('Nasi Capjay Seafood')
        ->and($product->fresh()->name)->toBe('NASI CAPJAY');
});

it('mewajibkan SKU dan menolak SKU yang sudah dipakai menu lain, tapi tidak bentrok dengan dirinya sendiri', function () {
    $user = timMenuSku();
    menuSku(['name' => 'Nasi Goreng', 'sku' => 'Nasi Goreng Kampung']);
    $product = menuSku();

    Livewire::actingAs($user)->test(EditMasterMenu::class, ['record' => $product->getRouteKey()])
        ->fillForm(['sku' => ''])
        ->call('save')
        ->assertHasFormErrors(['sku' => 'required']);

    Livewire::actingAs($user)->test(EditMasterMenu::class, ['record' => $product->getRouteKey()])
        ->fillForm(['sku' => 'Nasi Goreng Kampung'])
        ->call('save')
        ->assertHasFormErrors(['sku' => 'unique']);

    // Kontrol positif: simpan ulang dengan SKU sendiri tidak dianggap duplikat.
    Livewire::actingAs($user)->test(EditMasterMenu::class, ['record' => $product->getRouteKey()])
        ->fillForm(['sku' => 'NASI-CAPJAY-12K', 'unit' => 'box'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->sku)->toBe('NASI-CAPJAY-12K')
        ->and($product->fresh()->unit)->toBe('box');
});
