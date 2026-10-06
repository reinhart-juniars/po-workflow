<?php

use App\Imports\ProductsImport;
use App\Models\Product;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Revisi klien: SKU Master Menu diketik manual, tidak dibuat otomatis dari
 * nama, karena SKU tampil sebagai nama menu di website.
 */
function adminMasterMenu(): User
{
    Role::findOrCreate('admin', 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole('admin');

    return $user;
}

function formMenu(array $overrides = []): array
{
    return array_merge([
        'sku' => 'Nasi Goreng Kampung',
        'name' => 'Nasi Goreng 15K',
        'unit' => 'porsi',
        'base_price' => 15000,
        'active' => 1,
    ], $overrides);
}

it('menyimpan SKU persis seperti yang diketik admin, bukan hasil dari nama menu', function () {
    $this->actingAs(adminMasterMenu())
        ->post(route('adminapp.products.store'), formMenu(['sku' => '  Nasi Goreng   Kampung ']))
        ->assertRedirect(route('adminapp.products.index'))
        ->assertSessionHasNoErrors();

    $product = Product::query()->sole();
    // Spasi dirapikan, huruf besar/kecil dipertahankan; nama internal tetap kapital.
    expect($product->sku)->toBe('Nasi Goreng Kampung')
        ->and($product->name)->toBe('NASI GORENG 15K');
});

it('mewajibkan SKU dan menolak SKU yang sudah dipakai', function () {
    $admin = adminMasterMenu();

    $this->actingAs($admin)->post(route('adminapp.products.store'), formMenu(['sku' => '']))
        ->assertSessionHasErrors(['sku' => 'SKU wajib diisi. SKU dipakai sebagai nama menu di website.']);
    $this->actingAs($admin)->post(route('adminapp.products.store'), formMenu(['sku' => '   ']))
        ->assertSessionHasErrors('sku');
    expect(Product::query()->count())->toBe(0);

    // Kontrol positif, lalu SKU yang sama untuk menu lain ditolak.
    $this->actingAs($admin)->post(route('adminapp.products.store'), formMenu())->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('adminapp.products.store'), formMenu(['name' => 'Nasi Goreng 20K']))
        ->assertSessionHasErrors(['sku' => 'SKU ini sudah dipakai menu lain.']);
    expect(Product::query()->count())->toBe(1);
});

it('membolehkan admin mengganti SKU menu lama dan menyimpan ulang tanpa bentrok dengan dirinya sendiri', function () {
    $admin = adminMasterMenu();
    $product = Product::query()->create(['name' => 'Nasi Capjay', 'sku' => 'NASI-CAPJAY-12K', 'unit' => 'porsi', 'base_price' => 12000, 'active' => true]);

    // Simpan tanpa mengubah SKU: tidak dianggap duplikat dirinya sendiri.
    $this->actingAs($admin)->put(route('adminapp.products.update', $product), formMenu(['sku' => 'NASI-CAPJAY-12K', 'name' => 'Nasi Capjay', 'base_price' => 12000]))
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)->put(route('adminapp.products.update', $product), formMenu(['sku' => 'Nasi Capjay Seafood', 'name' => 'Nasi Capjay', 'base_price' => 12000]))
        ->assertSessionHasNoErrors();
    expect($product->fresh()->sku)->toBe('Nasi Capjay Seafood');
});

it('menampilkan kolom SKU yang bisa diketik di form menu', function () {
    $this->actingAs(adminMasterMenu())->get(route('adminapp.products.create'))
        ->assertOk()
        ->assertSee('name="sku"', false)
        ->assertSee('SKU tampil sebagai nama menu di website')
        ->assertDontSee('SKU dibuat otomatis');
});

it('import master menu: menu baru wajib membawa SKU, menu lama dicocokkan tanpa peduli huruf besar/kecil', function () {
    $lama = Product::query()->create(['name' => 'Rawon', 'sku' => 'Rawon Daging', 'unit' => 'porsi', 'base_price' => 20000, 'active' => true]);

    // Baris baru tanpa SKU ditolak -- sistem tidak lagi mengarang SKU.
    $import = new ProductsImport(commit: false);
    expect(fn () => $import->collection(collect([
        collect(['nama_menu' => 'Soto Ayam', 'satuan' => 'porsi', 'harga_jual' => 18000, 'sku' => null]),
    ])))->toThrow(RuntimeException::class, 'kolom SKU wajib diisi untuk menu baru');

    // SKU kapital di file lama tetap menemukan menu yang sama; sel SKU kosong
    // pada menu lama (dicocokkan lewat id) membiarkan SKU-nya.
    $import = new ProductsImport;
    $import->collection(collect([
        collect(['sku' => 'RAWON DAGING', 'nama_menu' => 'Rawon', 'satuan' => 'porsi', 'harga_jual' => 22000]),
        collect(['sku' => 'Soto Ayam Lamongan', 'nama_menu' => 'Soto Ayam', 'satuan' => 'porsi', 'harga_jual' => 18000]),
    ]));

    expect($import->summary())->toMatchArray(['created' => 1, 'updated' => 1])
        ->and((float) $lama->fresh()->base_price)->toBe(22000.0)
        ->and(Product::query()->where('sku', 'Soto Ayam Lamongan')->exists())->toBeTrue();

    (new ProductsImport)->collection(collect([
        collect(['id' => $lama->id, 'sku' => null, 'nama_menu' => 'Rawon', 'satuan' => 'porsi', 'harga_jual' => 23000]),
    ]));
    expect($lama->fresh()->sku)->toBe('RAWON DAGING');
});
