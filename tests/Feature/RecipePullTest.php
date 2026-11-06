<?php

use App\Exports\RecipePullExport;
use App\Filament\Pages\RecipePull;
use App\Models\InventoryMovement;
use App\Models\ProductionOrder;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Requisition;
use App\Models\User;
use App\Services\MaterialBreakdownService;
use App\Support\Access\ModuleAccess;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Tarik Resep: gudang menghitung kebutuhan bahan satu menu untuk jumlah bebas
 * tanpa membuat SPK Produksi (revisi user Inventory, 6 Nov 2026), padanan SPK
 * PRA di Master Menu Revamp.
 *
 * Fixture siapkanProduksi(): resep Gorengan 10 porsi = 250 gr tepung (bahan
 * dalam kg) + 150 ml minyak (bahan dalam liter).
 */
function penggunaTarikResep(string $role): User
{
    foreach (ModuleAccess::PERMISSIONS as $permission => $label) {
        Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
    }

    $roleModel = Role::findOrCreate($role, 'web');
    $roleModel->syncPermissions(ModuleAccess::DEFAULT_MATRIX[$role] ?? []);

    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

it('menghitung bahan untuk jumlah bebas tanpa membuat SPK, form kebutuhan, atau gerakan stok', function () {
    $data = siapkanProduksi();
    $this->actingAs(penggunaTarikResep('inventory'));

    $spkSebelum = ProductionOrder::query()->count();

    $page = Livewire::test(RecipePull::class)
        ->assertDontSeeHtml('sh-bd-recap')
        ->set('data.lines', ['baris' => ['recipe_id' => $data['recipe']->id, 'qty' => 7]])
        ->call('calculate')
        ->assertHasNoFormErrors()
        ->assertSeeHtml('sh-bd-recap')
        ->assertSee('Gorengan')
        ->assertSee('Tepung Terigu');

    // 7 porsi = 0,7x resep 10 porsi: 175 gr -> 0,175 kg; 105 ml -> 0,105 liter.
    $recap = collect($page->instance()->getBreakdown()['recap']);

    expect($recap->firstWhere('name', 'Tepung Terigu'))->toMatchArray(['qty' => 0.175, 'unit' => 'kg'])
        ->and($recap->firstWhere('name', 'Minyak Goreng'))->toMatchArray(['qty' => 0.105, 'unit' => 'liter']);

    expect(ProductionOrder::query()->count())->toBe($spkSebelum)
        ->and(Requisition::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0);
});

it('menampilkan susunan resep terbuka dengan sub-menu tanpa perlu klik', function () {
    $data = siapkanProduksi();
    $this->actingAs(penggunaTarikResep('inventory'));

    $sub = Recipe::query()->create(['name' => 'Bumbu Bistik', 'jenis' => Recipe::JENIS_SUB, 'yield_qty' => 1, 'yield_unit' => 'gr']);
    RecipeItem::query()->create(['recipe_id' => $sub->id, 'inventory_item_id' => $data['tepung']->id, 'raw_name' => 'tepung', 'qty' => 1, 'unit' => 'gr']);
    RecipeItem::query()->create(['recipe_id' => $data['recipe']->id, 'ref_recipe_id' => $sub->id, 'raw_name' => 'bumbu', 'qty' => 100, 'unit' => 'gr', 'section' => 'Pelengkap']);

    $html = Livewire::test(RecipePull::class)
        ->set('data.lines', ['baris' => ['recipe_id' => $data['recipe']->id, 'qty' => 10]])
        ->call('calculate')
        ->assertSee('Bumbu Bistik')
        ->html();

    expect($html)->toMatch('/<details class="sh-bd-menu"\s+open/')
        ->and($html)->toMatch('/<details class="sh-tree-node"[^>]*\sopen/')
        ->and($html)->toContain('sh-tree-section')
        ->and($html)->toContain('Pelengkap');

    // Kontrol: tampilan di SPK/PO tetap tertutup seperti semula.
    $bawaan = view('partials.material-breakdown', [
        'breakdown' => app(MaterialBreakdownService::class)->forRecipes([['recipe_id' => $data['recipe']->id, 'qty' => 10]]),
        'matchUrl' => null,
    ])->render();

    // Tarik Resep: rincian menu dulu lalu rekap seperti MMR; SPK/PO tetap rekap dulu.
    expect(strpos($html, 'sh-bd-menus'))->toBeLessThan(strpos($html, 'sh-bd-recap"'))
        ->and(strpos($bawaan, 'sh-bd-recap"'))->toBeLessThan(strpos($bawaan, 'sh-bd-menus'));

    expect($bawaan)->toContain('sh-tree-node')
        ->and($bawaan)->not->toMatch('/<details class="sh-bd-menu"\s+open/')
        ->and($bawaan)->not->toMatch('/<details class="sh-tree-node"[^>]*\sopen/')
        ->and($bawaan)->not->toContain('sh-tree-section');
});

it('menggabungkan resep yang dipilih dua kali dan bisa memuat beberapa menu', function () {
    $data = siapkanProduksi();

    $kedua = Recipe::query()->create(['name' => 'Tahu Goreng', 'jenis' => Recipe::JENIS_UTAMA, 'yield_qty' => 1, 'yield_unit' => 'porsi']);
    RecipeItem::query()->create(['recipe_id' => $kedua->id, 'inventory_item_id' => $data['minyak']->id, 'raw_name' => 'minyak', 'qty' => 10, 'unit' => 'ml']);

    $breakdown = app(MaterialBreakdownService::class)->forRecipes([
        ['recipe_id' => $data['recipe']->id, 'qty' => 10],
        ['recipe_id' => $kedua->id, 'qty' => 5],
        ['recipe_id' => (string) $data['recipe']->id, 'qty' => '10'],
    ]);

    expect($breakdown['menus'])->toHaveCount(2)
        ->and($breakdown['menus'][0])->toMatchArray(['label' => 'Gorengan', 'qty' => 20.0, 'unit' => 'porsi'])
        // Minyak: 20 porsi = 300 ml + 5 porsi tahu = 50 ml -> 0,35 liter dari dua menu.
        ->and(collect($breakdown['recap'])->firstWhere('name', 'Minyak Goreng'))->toMatchArray([
            'qty' => 0.35,
            'menus' => ['Gorengan', 'Tahu Goreng'],
        ]);
});

it('mencantumkan resep nonaktif sebagai tidak terhitung, bukan membuangnya diam-diam', function () {
    $data = siapkanProduksi();
    $data['recipe']->update(['is_active' => false]);

    $breakdown = app(MaterialBreakdownService::class)->forRecipes([['recipe_id' => $data['recipe']->id, 'qty' => 5]]);

    expect($breakdown['menus'])->toBe([])
        ->and($breakdown['recap'])->toBe([])
        ->and($breakdown['missing'])->toHaveCount(1)
        ->and($breakdown['missing'][0]['qty'])->toBe(5.0);
});

it('menolak jumlah nol, negatif, atau menu kosong dan tetap menerima jumlah yang sah', function (mixed $qty) {
    $data = siapkanProduksi();
    $this->actingAs(penggunaTarikResep('inventory'));

    $page = Livewire::test(RecipePull::class)
        ->set('data.lines', ['baris' => ['recipe_id' => $data['recipe']->id, 'qty' => $qty]])
        ->call('calculate')
        ->assertHasFormErrors()
        ->assertSet('pulled', []);

    // Kontrol positif: form yang sama lolos begitu jumlahnya benar.
    $page->set('data.lines', ['baris' => ['recipe_id' => $data['recipe']->id, 'qty' => 1.5]])
        ->call('calculate')
        ->assertHasNoFormErrors()
        ->assertSet('pulled', [['recipe_id' => $data['recipe']->id, 'qty' => 1.5]]);
})->with(['nol' => 0, 'negatif' => -3, 'kosong' => null, 'kebesaran' => 1000001]);

it('mengekspor dengan kolom SPK PRA MMR, dikelompokkan per bagian resep, plus rekap bahan', function () {
    $data = siapkanProduksi();
    $this->actingAs(penggunaTarikResep('inventory'));
    RecipeItem::query()->where('recipe_id', $data['recipe']->id)->update(['section' => 'Adonan']);

    Livewire::test(RecipePull::class)
        ->assertActionHidden('excel')
        ->set('data.lines', ['baris' => ['recipe_id' => $data['recipe']->id, 'qty' => 10]])
        ->call('calculate')
        ->assertActionVisible('excel')
        ->callAction('excel')
        ->assertFileDownloaded();

    $breakdown = app(MaterialBreakdownService::class)->forRecipes([['recipe_id' => $data['recipe']->id, 'qty' => 10]]);
    $sheets = (new RecipePullExport($breakdown))->sheets();
    $rows = $sheets[0]->array();

    // Kolom sama dengan SPK PRA MMR supaya tempel ke berkas gudang tetap lurus.
    expect($sheets[0]->title())->toBe('Tarik Resep')
        ->and($rows[0])->toBe(['', 'No', 'Order', 'QTY', 'Satuan', 'Pelaksana'])
        ->and($rows[1])->toBe(['', 1, 'siapkan komponen Gorengan', 10.0, 'porsi', ''])
        ->and($rows[2])->toBe(['', '', 'Adonan'])
        ->and($rows[3])->toBe(['', '', 'Tepung Terigu', 250.0, 'gram'])
        ->and($rows[4])->toBe(['', '', 'Minyak Goreng', 150.0, 'ml'])
        ->and($sheets[1]->title())->toBe('Rekap Bahan')
        ->and(collect($sheets[1]->array())->pluck(0)->all())->toContain('Tepung Terigu', 'Minyak Goreng');
});

it('hanya terbuka bagi peran yang boleh melihat resep', function () {
    siapkanProduksi();

    foreach (['inventory', 'production', 'admin', 'accounting'] as $role) {
        $this->actingAs(penggunaTarikResep($role))
            ->get(route('filament.admin.pages.tarik-resep'))
            ->assertOk()
            ->assertSee('Tarik Bahan');
    }

    // Punya akses panel Inventory, tapi izin recipe.view dicabut.
    $tanpaIzin = penggunaTarikResep('accounting');
    Role::findByName('accounting', 'web')->revokePermissionTo('recipe.view');
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($tanpaIzin->fresh())
        ->get(route('filament.admin.pages.tarik-resep'))
        ->assertForbidden();
});
