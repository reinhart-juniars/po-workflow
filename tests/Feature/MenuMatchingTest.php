<?php

use App\Filament\Menu\Pages\MenuMatching;
use App\Filament\Menu\Resources\RecipeResource\Pages\CreateRecipe;
use App\Filament\Menu\Resources\RecipeResource\Pages\EditRecipe;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use App\Models\User;
use App\Services\MenuMatchSuggester;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Pencocokan master produk (Admin App) <-> resep (Inventory).
 *
 * Tautan hidup di products.recipe_id supaya beberapa varian harga boleh
 * berbagi satu resep. Yang diuji: halaman kerja mengurutkan produk terlaris
 * lebih dulu, ketiga jalan keluarnya (tautkan / tanpa resep / buat resep)
 * benar-benar mengubah data, izin kelola dijaga, dan SPK Produksi membaca
 * tautan dari sisi produk.
 */
function penggunaPencocokan(string $role): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

function resepUji(string $name, bool $active = true): Recipe
{
    return Recipe::query()->create([
        'name' => $name, 'jenis' => Recipe::JENIS_UTAMA, 'yield_qty' => 1, 'yield_unit' => 'porsi',
        'ohc_pct' => 0.4, 'profit_pct' => 0.25, 'is_active' => $active,
    ]);
}

function produkUji(string $name, array $extra = []): Product
{
    // fresh(): kolom bawaan (needs_recipe) baru terisi setelah dibaca lagi dari DB.
    return Product::query()->create(array_merge(['name' => $name, 'unit' => 'porsi', 'base_price' => 10000, 'active' => true], $extra))->fresh();
}

/** Catat penjualan sebuah produk supaya kolom porsi terjual terisi. */
function terjual(Product $product, float $qty, string $date): void
{
    $area = Area::query()->firstOrCreate(['code' => 'AR'], ['name' => 'Area']);
    $customer = Customer::query()->firstOrCreate(['name' => 'Pelanggan'], ['area_id' => $area->id, 'is_lapak' => false]);
    $actual = SalesActual::query()->create(['sales_date' => $date, 'customer_id' => $customer->id, 'status' => 'submitted']);

    SalesActualItem::query()->create([
        'sales_actual_id' => $actual->id, 'product_id' => $product->id, 'item_name' => $product->name,
        'unit' => 'porsi', 'qty_delivery' => $qty, 'qty_actual' => $qty, 'unit_price' => 10000,
    ]);
}

it('mengusulkan resep dari nama produk tanpa token harga dan sinonim ejaan', function () {
    $solaria = resepUji('Nasi Goreng Ala Solaria');
    $bihun = resepUji('Bihun Goreng Telur');
    resepUji('Nasi Campur Telur Bali');

    $suggester = app(MenuMatchSuggester::class);

    expect($suggester->normalize('NASI GORENG SOLARIA 12K'))->toBe('nasi goreng solaria')
        ->and($suggester->normalize('RB Dori Cabe Garam 22.5K'))->toBe('rice bowl dori cabe garam')
        ->and($suggester->suggest('NASI GORENG SOLARIA 12K')['recipe']->is($solaria))->toBeTrue()
        ->and($suggester->suggest('MIHUN GORENG TELUR 10K')['recipe']->is($bihun))->toBeTrue()
        // Kontrol: nama yang sama sekali lain tidak dipaksakan.
        ->and($suggester->suggest('DONAT 3K'))->toBeNull();
});

it('mengurutkan produk yang belum dicocokkan dari yang paling laku dan menampilkan usulannya', function () {
    $this->actingAs(penggunaPencocokan('admin'));

    $solaria = resepUji('Nasi Goreng Ala Solaria');
    $laris = produkUji('NASI GORENG SOLARIA 12K');
    $sepi = produkUji('NASI KATSU 12K');
    $tertaut = produkUji('NASI GORENG SOLARIA 10K', ['recipe_id' => $solaria->id]);
    $tanpa = produkUji('EXTRA 1K', ['needs_recipe' => false]);
    $lama = produkUji('NASI JADUL 10K');

    terjual($laris, 200, now()->subDays(5)->toDateString());
    terjual($sepi, 3, now()->subDays(5)->toDateString());
    terjual($lama, 999, now()->subDays(200)->toDateString()); // di luar 90 hari

    Livewire::test(MenuMatching::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$laris, $sepi, $lama])
        ->assertCanNotSeeTableRecords([$tertaut, $tanpa])
        ->assertSeeInOrder(['NASI GORENG SOLARIA 12K', 'NASI KATSU 12K', 'NASI JADUL 10K'])
        ->assertSee('Usulan: Nasi Goreng Ala Solaria (')
        ->filterTable('status', MenuMatching::STATUS_TERTAUT)
        ->assertCanSeeTableRecords([$tertaut])
        ->assertCanNotSeeTableRecords([$laris]);
});

it('menautkan, menandai tanpa resep, dan melepas produk dari halaman', function () {
    $this->actingAs(penggunaPencocokan('menu'));

    $resep = resepUji('Nasi Goreng Ala Solaria');
    $a = produkUji('NASI GORENG SOLARIA 10K');
    $b = produkUji('NASI GORENG SOLARIA 12K');
    $extra = produkUji('EXTRA 1K');
    $hargaUp = produkUji('HARGA UP 2K');

    $page = Livewire::test(MenuMatching::class);

    // Dua varian harga menunjuk resep yang sama; usulan sudah terisi sebagai nilai awal.
    $page->mountTableAction('tautkan', $a)
        ->assertTableActionDataSet(['recipe_id' => $resep->id])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();
    $page->callTableAction('tautkan', $b, ['recipe_id' => $resep->id])->assertHasNoTableActionErrors();

    expect($a->fresh()->recipe_id)->toBe($resep->id)
        ->and($b->fresh()->recipe_id)->toBe($resep->id)
        ->and($resep->products()->count())->toBe(2);

    $page->callTableAction('tanpa_resep', $extra);
    expect($extra->fresh())->needs_recipe->toBeFalse()->recipe_id->toBeNull();

    $page->callTableBulkAction('tanpa_resep_massal', [$hargaUp]);
    expect($hargaUp->fresh()->needs_recipe)->toBeFalse();

    // Lepas mengembalikan ke daftar kerja.
    $page->filterTable('status', MenuMatching::STATUS_TERTAUT)->callTableAction('lepas', $b->fresh());
    expect($b->fresh())->recipe_id->toBeNull()->needs_recipe->toBeTrue();

    // Resep wajib dipilih.
    $page->filterTable('status', MenuMatching::STATUS_BELUM)
        ->callTableAction('tautkan', $b->fresh(), ['recipe_id' => null])
        ->assertHasTableActionErrors(['recipe_id']);
});

it('membuka daftar untuk pemegang izin lihat tetapi menolak perubahan tanpa izin kelola', function () {
    $resep = resepUji('Nasi Goreng Ala Solaria');
    $produk = produkUji('NASI GORENG SOLARIA 10K');

    // accounting: recipe.view saja.
    $this->actingAs(penggunaPencocokan('accounting'));

    $page = Livewire::test(MenuMatching::class)->assertOk()->assertCanSeeTableRecords([$produk]);
    $page->assertTableActionHidden('tautkan', $produk)
        ->assertTableActionHidden('tanpa_resep', $produk);

    // Panggilan langsung ke Livewire pun tidak mengubah data: aksi tersembunyi tidak bisa di-mount.
    $page->call('mountTableAction', 'tautkan', (string) $produk->getKey())
        ->set('mountedTableActionsData.0.recipe_id', $resep->id)
        ->call('callMountedTableAction');
    expect($produk->fresh()->recipe_id)->toBeNull();

    // Kontrol positif: peran tanpa recipe.view tidak bisa membuka; tim menu bisa mengubah.
    $this->actingAs(penggunaPencocokan('sales'));
    Livewire::test(MenuMatching::class)->assertForbidden();

    $this->actingAs(penggunaPencocokan('menu'));
    Livewire::test(MenuMatching::class)->callTableAction('tautkan', $produk, ['recipe_id' => $resep->id]);
    expect($produk->fresh()->recipe_id)->toBe($resep->id);
});

it('membuat resep baru dari produk dengan nama terusul dan produk langsung tertaut', function () {
    $this->actingAs(penggunaPencocokan('menu'));
    $produk = produkUji('PAKET BOX BENTO ITS 25K');

    $this->get(\App\Filament\Menu\Resources\RecipeResource::getUrl('create', ['produk' => $produk->id]))->assertOk();

    $page = Livewire::withQueryParams(['produk' => $produk->id])->test(CreateRecipe::class)
        ->assertFormSet(['name' => 'Paket Box Bento Its', 'product_ids' => [$produk->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    $resep = Recipe::query()->firstWhere('name', 'Paket Box Bento Its');

    expect($resep)->not->toBeNull()
        ->and($produk->fresh()->recipe_id)->toBe($resep->id)
        // Nilai bawaan kolom lain tidak hilang karena prefill.
        ->and($resep->jenis)->toBe(Recipe::JENIS_UTAMA)
        ->and($resep->is_active)->toBeTrue();
});

it('mengganti daftar produk sebuah resep dari form resep', function () {
    $this->actingAs(penggunaPencocokan('menu'));

    $resep = resepUji('Nasi Capjay');
    $p10 = produkUji('NASI CAPJAY 10K', ['recipe_id' => $resep->id]);
    $p12 = produkUji('NASI CAPJAY 12K');
    $p15 = produkUji('NASI CAPJAY 15K', ['needs_recipe' => false]);

    Livewire::test(EditRecipe::class, ['record' => $resep->getRouteKey()])
        ->assertFormSet(['product_ids' => [$p10->id]])
        ->fillForm(['product_ids' => [$p12->id, $p15->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($p10->fresh()->recipe_id)->toBeNull()
        ->and($p12->fresh()->recipe_id)->toBe($resep->id)
        // Produk yang tadinya "tanpa resep" kembali dianggap perlu resep.
        ->and($p15->fresh())->recipe_id->toBe($resep->id)->needs_recipe->toBeTrue();
});

it('menampilkan status resep tiap produk di master menu Admin App', function () {
    $this->actingAs(penggunaPencocokan('admin'));

    $resep = resepUji('Nasi Capjay');
    produkUji('NASI CAPJAY 10K', ['recipe_id' => $resep->id]);
    produkUji('EXTRA 1K', ['needs_recipe' => false]);
    produkUji('NASI KATSU 12K');

    $this->get(route('adminapp.products.index'))
        ->assertOk()
        ->assertSee('Resep:')
        ->assertSee('Nasi Capjay')
        ->assertSee('Tanpa resep')
        ->assertSee('Belum ada resep')
        ->assertSee(route('filament.menu.pages.pencocokan-menu'), false);
});
