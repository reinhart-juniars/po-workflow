<?php

use App\Console\Commands\DeactivateUnusedMenusCommand;
use App\Filament\Menu\Resources\MasterMenuResource;
use App\Filament\Menu\Resources\MasterMenuResource\Pages\EditMasterMenu;
use App\Filament\Menu\Resources\RecipeResource\Pages\CreateRecipe;
use App\Filament\Menu\Resources\RecipeResource\Pages\EditRecipe;
use App\Models\Area;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Recipe;
use App\Models\User;
use App\Services\MasterMenuSync;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Revisi 9 Nov 2026: Master Menu mengikuti Menu Utama tim menu.
 *
 * - Menu Utama baru (atau lama yang disimpan) tanpa Master Menu dibuatkan satu.
 * - Admin tidak membuat menu; di Admin › Harga Menu hanya mengisi Harga Non Lapak.
 * - Menu yang belum punya resep tidak bisa dipesan di PO, kecuali ditandai
 *   tim menu memang tanpa resep.
 * - Menu tanpa resep yang 90 hari tidak dipakai dinonaktifkan otomatis.
 */
function penggunaPeranMenu(string $role): User
{
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

function resepUtama(array $extra = []): Recipe
{
    return Recipe::query()->create($extra + [
        'name' => 'Nasi Ayam Teriyaki Box WP', 'jenis' => Recipe::JENIS_UTAMA,
        'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25, 'is_active' => true,
    ]);
}

function formPoMenu(array $items): array
{
    $area = Area::query()->firstOrCreate(['code' => 'MTM'], ['name' => 'Area Menu']);
    $customer = Customer::query()->firstOrCreate(['name' => 'Bu Sari'], ['area_id' => $area->id, 'active' => true]);
    $cash = CashAccount::query()->firstOrCreate(['name' => 'Kas Menu'], ['type' => 'cash', 'is_active' => true]);

    return [
        'customer_id' => $customer->id, 'recipient_name' => 'Penerima', 'shipping_address' => 'Jl. Melati 2',
        'area_id' => $area->id, 'delivery_date' => '2026-11-12', 'delivery_time' => '09:00',
        'payment_type' => 'cash', 'cash_account_id' => $cash->id, 'shipping_cost' => 0,
        'items' => $items,
    ];
}

it('membuatkan Master Menu saat tim menu membuat Menu Utama, dan memberi tahu admin', function () {
    $timMenu = penggunaPeranMenu('menu');
    $admin = penggunaPeranMenu('admin');

    Livewire::actingAs($timMenu)->test(CreateRecipe::class)
        ->fillForm(['name' => 'Nasi Ayam Teriyaki + Telur + Box WP', 'jenis' => Recipe::JENIS_UTAMA, 'yield_qty' => 1, 'yield_unit' => 'porsi', 'target_price' => 18000])
        ->call('create')
        ->assertHasNoFormErrors();

    $recipe = Recipe::query()->where('name', 'Nasi Ayam Teriyaki + Telur + Box WP')->sole();
    $product = Product::query()->where('recipe_id', $recipe->id)->sole();

    expect($product->name)->toBe('NASI AYAM TERIYAKI + TELUR + BOX WP')
        ->and($product->sku)->toBe('Nasi Ayam Teriyaki + Telur + Box WP')
        ->and($product->origin)->toBe(Product::ORIGIN_MENU)
        ->and($product->active)->toBeTrue()
        ->and((float) $product->base_price)->toBe(0.0)
        ->and($product->isOrderable())->toBeTrue();

    $judul = DatabaseNotification::query()->where('notifiable_id', $admin->id)->pluck('data')->pluck('title')->all();
    expect($judul)->toBe(['Menu baru dari tim menu: NASI AYAM TERIYAKI + TELUR + BOX WP'])
        ->and(DatabaseNotification::query()->where('notifiable_id', $timMenu->id)->count())->toBe(0);
});

it('tidak membuat Master Menu untuk sub-menu atau Menu Utama yang sudah ditautkan ke menu lama', function () {
    $timMenu = penggunaPeranMenu('menu');
    $lama = Product::query()->create(['name' => 'NASI AYAM TERIYAKI BOX WP', 'sku' => 'NASI AYAM TERIYAKI BOX WP', 'unit' => 'porsi', 'base_price' => 25000, 'active' => true]);

    Livewire::actingAs($timMenu)->test(CreateRecipe::class)
        ->fillForm(['name' => 'Saos Teriyaki', 'jenis' => Recipe::JENIS_SUB, 'yield_qty' => 1, 'yield_unit' => 'porsi'])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::actingAs($timMenu)->test(CreateRecipe::class)
        ->fillForm(['name' => 'Nasi Teriyaki Box', 'jenis' => Recipe::JENIS_UTAMA, 'yield_qty' => 1, 'yield_unit' => 'porsi', 'product_ids' => [$lama->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::query()->count())->toBe(1)
        ->and($lama->fresh()->recipe_id)->toBe(Recipe::query()->where('name', 'Nasi Teriyaki Box')->value('id'));
});

it('membuatkan Master Menu saat Menu Utama lama tanpa menu disimpan ulang', function () {
    $recipe = resepUtama();
    expect(Product::query()->count())->toBe(0);

    Livewire::actingAs(penggunaPeranMenu('menu'))
        ->test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Product::query()->where('recipe_id', $recipe->id)->count())->toBe(1);

    // Disimpan lagi: tidak dobel.
    Livewire::actingAs(penggunaPeranMenu('menu'))
        ->test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->call('save');
    expect(Product::query()->count())->toBe(1);
});

it('mengikutkan nama dan status aktif menu jual pada resepnya', function () {
    $recipe = resepUtama();
    $menu = app(MasterMenuSync::class)->ensureFor($recipe);

    $recipe->update(['name' => 'Nasi Chicken Teriyaki Box WP']);
    expect($menu->fresh()->name)->toBe('NASI CHICKEN TERIYAKI BOX WP');

    $recipe->update(['is_active' => false]);
    expect($menu->fresh()->active)->toBeFalse()
        ->and($menu->fresh()->isOrderable())->toBeFalse();

    // Disimpan tanpa mengubah status: menu yang sengaja dinonaktifkan tetap nonaktif.
    $recipe->update(['is_active' => true]);
    $menu->fresh()->update(['active' => false]);
    $recipe->update(['notes' => 'catatan baru']);
    expect($menu->fresh()->active)->toBeFalse();
});

it('memberi nomor pada SKU menu baru bila namanya sudah dipakai', function () {
    Product::query()->create(['name' => 'SOTO AYAM LAMA', 'sku' => 'Soto Ayam', 'unit' => 'porsi', 'base_price' => 0, 'active' => true]);

    $product = app(MasterMenuSync::class)->ensureFor(resepUtama(['name' => 'Soto Ayam']));

    expect($product->sku)->toBe('Soto Ayam (2)');
});

it('hanya menerima menu jual Menu Utama di PO', function () {
    $admin = penggunaPeranMenu('admin');
    $sisaV2 = Product::query()->create(['name' => 'DONAT 3K', 'sku' => 'DONAT 3K', 'unit' => 'pcs', 'base_price' => 3000, 'active' => true]);
    $hargaUp = Product::query()->create(['name' => 'HARGA UP 2K', 'sku' => 'HARGA UP 2K', 'unit' => 'pcs', 'base_price' => 2000, 'active' => true, 'needs_recipe' => false]);
    $menu = app(MasterMenuSync::class)->ensureFor(resepUtama());
    $menu->update(['base_price' => 25000]);

    $this->actingAs($admin)->get(route('adminapp.orders.index'))->assertOk()
        ->assertSee('NASI AYAM TERIYAKI BOX WP')
        ->assertDontSee('DONAT 3K')
        ->assertDontSee('HARGA UP 2K');

    foreach ([$sisaV2, $hargaUp] as $product) {
        $this->actingAs($admin)->post(route('adminapp.orders.store'), formPoMenu([['product_id' => $product->id, 'qty' => 10]]))
            ->assertSessionHasErrors(['items.0.product_id' => 'Menu '.$product->name.' tidak ada di Menu Utama tim menu, jadi tidak bisa dipesan. Pilih menu dari daftar.']);
    }
    expect(PurchaseOrder::query()->count())->toBe(0);

    // Kontrol positif: menu jual Menu Utama lolos.
    $this->actingAs($admin)->post(route('adminapp.orders.store'), formPoMenu([['product_id' => $menu->id, 'qty' => 10]]))
        ->assertSessionHasNoErrors();
    expect(PurchaseOrder::query()->sole()->items()->sole()->product_id)->toBe($menu->id);
});

it('tetap bisa mengedit PO lama yang memuat menu yang kini tidak bisa dipesan', function () {
    $admin = penggunaPeranMenu('admin');
    $recipe = resepUtama(['name' => 'Donat Gula']);
    $donat = app(MasterMenuSync::class)->ensureFor($recipe);
    $lain = Product::query()->create(['name' => 'BOLU 5K', 'sku' => 'BOLU 5K', 'unit' => 'pcs', 'base_price' => 5000, 'active' => true]);

    $this->actingAs($admin)->post(route('adminapp.orders.store'), formPoMenu([['product_id' => $donat->id, 'qty' => 10]]))->assertSessionHasNoErrors();
    $po = PurchaseOrder::query()->sole();

    // Tim menu menonaktifkan resepnya: menu tidak bisa dipesan di PO baru.
    $recipe->update(['is_active' => false]);
    expect($donat->fresh()->isOrderable())->toBeFalse();

    $this->actingAs($admin)->get(route('adminapp.orders.edit', $po))->assertOk()->assertSee('DONAT GULA');
    $this->actingAs($admin)->put(route('adminapp.orders.update', $po), formPoMenu([['product_id' => $donat->id, 'qty' => 12]]) + ['edit_reason' => 'Tambah porsi'])
        ->assertSessionHasNoErrors();
    expect($po->fresh()->items()->sole()->qty)->toBe(12);

    // Menu di luar Menu Utama yang belum ada di PO tetap ditolak.
    $this->actingAs($admin)->put(route('adminapp.orders.update', $po), formPoMenu([['product_id' => $lain->id, 'qty' => 1]]) + ['edit_reason' => 'Ganti menu'])
        ->assertSessionHasErrors('items.0.product_id');
});

it('membatasi Harga Menu admin pada Harga Non Lapak: tidak ada tambah menu atau impor', function () {
    $admin = penggunaPeranMenu('admin');
    $menu = app(MasterMenuSync::class)->ensureFor(resepUtama());

    $this->actingAs($admin)->get(route('adminapp.products.index'))->assertOk()
        ->assertSee('Harga Menu')
        ->assertSee('Harga belum diisi')
        ->assertDontSee('Tambah Menu Baru')
        ->assertDontSee('Import Excel');

    expect(\Illuminate\Support\Facades\Route::has('adminapp.products.create'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Route::has('adminapp.products.store'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Route::has('adminapp.products.import.excel'))->toBeFalse();
    $this->actingAs($admin)->post('/admin-app/products', ['name' => 'MENU ADMIN', 'sku' => 'X', 'unit' => 'porsi', 'base_price' => 1])
        ->assertStatus(405);
    expect(Product::query()->count())->toBe(1);

    $this->actingAs($admin)->put(route('adminapp.products.update', $menu), ['base_price' => 25000, 'name' => 'DIGANTI ADMIN'])
        ->assertSessionHasNoErrors();
    expect((float) $menu->fresh()->base_price)->toBe(25000.0)
        ->and($menu->fresh()->name)->toBe('NASI AYAM TERIYAKI BOX WP');
});

it('membuka Master Menu di aplikasi Menu: tim menu mengubah, admin hanya melihat, sales tidak masuk', function () {
    $menu = app(MasterMenuSync::class)->ensureFor(resepUtama());

    $this->actingAs(penggunaPeranMenu('menu'))->get(MasterMenuResource::getUrl('edit', ['record' => $menu]))->assertOk();
    $this->actingAs(penggunaPeranMenu('admin'))->get(MasterMenuResource::getUrl('index'))->assertOk()->assertSee('NASI AYAM TERIYAKI BOX WP');
    $this->actingAs(penggunaPeranMenu('admin'))->get(MasterMenuResource::getUrl('edit', ['record' => $menu]))->assertForbidden();
    $this->actingAs(penggunaPeranMenu('sales'))->get(MasterMenuResource::getUrl('index'))->assertForbidden();

    // Nama ikut resep dan terkunci; harga non lapak tidak ada di form tim menu.
    Livewire::actingAs(penggunaPeranMenu('menu'))
        ->test(EditMasterMenu::class, ['record' => $menu->getRouteKey()])
        ->assertFormFieldIsDisabled('name')
        ->assertFormFieldExists('lapak_price')
        ->assertFormFieldDoesNotExist('base_price');
});

it('menonaktifkan menu tanpa resep yang 90 hari tidak dipakai, dan membiarkan sisanya', function () {
    Carbon::setTestNow('2026-11-09 05:45:00');
    $lama = Carbon::parse('2026-07-01');

    $buat = function (string $name, array $extra = []) use ($lama) {
        $product = Product::query()->create($extra + ['name' => $name, 'sku' => $name, 'unit' => 'porsi', 'base_price' => 10000, 'active' => true]);
        $product->forceFill(['created_at' => $lama, 'updated_at' => $lama])->saveQuietly();

        return $product;
    };

    $mati = $buat('MENU V2 TIDUR');
    $dipesan = $buat('MENU V2 LARIS');
    $beresep = $buat('MENU BERESEP', ['recipe_id' => resepUtama()->id]);
    $beresep->forceFill(['updated_at' => $lama])->saveQuietly();
    $baruDiubah = $buat('MENU BARU DIAKTIFKAN');
    $baruDiubah->forceFill(['updated_at' => Carbon::parse('2026-10-30')])->saveQuietly();

    // PO lama yang memakai MENU V2 LARIS (tanggal kirim dalam 90 hari).
    formPoMenu([]);
    $po = PurchaseOrder::query()->create([
        'customer_id' => Customer::query()->value('id'), 'recipient_name' => 'Penerima', 'shipping_address' => 'Jl.',
        'area_id' => Area::query()->value('id'), 'delivery_date' => '2026-09-01', 'delivery_time' => '09:00:00', 'payment_type' => 'cash', 'status' => 'completed', 'created_by' => User::factory()->create()->id,
    ]);
    $po->items()->create(['product_id' => $dipesan->id, 'qty' => 1, 'unit' => 'porsi']);
    $po->forceFill(['created_at' => '2026-08-30 10:00:00'])->saveQuietly();

    // Uji-jalan tidak mengubah apa pun.
    $this->artisan('menu:deactivate-unused', ['--dry-run' => true])->assertSuccessful();
    expect($mati->fresh()->active)->toBeTrue();

    $this->artisan('menu:deactivate-unused')->assertSuccessful();

    expect($mati->fresh()->active)->toBeFalse()
        ->and($dipesan->fresh()->active)->toBeTrue()
        ->and($beresep->fresh()->active)->toBeTrue()
        ->and($baruDiubah->fresh()->active)->toBeTrue()
        ->and(DeactivateUnusedMenusCommand::IDLE_DAYS)->toBe(90);

    Carbon::setTestNow();
});

it('menyamakan seluruh Master Menu dengan Menu Utama lewat menu:sync-from-recipes', function () {
    $admin = penggunaPeranMenu('admin');

    // Resep bertaut dua varian V2: yang paling sering dipesan jadi menu jualnya.
    $koloke = resepUtama(['name' => 'Nasi Koloke']);
    $koloke12 = Product::query()->create(['name' => 'NASI KOLOKE 12K', 'sku' => 'Nasi Koloke 12K', 'unit' => 'porsi', 'base_price' => 12000, 'active' => true, 'recipe_id' => $koloke->id]);
    $koloke15 = Product::query()->create(['name' => 'NASI KOLOKE 15K', 'sku' => 'Nasi Koloke', 'unit' => 'porsi', 'base_price' => 15000, 'active' => true, 'recipe_id' => $koloke->id]);
    formPoMenu([]);
    $po = PurchaseOrder::query()->create([
        'customer_id' => Customer::query()->value('id'), 'recipient_name' => 'Penerima', 'shipping_address' => 'Jl.',
        'area_id' => Area::query()->value('id'), 'delivery_date' => now()->subDays(3)->toDateString(), 'delivery_time' => '09:00:00', 'payment_type' => 'cash', 'status' => 'completed', 'created_by' => User::factory()->create()->id,
    ]);
    $po->items()->create(['product_id' => $koloke15->id, 'qty' => 5, 'unit' => 'porsi']);
    $po->items()->create(['product_id' => $koloke15->id, 'qty' => 2, 'unit' => 'porsi']);
    $po->items()->create(['product_id' => $koloke12->id, 'qty' => 1, 'unit' => 'porsi']);

    // Menu V2 tanpa resep bernama sama: ditautkan, harganya terbawa.
    $ladaHitam = Product::query()->create(['name' => 'NASI AYAM LADA HITAM', 'sku' => 'NASI AYAM LADA HITAM', 'unit' => 'porsi', 'base_price' => 14000, 'active' => true]);
    $resepLada = resepUtama(['name' => 'Nasi Ayam Lada Hitam']);

    // Harga di nama berbeda: tidak ditautkan, resepnya dibuatkan menu baru.
    $sopMerah = Product::query()->create(['name' => 'NASI SOP MERAH 15K', 'sku' => 'NASI SOP MERAH 15K', 'unit' => 'porsi', 'base_price' => 15000, 'active' => true]);
    $resepSop = resepUtama(['name' => 'nasi sop merah 12k']);

    // Item di luar Menu Utama.
    $hargaUp = Product::query()->create(['name' => 'HARGA UP 2K', 'sku' => 'HARGA UP 2K', 'unit' => 'pcs', 'base_price' => 2000, 'active' => true, 'needs_recipe' => false]);
    resepUtama(['name' => 'Sambal Bawang', 'jenis' => Recipe::JENIS_SUB]);

    $this->artisan('menu:sync-from-recipes', ['--dry-run' => true])->assertSuccessful();
    expect($koloke15->fresh()->name)->toBe('NASI KOLOKE 15K')
        ->and(Product::query()->count())->toBe(5);

    $this->artisan('menu:sync-from-recipes')->assertSuccessful();

    expect($koloke15->fresh())
        ->name->toBe('NASI KOLOKE')
        ->sku->toBe('Nasi Koloke')
        ->origin->toBe(Product::ORIGIN_MENU)
        ->and((float) $koloke15->fresh()->base_price)->toBe(15000.0)
        ->and($koloke12->fresh()->active)->toBeFalse()
        ->and($ladaHitam->fresh())->recipe_id->toBe($resepLada->id)->name->toBe('NASI AYAM LADA HITAM')
        ->and($sopMerah->fresh()->recipe_id)->toBeNull()
        ->and(Product::query()->where('recipe_id', $resepSop->id)->sole()->name)->toBe('NASI SOP MERAH 12K');

    // Harga Menu = pilihan PO = tepat satu menu per Menu Utama, bernama resep.
    expect(Product::query()->orderable()->orderBy('name')->pluck('name')->all())
        ->toBe(['NASI AYAM LADA HITAM', 'NASI KOLOKE', 'NASI SOP MERAH 12K']);

    foreach ([route('adminapp.products.index'), route('adminapp.orders.index')] as $url) {
        $this->actingAs($admin)->get($url)->assertOk()
            ->assertSee('NASI KOLOKE')
            ->assertDontSee('NASI KOLOKE 12K')
            ->assertDontSee('NASI KOLOKE 15K')
            ->assertDontSee('HARGA UP 2K')
            ->assertDontSee('NASI SOP MERAH 15K');
    }

    // Satu lonceng ringkasan untuk menu baru, bukan satu per menu.
    expect(DatabaseNotification::query()->where('notifiable_id', $admin->id)->pluck('data')->pluck('title')->all())
        ->toBe(['1 menu dari tim menu belum punya Harga Non Lapak']);

    // Dijalankan ulang: tidak ada perubahan lagi.
    $this->artisan('menu:sync-from-recipes')->assertSuccessful();
    expect(Product::query()->orderable()->count())->toBe(3)
        ->and($hargaUp->fresh()->isOrderable())->toBeFalse();
});

it('memakai menu lama bernama sama saat Menu Utama disimpan, bukan membuat menu dobel', function () {
    $lama = Product::query()->create(['name' => 'NASI AYAM LADA HITAM', 'sku' => 'NASI AYAM LADA HITAM', 'unit' => 'porsi', 'base_price' => 14000, 'active' => true]);
    $recipe = resepUtama(['name' => 'Nasi Ayam Lada Hitam']);

    $menu = app(MasterMenuSync::class)->ensureFor($recipe);

    expect($menu->is($lama))->toBeTrue()
        ->and(Product::query()->count())->toBe(1)
        ->and($lama->fresh()->isOrderable())->toBeTrue();
});

it('menjalankan sinkron Menu Utama lewat migrasi supaya Harga Menu tidak kosong setelah deploy', function () {
    $recipe = resepUtama(['name' => 'Nasi Bakmoy']);
    $lama = Product::query()->create(['name' => 'NASI BAKMOY 12K', 'sku' => 'NASI BAKMOY 12K', 'unit' => 'porsi', 'base_price' => 12000, 'active' => true, 'recipe_id' => $recipe->id]);
    expect(Product::query()->orderable()->count())->toBe(0);

    (require database_path('migrations/2026_11_09_100001_sync_master_menu_from_recipes.php'))->up();

    expect($lama->fresh())
        ->name->toBe('NASI BAKMOY')
        ->isOrderable()->toBeTrue()
        ->and((float) $lama->fresh()->base_price)->toBe(12000.0);
});
