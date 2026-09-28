<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Support\Navigation;
use Spatie\Permission\Models\Role;

/**
 * Revisi klien setelah presentasi v3.1:
 * - Katalog Foto Menu punya tab aplikasi sendiri (Marketing), bukan menu di Admin/Sales.
 * - Tiap menu bisa dicentang "Tampil di website"; SKU = nama menu di website,
 *   jadi menu tanpa SKU tidak bisa dicentang.
 */
function penggunaMarketing(string ...$roles): User
{
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);

    foreach ($roles as $role) {
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);
    }

    return $user;
}

function menuKatalog(array $attributes = []): Product
{
    return Product::query()->create(array_merge([
        'name' => 'Nasi Kuning',
        'sku' => 'Nasi Kuning Spesial',
        'unit' => 'porsi',
        'base_price' => 15000,
        'active' => true,
    ], $attributes));
}

it('membuka katalog hanya untuk marketing, owner, dan superadmin', function () {
    menuKatalog();

    // Positif: marketing & owner membuka dashboard dan katalog.
    foreach (['marketing', 'owner'] as $role) {
        $user = penggunaMarketing($role);
        $this->actingAs($user)->get(route('marketingapp.dashboard'))->assertOk()->assertSee('Dashboard Marketing');
        $this->actingAs($user)->get(route('marketingapp.catalog.index'))->assertOk()->assertSee('Nasi Kuning Spesial');
    }

    // Negatif: admin dan sales tidak lagi membuka katalog, juga tidak bisa mencentang.
    $product = menuKatalog(['name' => 'Es Teh', 'sku' => 'Es Teh Manis']);
    foreach (['admin', 'sales'] as $role) {
        $user = penggunaMarketing($role);
        $this->actingAs($user)->get(route('marketingapp.catalog.index'))->assertForbidden();
        $this->actingAs($user)->patch(route('marketingapp.catalog.website', $product), ['show_on_website' => 1])->assertForbidden();
    }
    expect($product->fresh()->show_on_website)->toBeFalse();

    // Bookmark lama diarahkan ke tempat baru.
    $this->actingAs(penggunaMarketing('admin'))->get('/admin-app/catalog')->assertRedirect(route('marketingapp.catalog.index'));
    $this->actingAs(penggunaMarketing('sales'))->get('/sales-app/catalog')->assertRedirect(route('marketingapp.catalog.index'));
});

it('menampilkan tab Marketing di bilah atas untuk peran marketing saja', function () {
    $marketing = penggunaMarketing('marketing');
    expect(collect(Navigation::tabs($marketing))->pluck('key')->all())->toBe(['marketing'])
        ->and(Navigation::dashboardUrl($marketing))->toBe(route('marketingapp.dashboard'));

    $this->actingAs($marketing)->get(route('marketingapp.catalog.index'))
        ->assertOk()->assertSee('Katalog Foto Menu')->assertDontSee(route('adminapp.dashboard'), false);

    // Katalog tidak lagi ada di sidebar Admin maupun Sales.
    $owner = penggunaMarketing('owner');
    $labels = fn (string $app) => collect(Navigation::sidebar($app, $owner))->flatMap(fn ($section) => collect($section['items'])->pluck('label'))->all();
    expect($labels('marketing'))->toContain('Katalog Foto Menu')
        ->and($labels('admin'))->not->toContain('Katalog Foto Menu')
        ->and($labels('sales'))->not->toContain('Katalog Foto Menu');

    expect(User::manageableRoles())->toContain('marketing');
});

it('mencentang menu yang tampil di website dan mencatatnya di audit log', function () {
    $marketing = penggunaMarketing('marketing');
    $product = menuKatalog();
    $lain = menuKatalog(['name' => 'Es Teh', 'sku' => 'Es Teh Manis']);

    // Bawaan: tidak tampil.
    expect($product->show_on_website)->toBeFalse()
        ->and(Product::query()->onWebsite()->count())->toBe(0);

    // Centang lewat fetch (JSON).
    $this->actingAs($marketing)
        ->patchJson(route('marketingapp.catalog.website', $product), ['show_on_website' => 1])
        ->assertOk()
        ->assertJson(['show_on_website' => true, 'on_website' => 1]);

    expect($product->fresh()->show_on_website)->toBeTrue()
        ->and(Product::query()->onWebsite()->pluck('sku')->all())->toBe(['Nasi Kuning Spesial'])
        ->and(AuditLog::query()->where('action', 'website_shown')->where('entity_id', $product->id)->exists())->toBeTrue();

    // Filter katalog.
    $this->actingAs($marketing)->get(route('marketingapp.catalog.index', ['website' => 'tampil']))
        ->assertSee('Nasi Kuning Spesial')->assertDontSee('Es Teh Manis');
    $this->actingAs($marketing)->get(route('marketingapp.catalog.index', ['website' => 'tidak']))
        ->assertSee('Es Teh Manis')->assertDontSee('Nasi Kuning Spesial');

    // Hapus centang lewat form biasa (tanpa JS): hidden 0 terkirim.
    $this->actingAs($marketing)
        ->patch(route('marketingapp.catalog.website', $product), ['show_on_website' => 0])
        ->assertRedirect();
    expect($product->fresh()->show_on_website)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'website_hidden')->exists())->toBeTrue();
    expect($lain->fresh()->show_on_website)->toBeFalse();
});

it('menolak mencentang menu tanpa SKU karena SKU adalah nama menunya di website', function () {
    $marketing = penggunaMarketing('marketing');
    $tanpaSku = menuKatalog(['name' => 'Menu Lama', 'sku' => null]);
    $denganSku = menuKatalog(['name' => 'Rawon', 'sku' => 'Rawon Daging']);

    $this->actingAs($marketing)
        ->patchJson(route('marketingapp.catalog.website', $tanpaSku), ['show_on_website' => 1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('show_on_website');
    expect($tanpaSku->fresh()->show_on_website)->toBeFalse();

    // Kontrol positif: menu ber-SKU di request yang sama bentuknya lolos.
    $this->actingAs($marketing)
        ->patchJson(route('marketingapp.catalog.website', $denganSku), ['show_on_website' => 1])
        ->assertOk();
    expect($denganSku->fresh()->show_on_website)->toBeTrue();

    // Kartu menu tanpa SKU menampilkan petunjuk, bukan kotak centang aktif.
    $this->actingAs($marketing)->get(route('marketingapp.catalog.index'))->assertSee('Isi SKU dulu untuk tampil di website');
});

it('hanya menerbitkan menu aktif ke website walau masih tercentang', function () {
    $product = menuKatalog(['show_on_website' => true]);
    expect(Product::query()->onWebsite()->count())->toBe(1);

    $product->update(['active' => false]);
    expect(Product::query()->onWebsite()->count())->toBe(0);
});

it('menghitung menu website tanpa foto di dashboard marketing', function () {
    menuKatalog(['show_on_website' => true]);
    menuKatalog(['name' => 'Rawon', 'sku' => 'Rawon Daging', 'show_on_website' => true, 'photo_path' => 'menu-photos/rawon.jpg']);

    $this->actingAs(penggunaMarketing('marketing'))->get(route('marketingapp.dashboard'))
        ->assertOk()
        ->assertSee('Perlu Foto Dulu')
        ->assertSee('Nasi Kuning Spesial')
        ->assertDontSee('Rawon Daging');
});

function loncengSku(User $user): array
{
    return \Illuminate\Notifications\DatabaseNotification::query()
        ->where('notifiable_id', $user->id)
        ->get()
        ->map(fn ($n) => $n->data['title'])
        ->all();
}

it('membolehkan marketing mengganti SKU langsung dari katalog dengan aturan yang sama seperti Master Menu', function () {
    $marketing = penggunaMarketing('marketing');
    $product = menuKatalog();
    menuKatalog(['name' => 'Es Teh', 'sku' => 'Es Teh Manis']);

    $this->actingAs($marketing)
        ->patchJson(route('marketingapp.catalog.sku', $product), ['sku' => '  Nasi Kuning   Komplit '])
        ->assertOk()
        ->assertJson(['sku' => 'Nasi Kuning Komplit']);
    expect($product->fresh()->sku)->toBe('Nasi Kuning Komplit')
        ->and(AuditLog::query()->where('action', 'sku_changed')->where('entity_id', $product->id)->value('message'))
        ->toBe('SKU menu NASI KUNING: Nasi Kuning Spesial → Nasi Kuning Komplit');

    // Kosong dan SKU milik menu lain ditolak; SKU tidak berubah.
    $this->actingAs($marketing)->patchJson(route('marketingapp.catalog.sku', $product), ['sku' => '   '])
        ->assertUnprocessable()->assertJsonValidationErrors('sku');
    $this->actingAs($marketing)->patchJson(route('marketingapp.catalog.sku', $product), ['sku' => 'Es Teh Manis'])
        ->assertUnprocessable()->assertJsonPath('errors.sku.0', 'SKU ini sudah dipakai menu lain.');
    expect($product->fresh()->sku)->toBe('Nasi Kuning Komplit');

    // Admin mengganti lewat Master Menu, bukan lewat route katalog; sales tidak sama sekali.
    foreach (['admin', 'sales'] as $role) {
        $this->actingAs(penggunaMarketing($role))
            ->patch(route('marketingapp.catalog.sku', $product), ['sku' => 'Dibajak'])
            ->assertForbidden();
    }
    expect($product->fresh()->sku)->toBe('Nasi Kuning Komplit');

    $this->actingAs($marketing)->get(route('marketingapp.catalog.index'))
        ->assertSee(route('marketingapp.catalog.sku', $product), false);
});

it('memberi lonceng ke admin saat marketing mengganti SKU menu yang tampil di website, dan sebaliknya', function () {
    $marketing = penggunaMarketing('marketing');
    $marketingLain = penggunaMarketing('marketing');
    $admin = penggunaMarketing('admin');
    $sales = penggunaMarketing('sales');
    $product = menuKatalog(['show_on_website' => true]);

    // Marketing mengganti dari katalog -> admin (dan marketing lain) diberi tahu; pelaku dan sales tidak.
    $this->actingAs($marketing)->patchJson(route('marketingapp.catalog.sku', $product), ['sku' => 'Nasi Kuning Komplit'])->assertOk();

    expect(loncengSku($admin))->toBe(['Nama menu di website diganti: Nasi Kuning Komplit'])
        ->and(loncengSku($marketingLain))->toHaveCount(1)
        ->and(loncengSku($marketing))->toBe([])
        ->and(loncengSku($sales))->toBe([]);

    // Admin mengganti dari Master Menu -> marketing diberi tahu; admin (pelaku) tidak bertambah.
    $this->actingAs($admin)->put(route('adminapp.products.update', $product), [
        'sku' => 'Nasi Kuning Istimewa', 'name' => 'Nasi Kuning', 'unit' => 'porsi', 'base_price' => 15000, 'active' => 1,
    ])->assertSessionHasNoErrors();

    expect(loncengSku($marketing))->toBe(['Nama menu di website diganti: Nasi Kuning Istimewa'])
        ->and(loncengSku($admin))->toHaveCount(1);
});

it('tidak membunyikan lonceng untuk menu yang belum tampil di website, tapi tetap mencatat audit log', function () {
    $marketing = penggunaMarketing('marketing');
    $admin = penggunaMarketing('admin');
    $product = menuKatalog(['show_on_website' => false]);

    $this->actingAs($marketing)->patchJson(route('marketingapp.catalog.sku', $product), ['sku' => 'Nasi Kuning Komplit'])->assertOk();

    expect(loncengSku($admin))->toBe([])
        ->and(AuditLog::query()->where('action', 'sku_changed')->where('entity_id', $product->id)->exists())->toBeTrue();

    // Kontrol positif: begitu tampil di website, penggantian berikutnya membunyikan lonceng.
    $product->update(['show_on_website' => true]);
    $this->actingAs($marketing)->patchJson(route('marketingapp.catalog.sku', $product), ['sku' => 'Nasi Kuning Lengkap'])->assertOk();
    expect(loncengSku($admin))->toBe(['Nama menu di website diganti: Nasi Kuning Lengkap']);
});
