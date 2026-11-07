<?php

use App\Exports\SpkPraExport;
use App\Exports\SpkPraWorkbook;
use App\Filament\Resources\ProductionOrderResource\Pages\SpkPraSheet;
use App\Models\InventoryItem;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderComponentOverride;
use App\Models\ProductionOrderLine;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\SpkComponentRule;
use App\Models\User;
use App\Services\MaterialBreakdownService;
use App\Services\SpkPraService;
use App\Support\Access\ModuleAccess;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Lembar SPK PRA: dokumen per menu yang dikirim gudang ke produksi. Komponen tampil
 * sesuai setelan, sub-menu terurai otomatis, dan jumlah khusus berlaku per SPK.
 */
function penggunaSpkPra(string $role): User
{
    foreach (array_keys(ModuleAccess::PERMISSIONS) as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate($role, 'web')->syncPermissions(ModuleAccess::DEFAULT_MATRIX[$role] ?? []);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

function bahanSpkPra(string $name, string $unit = 'gr', string $category = InventoryItem::CATEGORY_RAW_MATERIAL): InventoryItem
{
    return InventoryItem::query()->create(['name' => $name, 'unit' => $unit, 'category' => $category, 'unit_price' => 1000, 'is_active' => true]);
}

/** @param  list<array{0: ?string, 1: string, 2: float, 3: string, 4?: InventoryItem|Recipe|null}>  $items [bagian, nama, jumlah, satuan, bahan/sub-menu] */
function resepSpkPra(string $name, array $items, string $jenis = Recipe::JENIS_UTAMA): Recipe
{
    $recipe = Recipe::query()->create(['name' => $name, 'jenis' => $jenis, 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25]);

    foreach ($items as $index => $item) {
        $link = $item[4] ?? null;

        RecipeItem::query()->create([
            'recipe_id' => $recipe->id,
            'sort_order' => $index + 1,
            'section' => $item[0],
            'raw_name' => $item[1],
            'qty' => $item[2],
            'unit' => $item[3],
            'inventory_item_id' => $link instanceof InventoryItem ? $link->id : null,
            'ref_recipe_id' => $link instanceof Recipe ? $link->id : null,
        ]);
    }

    return $recipe;
}

function spkPraUntuk(Recipe $recipe, float $qty = 30, array $attributes = []): ProductionOrder
{
    $order = ProductionOrder::query()->create($attributes + [
        'title' => 'SPK PRA 7 NOVEMBER 2026',
        'production_date' => '2026-11-08',
        'work_date' => '2026-11-07',
        'production_time' => '08.30',
    ]);

    ProductionOrderLine::query()->create([
        'production_order_id' => $order->id, 'sort_order' => 1, 'kind' => ProductionOrderLine::KIND_MENU,
        'recipe_id' => $recipe->id, 'qty' => $qty, 'unit' => 'porsi',
    ]);

    return $order;
}

/** Menu contoh yang strukturnya sama dengan berkas 7 November. */
function menuContohSpkPra(): Recipe
{
    $nasi = resepSpkPra('Nasi Putih', [[null, 'beras', 77, 'gr'], [null, 'air', 125, 'ml']], Recipe::JENIS_SUB);
    $suun = resepSpkPra('Suun Kecap', [[null, 'suun golok mas', 0.03, 'pack'], [null, 'bawang putih', 1.5, 'gr']], Recipe::JENIS_SUB);

    return resepSpkPra('Nasi Ayam Geprek', [
        [null, 'timun', 10, 'gr'],
        ['Nasi Putih', 'Nasi Putih', 1, 'porsi', $nasi],
        ['Ayam Goreng', 'dada ayam fillet', 35, 'gr'],
        ['Ayam Goreng', 'minyak tropical', 50, 'ml'],
        ['Bumbu', 'cabe merah', 4, 'gr', bahanSpkPra('Cabe Merah')],
        ['Bumbu', 'garam halus', 1, 'gr', bahanSpkPra('Garam 500gr')],
        ['Suun Kecap', 'Suun Kecap', 0.5, 'porsi', $suun],
        ['Kemasan', 'thinwall', 1, 'pcs', bahanSpkPra('Thinwall', 'pcs', InventoryItem::CATEGORY_PACKAGING)],
        [null, 'plastik klip', 1, 'lbr', bahanSpkPra('Plastik Klip', 'lbr', InventoryItem::CATEGORY_PACKAGING)],
    ]);
}

function menuLembar(ProductionOrder $order, int $index = 0): array
{
    return app(SpkPraService::class)->forProductionOrder($order)['menus'][$index];
}

function komponenLembar(array $menu, string $key): ?array
{
    return collect($menu['components'])->firstWhere('key', $key);
}

it('menyembunyikan Kemasan dan Nasi Putih, membuang kemasan lepas, dan mengurai sub-menu otomatis', function () {
    $menu = menuLembar(spkPraUntuk(menuContohSpkPra()));

    expect($menu['title'])->toBe('siapkan komponen Nasi Ayam Geprek')
        ->and($menu['qty'])->toBe(30.0)
        ->and(collect($menu['hidden'])->pluck('key')->sort()->values()->all())->toBe(['kemasan', 'nasi putih']);

    // Bahan lepas tanpa judul; plastik klip berkategori kemasan tidak ikut tampil.
    $loose = komponenLembar($menu, SpkPraService::KEY_LOOSE);
    expect($loose['name'])->toBeNull()
        ->and($loose['rows'])->toBe([['name' => 'timun', 'qty' => 300.0, 'unit' => 'gram']]);

    // Memakai tulisan resep, bukan nama item stok.
    expect(collect(komponenLembar($menu, 'bumbu')['rows'])->pluck('name')->all())->toBe(['cabe merah', 'garam halus']);

    // Sub-menu: 0,5 x 30 porsi = 15 porsi, bahannya ikut terhitung.
    $suun = komponenLembar($menu, 'suun kecap');
    expect($suun)->toMatchArray(['name' => 'Suun Kecap', 'qty' => 15.0, 'unit' => 'porsi', 'show_qty' => true])
        ->and($suun['rows'])->toBe([
            ['name' => 'suun golok mas', 'qty' => 0.45, 'unit' => 'pack'],
            ['name' => 'bawang putih', 'qty' => 22.5, 'unit' => 'gram'],
        ]);
});

it('meringkas komponen jadi satu baris dengan jumlah bahan utama atau jumlah komponen', function () {
    $order = spkPraUntuk(menuContohSpkPra());

    SpkComponentRule::query()->create(['component_key' => 'ayam goreng', 'display' => 'single', 'label' => 'Ayam Goreng Fillet', 'qty_basis' => 'ingredient', 'basis_item' => 'dada ayam fillet']);
    SpkComponentRule::query()->create(['component_key' => 'bumbu', 'display' => 'single']);

    $menu = menuLembar($order);

    expect(komponenLembar($menu, 'ayam goreng'))->toMatchArray(['name' => 'Ayam Goreng Fillet', 'qty' => 1050.0, 'unit' => 'gram', 'rows' => [], 'show_qty' => true])
        ->and(komponenLembar($menu, 'bumbu'))->toMatchArray(['name' => 'Bumbu', 'qty' => 30.0, 'unit' => 'porsi', 'rows' => []]);
});

it('mencatat bahan utama yang sudah tidak ada dan tetap menampilkan jumlah komponen', function () {
    $order = spkPraUntuk(menuContohSpkPra());
    SpkComponentRule::query()->create(['component_key' => 'ayam goreng', 'display' => 'single', 'qty_basis' => 'ingredient', 'basis_item' => 'bahan lama']);

    $menu = menuLembar($order);

    expect(komponenLembar($menu, 'ayam goreng'))->toMatchArray(['qty' => 30.0, 'unit' => 'porsi'])
        ->and($menu['issues'][0])->toContain('bahan lama');
});

it('menyembunyikan bahan terpilih dan mendahulukan pengecualian menu di atas setelan semua menu', function () {
    $recipe = menuContohSpkPra();
    $lain = resepSpkPra('Menu Lain', [['Bumbu', 'cabe merah', 1, 'gr'], ['Bumbu', 'garam halus', 1, 'gr']]);

    SpkComponentRule::query()->create(['component_key' => 'bumbu', 'hidden_items' => ['garam halus']]);
    SpkComponentRule::query()->create(['recipe_id' => $recipe->id, 'component_key' => 'bumbu', 'display' => 'single']);

    expect(collect(komponenLembar(menuLembar(spkPraUntuk($lain)), 'bumbu')['rows'])->pluck('name')->all())->toBe(['cabe merah'])
        ->and(komponenLembar(menuLembar(spkPraUntuk($recipe)), 'bumbu'))->toMatchArray(['display' => 'single', 'scope' => 'menu', 'rows' => []]);
});

it('menskalakan isi komponen dengan jumlah khusus hanya pada SPK itu', function () {
    $recipe = menuContohSpkPra();
    $order = spkPraUntuk($recipe, 25);
    $lain = spkPraUntuk($recipe, 25);

    // Suun Kecap acuan 12,5 porsi dimasak 13: suun 0,375 pack menjadi 0,39 pack.
    app(SpkPraService::class)->saveOverride($order, $recipe->id, 'suun kecap', 13, '2 kotak', null);

    $suun = komponenLembar(menuLembar($order), 'suun kecap');

    expect($suun)->toMatchArray(['qty' => 13.0, 'base_qty' => 12.5, 'custom' => true, 'note' => '2 kotak'])
        ->and($suun['rows'][0])->toBe(['name' => 'suun golok mas', 'qty' => 0.39, 'unit' => 'pack'])
        ->and($suun['rows'][1]['qty'])->toBe(19.5);

    expect(komponenLembar(menuLembar($lain), 'suun kecap'))->toMatchArray(['qty' => 12.5, 'custom' => false]);

    // Dikosongkan: kembali ke hitungan resep dan barisnya terhapus.
    app(SpkPraService::class)->saveOverride($order, $recipe->id, 'suun kecap', null, null, null);

    expect(komponenLembar(menuLembar($order), 'suun kecap')['qty'])->toBe(12.5)
        ->and(ProductionOrderComponentOverride::query()->count())->toBe(0);
});

it('menggabungkan menu yang sama dan tetap menampilkan baris manual', function () {
    $recipe = menuContohSpkPra();
    $order = spkPraUntuk($recipe, 10);

    ProductionOrderLine::query()->create(['production_order_id' => $order->id, 'sort_order' => 2, 'kind' => ProductionOrderLine::KIND_MENU, 'recipe_id' => $recipe->id, 'qty' => 20, 'unit' => 'porsi']);
    ProductionOrderLine::query()->create(['production_order_id' => $order->id, 'sort_order' => 3, 'kind' => ProductionOrderLine::KIND_MANUAL, 'label' => 'siapkan stiker', 'qty' => 5, 'unit' => 'set']);

    $menus = app(SpkPraService::class)->forProductionOrder($order)['menus'];

    expect($menus)->toHaveCount(2)
        ->and($menus[0])->toMatchArray(['no' => 1, 'qty' => 30.0])
        ->and($menus[1])->toMatchArray(['no' => 2, 'title' => 'siapkan stiker', 'qty' => 5.0, 'unit' => 'set', 'components' => []]);
});

it('tidak berhenti pada resep yang berputar dan mencatatnya', function () {
    $a = resepSpkPra('Sub A', [[null, 'garam', 1, 'gr']], Recipe::JENIS_SUB);
    $b = resepSpkPra('Sub B', [[null, 'Sub A', 1, 'porsi', $a]], Recipe::JENIS_SUB);
    RecipeItem::query()->create(['recipe_id' => $a->id, 'sort_order' => 2, 'raw_name' => 'Sub B', 'qty' => 1, 'unit' => 'porsi', 'ref_recipe_id' => $b->id]);

    $menu = menuLembar(spkPraUntuk(resepSpkPra('Menu Putar', [[null, 'Sub A', 1, 'porsi', $a]])));

    expect(komponenLembar($menu, 'sub a'))->not->toBeNull()
        ->and(implode(' ', $menu['issues']))->toContain('berputar');
});

it('tidak memperingatkan sub-menu yang tidak bisa diurai bila komponennya diringkas', function () {
    $sub = resepSpkPra('Koin Ayam', [[null, 'ayam', 10, 'gr']], Recipe::JENIS_SUB);
    $order = spkPraUntuk(resepSpkPra('Menu Koin', [[null, 'Koin Ayam', 2, 'pcs', $sub]]));

    // Rinci: pcs tidak sepadan dengan hasil porsi, jadi dapur perlu tahu isinya tidak terurai.
    expect(menuLembar($order)['issues'][0])->toContain('Koin Ayam');

    SpkComponentRule::query()->create(['component_key' => 'koin ayam', 'display' => 'single']);

    $menu = menuLembar($order);

    expect($menu['issues'])->toBe([])
        ->and(komponenLembar($menu, 'koin ayam'))->toMatchArray(['qty' => 60.0, 'unit' => 'pcs', 'rows' => []]);
});

it('menyimpan setelan lewat halaman, berlaku lintas SPK, dan jumlah khusus hanya di SPK itu', function () {
    $recipe = menuContohSpkPra();
    $order = spkPraUntuk($recipe);
    $this->actingAs(penggunaSpkPra('inventory'));

    Livewire::test(SpkPraSheet::class, ['record' => $order->getRouteKey()])
        ->assertSee('siapkan komponen Nasi Ayam Geprek')
        ->assertSee('dada ayam fillet')
        ->callAction('atur', data: [
            'display' => 'single',
            'label' => 'Ayam Goreng Fillet',
            'basis_item' => 'dada ayam fillet',
            'hidden_items' => [],
            'scope' => 'global',
            'qty' => 1100,
            'note' => '2 kotak',
        ], arguments: ['recipe' => $recipe->id, 'key' => 'ayam goreng'])
        ->assertHasNoActionErrors()
        ->assertSee('Ayam Goreng Fillet')
        ->assertDontSee('dada ayam fillet');

    expect(SpkComponentRule::query()->whereNull('recipe_id')->where('component_key', 'ayam goreng')->first())
        ->toMatchArray(['display' => 'single', 'label' => 'Ayam Goreng Fillet', 'qty_basis' => 'ingredient', 'basis_item' => 'dada ayam fillet']);

    expect(komponenLembar(menuLembar($order), 'ayam goreng'))->toMatchArray(['qty' => 1100.0, 'custom' => true, 'note' => '2 kotak'])
        ->and(komponenLembar(menuLembar(spkPraUntuk($recipe)), 'ayam goreng'))->toMatchArray(['qty' => 1050.0, 'custom' => false, 'display' => 'single']);
});

it('membuka kembali komponen tersembunyi hanya untuk satu menu', function () {
    $recipe = menuContohSpkPra();
    $order = spkPraUntuk($recipe);
    $this->actingAs(penggunaSpkPra('inventory'));

    Livewire::test(SpkPraSheet::class, ['record' => $order->getRouteKey()])
        ->callAction('atur', data: ['display' => 'single', 'scope' => 'menu', 'label' => null, 'basis_item' => null, 'qty' => null, 'note' => null], arguments: ['recipe' => $recipe->id, 'key' => 'nasi putih'])
        ->assertHasNoActionErrors();

    expect(komponenLembar(menuLembar($order), 'nasi putih'))->toMatchArray(['display' => 'single', 'qty' => 30.0, 'scope' => 'menu']);

    // Setelan semua menu Nasi Putih tidak berubah, jadi menu lain tetap menyembunyikannya.
    $lain = resepSpkPra('Menu Lain', [['Nasi Putih', 'beras', 77, 'gr'], ['Nasi Putih', 'air', 125, 'ml']]);

    expect(collect(menuLembar(spkPraUntuk($lain))['hidden'])->pluck('key')->all())->toBe(['nasi putih']);
});

it('menolak jumlah khusus nol atau negatif dan menerima jumlah yang sah', function (mixed $qty) {
    $recipe = menuContohSpkPra();
    $order = spkPraUntuk($recipe);
    $this->actingAs(penggunaSpkPra('inventory'));
    $form = ['display' => 'detail', 'hidden_items' => [], 'scope' => 'global', 'label' => null, 'note' => null];
    $arguments = ['recipe' => $recipe->id, 'key' => 'bumbu'];

    Livewire::test(SpkPraSheet::class, ['record' => $order->getRouteKey()])
        ->callAction('atur', data: $form + ['qty' => $qty], arguments: $arguments)
        ->assertHasActionErrors(['qty']);

    expect(ProductionOrderComponentOverride::query()->count())->toBe(0);

    // Kontrol positif: form yang sama lolos dengan jumlah yang benar.
    Livewire::test(SpkPraSheet::class, ['record' => $order->getRouteKey()])
        ->callAction('atur', data: $form + ['qty' => 12.5], arguments: $arguments)
        ->assertHasNoActionErrors();

    expect(ProductionOrderComponentOverride::query()->value('qty'))->toEqual('12.5000');
})->with(['nol' => 0, 'negatif' => -5, 'kebesaran' => 1000001]);

it('menyaring isian bahan yang tidak ada di komponen', function () {
    $recipe = menuContohSpkPra();
    $order = spkPraUntuk($recipe);
    $this->actingAs(penggunaSpkPra('inventory'));

    Livewire::test(SpkPraSheet::class, ['record' => $order->getRouteKey()])
        ->callAction('atur', data: ['display' => 'detail', 'hidden_items' => ['cabe merah', 'bahan palsu'], 'scope' => 'menu', 'qty' => null, 'label' => null, 'note' => null], arguments: ['recipe' => $recipe->id, 'key' => 'bumbu']);

    $rule = SpkComponentRule::query()->where('recipe_id', $recipe->id)->where('component_key', 'bumbu')->first();

    expect($rule?->hidden_items ?? [])->not->toContain('bahan palsu');
});

it('tidak mengubah apa pun untuk resep di luar SPK yang dibuka', function () {
    $recipe = menuContohSpkPra();
    $luar = resepSpkPra('Menu Luar', [['Bumbu Luar', 'garam', 1, 'gr']]);
    $order = spkPraUntuk($recipe);
    $this->actingAs(penggunaSpkPra('inventory'));

    Livewire::test(SpkPraSheet::class, ['record' => $order->getRouteKey()])
        ->callAction('atur', data: ['display' => 'hidden', 'scope' => 'global'], arguments: ['recipe' => $luar->id, 'key' => 'bumbu luar']);

    expect(SpkComponentRule::query()->where('component_key', 'bumbu luar')->exists())->toBeFalse();

    // Kontrol positif: komponen milik SPK ini tersimpan.
    Livewire::test(SpkPraSheet::class, ['record' => $order->getRouteKey()])
        ->callAction('atur', data: ['display' => 'hidden', 'scope' => 'global'], arguments: ['recipe' => $recipe->id, 'key' => 'bumbu']);

    expect(SpkComponentRule::query()->where('component_key', 'bumbu')->exists())->toBeTrue();
});

it('membatasi siapa yang boleh mengatur, dan mengunci SPK yang sudah selesai', function () {
    $recipe = menuContohSpkPra();
    $order = spkPraUntuk($recipe);
    $data = ['display' => 'hidden', 'scope' => 'global'];
    $arguments = ['recipe' => $recipe->id, 'key' => 'bumbu'];

    // Accounting hanya melihat.
    $this->actingAs(penggunaSpkPra('accounting'));
    Livewire::test(SpkPraSheet::class, ['record' => $order->getRouteKey()])
        ->assertOk()
        ->assertActionVisible('excel')
        ->assertActionHidden('atur', $arguments);

    expect(SpkComponentRule::query()->where('component_key', 'bumbu')->exists())->toBeFalse();

    // SPK selesai terkunci, walau untuk gudang.
    $this->actingAs(penggunaSpkPra('inventory'));
    $order->update(['status' => ProductionOrder::STATUS_COMPLETED]);
    Livewire::test(SpkPraSheet::class, ['record' => $order->getRouteKey()])->assertActionHidden('atur', $arguments);

    // Kontrol positif: gudang boleh mengatur SPK yang masih terbuka, termasuk SPK dari slot.
    $order->update(['status' => ProductionOrder::STATUS_DRAFT]);
    Livewire::test(SpkPraSheet::class, ['record' => $order->getRouteKey()])
        ->assertActionVisible('atur', $arguments)
        ->callAction('atur', data: $data, arguments: $arguments);

    expect(SpkComponentRule::query()->where('component_key', 'bumbu')->exists())->toBeTrue();
});

it('hanya bisa dibuka peran yang boleh melihat SPK Produksi', function () {
    $order = spkPraUntuk(menuContohSpkPra());
    $url = route('filament.admin.resources.production-orders.spk-pra', $order);

    foreach (['inventory', 'production', 'admin', 'accounting'] as $role) {
        $this->actingAs(penggunaSpkPra($role))->get($url)->assertOk()->assertSee('siapkan komponen Nasi Ayam Geprek');
    }

    $tanpaIzin = penggunaSpkPra('accounting');
    Role::findByName('accounting', 'web')->revokePermissionTo('production.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($tanpaIzin->fresh())->get($url)->assertForbidden();
});

it('mengekspor Excel berformat berkas kiriman dan rekap tanpa harga', function () {
    $recipe = menuContohSpkPra();
    $order = spkPraUntuk($recipe);
    $service = app(SpkPraService::class);

    SpkComponentRule::query()->create(['component_key' => 'ayam goreng', 'display' => 'single', 'label' => 'Ayam Goreng Fillet', 'qty_basis' => 'ingredient', 'basis_item' => 'dada ayam fillet']);
    $service->saveOverride($order, $recipe->id, 'ayam goreng', null, '2 kotak', null);

    $sheets = (new SpkPraWorkbook($service->forProductionOrder($order), app(MaterialBreakdownService::class)->forProductionOrder($order)))->sheets();
    $rows = $sheets[0]->array();

    expect($sheets[0])->toBeInstanceOf(SpkPraExport::class)
        ->and($rows[0])->toBe(['', '', 'SPK PRA untuk dikerjakan tgl', '07/11/2026', 'jam', '08.30'])
        ->and($rows[1])->toBe(['', 'No', 'Order', 'QTY', 'Satuan', 'Pelaksana'])
        ->and($rows[2])->toBe(['', 1, 'siapkan komponen Nasi Ayam Geprek', 30.0, 'porsi', null])
        ->and($rows[3])->toBe(['', '', 'timun', 300.0, 'gram'])
        ->and($rows)->toContain(['', '', 'Ayam Goreng Fillet', 1050.0, 'gram', '2 kotak'])
        ->and($rows)->toContain(['', '', 'Bumbu', null, null, null])
        ->and($rows)->toContain(['', '', 'cabe merah', 120.0, 'gram'])
        ->and(collect($rows)->pluck(2)->all())->not->toContain('thinwall');

    $recap = $sheets[1]->array();

    expect($recap[0])->toBe(['No.', 'Bahan', 'Total Qty', 'Satuan', 'Dari brp menu'])
        ->and(collect($recap)->pluck(1)->all())->toContain('Cabe Merah');
});

it('menebalkan tepat baris judul komponen dan memberi jarak antar menu di berkas Excel sungguhan', function () {
    $recipe = menuContohSpkPra();
    $order = spkPraUntuk($recipe);
    $lain = resepSpkPra('Menu Kedua', [['Bumbu Kedua', 'kunyit', 2, 'gr']]);
    ProductionOrderLine::query()->create(['production_order_id' => $order->id, 'sort_order' => 2, 'kind' => ProductionOrderLine::KIND_MENU, 'recipe_id' => $lain->id, 'qty' => 10, 'unit' => 'porsi']);

    $service = app(SpkPraService::class);
    $binary = Maatwebsite\Excel\Facades\Excel::raw(
        new SpkPraWorkbook($service->forProductionOrder($order), app(MaterialBreakdownService::class)->forProductionOrder($order)),
        Maatwebsite\Excel\Excel::XLSX,
    );

    $path = tempnam(sys_get_temp_dir(), 'spkpra').'.xlsx';
    file_put_contents($path, $binary);
    $sheet = PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheetByName('SPK PRA');
    unlink($path);

    $cells = [];
    foreach (range(1, $sheet->getHighestRow()) as $row) {
        $name = (string) $sheet->getCell('C'.$row)->getValue();
        $cells[$name] = ['row' => $row, 'bold' => (bool) $sheet->getStyle('C'.$row)->getFont()->getBold()];
    }

    expect($cells['Bumbu']['bold'])->toBeTrue()
        ->and($cells['Bumbu Kedua']['bold'])->toBeTrue()
        ->and($cells['cabe merah']['bold'])->toBeFalse()
        ->and($cells['kunyit']['bold'])->toBeFalse()
        ->and($cells['siapkan komponen Menu Kedua']['bold'])->toBeFalse();

    // Satu baris kosong sebelum menu kedua.
    $menuKedua = $cells['siapkan komponen Menu Kedua']['row'];
    expect($sheet->getCell('C'.($menuKedua - 1))->getValue())->toBeIn([null, ''])
        ->and($sheet->getCell('C'.($menuKedua - 2))->getValue())->not->toBeIn([null, '']);
});

it('mengatur tanggal dan jam dikerjakan lewat lembar, dalam batas H-7 sampai H', function () {
    $order = spkPraUntuk(menuContohSpkPra());
    $this->actingAs(penggunaSpkPra('inventory'));

    Livewire::test(SpkPraSheet::class, ['record' => $order->getRouteKey()])
        ->callAction('jadwal', data: ['work_date' => '2026-11-20', 'production_time' => '09.00'])
        ->assertHasActionErrors(['work_date']);

    expect($order->fresh()->work_date->toDateString())->toBe('2026-11-07');

    // Kontrol positif: tanggal dalam batas tersimpan dan ikut ke lembar.
    Livewire::test(SpkPraSheet::class, ['record' => $order->getRouteKey()])
        ->callAction('jadwal', data: ['work_date' => '2026-11-08', 'production_time' => '09.00'])
        ->assertHasNoActionErrors();

    $fresh = $order->fresh();

    expect($fresh->work_date->toDateString())->toBe('2026-11-08')
        ->and($fresh->production_time)->toBe('09.00')
        ->and((new SpkPraExport(app(SpkPraService::class)->forProductionOrder($fresh)))->array()[0])
        ->toBe(['', '', 'SPK PRA untuk dikerjakan tgl', '08/11/2026', 'jam', '09.00']);
});

it('mengunduh Excel dan PDF lewat halaman', function () {
    $order = spkPraUntuk(menuContohSpkPra());
    $this->actingAs(penggunaSpkPra('inventory'));

    Livewire::test(SpkPraSheet::class, ['record' => $order->getRouteKey()])
        ->callAction('excel')
        ->assertFileDownloaded('SPK PRA 7 NOVEMBER 2026.xlsx');

    Livewire::test(SpkPraSheet::class, ['record' => $order->getRouteKey()])
        ->callAction('pdf')
        ->assertFileDownloaded('SPK PRA 7 NOVEMBER 2026.pdf');
});
