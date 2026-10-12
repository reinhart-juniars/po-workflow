<?php

use App\Exports\MaterialBreakdownExport;
use App\Exports\MenuListExport;
use App\Exports\PlatingExport;
use App\Exports\PriceHistoryExport;
use App\Exports\ProductionTasksExport;
use App\Exports\RecipeTasksExport;
use App\Filament\Menu\Pages\RecipeTaskTemplates;
use App\Filament\Menu\Resources\RecipeResource\Pages\ListRecipes;
use App\Filament\Resources\ProductionOrderResource\Pages\PlatingSheet;
use App\Models\ProductionTask;
use App\Models\ProductionWorker;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeTask;
use App\Models\User;
use App\Services\MaterialBreakdownService;
use App\Services\PlatingService;
use App\Services\ProductionOrderService;
use App\Services\RecipeTaskImporter;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;

/**
 * Fitur Master Menu Revamp yang dipakai klien dan kini ada di 3S ONE:
 * daftar menu dengan status profit, harga manual per baris resep, export
 * riwayat harga, plating per menu + Excel, export Excel breakdown & lembar
 * kerja, halaman Pekerjaan Menu + import/export, pilihan tugas di lembar kerja.
 */
function penggunaParitas(string $role): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

/** Baris-baris sebuah sheet export sebagai array. */
function barisSheet(object $sheet): array
{
    return method_exists($sheet, 'array') ? $sheet->array() : $sheet->collection()->map(fn ($row) => array_values((array) $row))->all();
}

it('menandai menu yang profit nyatanya di bawah target dan bisa menyaringnya', function () {
    $data = siapkanProduksi();
    // Gorengan: HPP 600/porsi + OHC 40% = 840. Target 1.000 -> profit 19% < 25%.
    $data['recipe']->update(['target_price' => 1000, 'kategori' => 'gorengan']);

    $untung = Recipe::query()->create(['name' => 'Tepung Bumbu', 'jenis' => Recipe::JENIS_UTAMA, 'kategori' => 'bumbu/biang', 'yield_qty' => 10, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25, 'target_price' => 2000]);
    RecipeItem::query()->create(['recipe_id' => $untung->id, 'inventory_item_id' => $data['tepung']->id, 'raw_name' => 'tepung', 'qty' => 500, 'unit' => 'gr']);

    $this->actingAs(penggunaParitas('admin'));

    Livewire::test(ListRecipes::class)
        ->assertCanSeeTableRecords([$data['recipe'], $untung])
        ->filterTable('profit_di_bawah_target')
        ->assertCanSeeTableRecords([$data['recipe']])
        ->assertCanNotSeeTableRecords([$untung]);

    Livewire::test(ListRecipes::class)
        ->filterTable('kategori', 'gorengan')
        ->assertCanSeeTableRecords([$data['recipe']])
        ->assertCanNotSeeTableRecords([$untung]);

    $rows = (new MenuListExport)->collection()->keyBy(0);
    expect($rows['Gorengan'][13])->toBe('DI BAWAH TARGET')
        ->and($rows['Tepung Bumbu'][13])->toBe('OK')
        ->and($rows['Gorengan'][9])->toBe(840.0);
});

it('memindahkan resep antara Menu Utama dan Sub-Menu dari daftar', function () {
    $data = siapkanProduksi();
    $this->actingAs(penggunaParitas('menu'));

    Livewire::test(ListRecipes::class)->callTableAction('pindah_jenis', $data['recipe']);
    expect($data['recipe']->fresh()->jenis)->toBe(Recipe::JENIS_SUB);

    Livewire::test(ListRecipes::class)->callTableAction('pindah_jenis', $data['recipe']);
    expect($data['recipe']->fresh()->jenis)->toBe(Recipe::JENIS_UTAMA);
});

it('mengekspor riwayat harga semua bahan atau satu bahan saja', function () {
    $data = siapkanProduksi();
    $this->actingAs(penggunaParitas('admin'));

    $data['tepung']->update(['unit_price' => 13000]);
    $data['minyak']->update(['unit_price' => 22000]);

    $semua = (new PriceHistoryExport)->collection();
    $tepung = (new PriceHistoryExport($data['tepung']->id))->collection();

    expect($semua->pluck(1)->unique()->sort()->values()->all())->toBe(['Minyak Goreng', 'Tepung Terigu'])
        ->and($tepung->pluck(1)->unique()->all())->toBe(['Tepung Terigu'])
        ->and($tepung->first()[5])->toBe(13000.0);
});

it('menampilkan plating per menu dengan slot bernomor dan mengekspornya dalam dua sheet', function () {
    $data = siapkanProduksi();
    $order = app(ProductionOrderService::class)->generateFromSpk($data['spk']);
    RecipeItem::query()->where('recipe_id', $data['recipe']->id)->first()->update(['section' => 'Adonan']);

    $sheets = (new PlatingExport($order))->sheets();
    $global = barisSheet($sheets[0]);
    $perMenu = barisSheet($sheets[1]);

    expect($sheets[0]->title())->toBe('Komponen Global')
        ->and($sheets[1]->title())->toBe('Komponen Per Menu')
        ->and($global[1])->toBe([1, 'GORENGAN 10K', 80.0, 'porsi', 'Adonan', $global[1][5]])
        ->and(end($global)[1])->toBe('Total Produksi')
        // Kartu Gorengan: judul + minimal 8 slot, slot 1 berisi komponennya.
        ->and($perMenu[1])->toBe([1, 'Adonan'])
        ->and(array_slice($perMenu, 1, PlatingService::MIN_SLOTS))->toHaveCount(8)
        ->and($perMenu[8])->toBe([8, '']);

    $this->actingAs(penggunaParitas('admin'));
    Livewire::test(PlatingSheet::class, ['record' => $order->id])
        ->assertDontSeeHtml('sh-plate-card')
        ->callAction('tampilan_kartu')
        ->assertSet('tampilan', 'kartu')
        ->assertSeeHtml('sh-plate-card');
});

it('mengekspor breakdown bahan dan lembar kerja ke Excel', function () {
    $data = siapkanProduksi();
    $order = app(ProductionOrderService::class)->generateFromSpk($data['spk']);
    $order->tasks()->create(['sort_order' => 1, 'recipe_id' => $data['recipe']->id, 'menu_label' => 'Gorengan', 'worker_name' => 'Mia', 'task' => 'goreng', 'object' => 'adonan', 'quantity_text' => '2 kg']);

    $sheets = (new MaterialBreakdownExport(app(MaterialBreakdownService::class)->forProductionOrder($order)))->sheets();
    $rekap = barisSheet($sheets[0]);
    $menu = barisSheet($sheets[1]);

    expect($rekap[0][0])->toBe('Bahan')
        ->and(collect($rekap)->pluck(0)->all())->toContain('Tepung Terigu', 'Minyak Goreng', 'BELUM ADA RESEP: ES TEH (20 cup)')
        ->and($menu[1][0])->toBe('Gorengan')
        ->and(trim($menu[2][0]))->toBe('Minyak Goreng');

    expect((new ProductionTasksExport($order))->collection()->first())->toBe([1, 'Mia', 'Gorengan', 'goreng', 'adonan', '2 kg', '']);

    // Admin mengunduh Excel breakdown dari Detail PO.
    $this->actingAs(penggunaParitas('admin'))
        ->get(route('adminapp.orders.breakdown.excel', $data['pos'][0]))
        ->assertOk()
        ->assertDownload('kebutuhan-bahan-'.$data['pos'][0]->po_number.'.xlsx');
});

it('mengimpor template Pekerjaan Menu dari berkas Master Menu maupun export 3S ONE', function () {
    $data = siapkanProduksi();
    $data['recipe']->update(['source_recipe_id' => 77]);
    $sambal = Recipe::query()->create(['name' => 'Sambal Bawang', 'jenis' => Recipe::JENIS_SUB, 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25]);
    RecipeTask::query()->create(['recipe_id' => $sambal->id, 'sort_order' => 1, 'task' => 'lama', 'object' => 'dibuang']);

    // Format export Master Menu: recipe_id = id Master Menu; menu kosong mewarisi baris atasnya.
    $result = app(RecipeTaskImporter::class)->import([[
        ['recipe_id', 'Menu', 'Apa yang Dikerjakan', 'Objek', 'Jumlah', 'PIC'],
        [77, 'Gorengan lama', 'timbang', 'tepung', '250 gr', 'Tini'],
        ['', '', 'goreng', 'adonan', null, 'tono / indra'],
        ['', 'sambal bawang', 'ulek', 'cabai', '1 kg', 'Mia'],
        ['', 'Menu Hantu', 'potong', 'apa saja', '', ''],
        // Nama berbeda yang jatuh ke resep yang sudah terisi (Gorengan) dilewati.
        ['', 'Gorengan', 'ulek lagi', '', '', ''],
    ]]);

    expect($result)->toMatchArray(['menus_matched' => 2, 'tasks_inserted' => 3, 'unmatched' => ['Menu Hantu']])
        ->and($result['duplicates'])->toBe(['Gorengan → Gorengan'])
        ->and($data['recipe']->tasks()->pluck('task')->all())->toBe(['timbang', 'goreng'])
        // Template lama diganti, bukan ditambah.
        ->and($sambal->tasks()->pluck('task')->all())->toBe(['ulek'])
        // PIC tunggal jadi pelaksana; gabungan "tono / indra" tidak.
        ->and(ProductionWorker::query()->pluck('name')->all())->toContain('Tini', 'Mia')
        ->and(ProductionWorker::query()->where('name', 'tono / indra')->exists())->toBeFalse();

    // Bolak-balik dengan export 3S ONE (id_resep = id 3S ONE) menghasilkan template yang sama.
    $export = (new RecipeTasksExport)->collection()->map(fn ($row) => array_values($row))->all();
    $ulang = app(RecipeTaskImporter::class)->import([array_merge([(new RecipeTasksExport)->headings()], $export)]);
    expect($ulang)->toMatchArray(['menus_matched' => 2, 'tasks_inserted' => 3, 'unmatched' => []])
        ->and($data['recipe']->tasks()->pluck('pic')->all())->toBe(['Tini', 'tono / indra']);

    expect(fn () => app(RecipeTaskImporter::class)->import([[['a', 'b'], ['c', 'd']]]))
        ->toThrow(RuntimeException::class, 'Data tidak ditemukan');
});

it('membuka halaman Pekerjaan Menu untuk pemegang recipe.view dan menawarkan tugas yang pernah dipakai', function () {
    $data = siapkanProduksi();
    RecipeTask::query()->create(['recipe_id' => $data['recipe']->id, 'sort_order' => 1, 'task' => 'Goreng', 'object' => 'adonan', 'pic' => 'Mia']);
    $order = app(ProductionOrderService::class)->generateFromSpk($data['spk']);
    $order->tasks()->create(['sort_order' => 1, 'task' => 'goreng']);
    $order->tasks()->create(['sort_order' => 2, 'task' => 'Packing']);

    // Tanpa duplikat beda huruf besar/kecil.
    expect(ProductionTask::taskOptions())->toBe(['Goreng', 'Packing']);

    $this->actingAs(penggunaParitas('admin'))->get('/menu/pekerjaan-menu')
        ->assertOk()->assertSee('Pekerjaan Menu')->assertSee('Gorengan')->assertSee('Goreng adonan — Mia');

    Livewire::test(RecipeTaskTemplates::class)
        ->filterTable('punya_template', true)
        ->assertCanSeeTableRecords([$data['recipe']]);
});

it('menolak halaman Pekerjaan Menu untuk peran tanpa recipe.view', function () {
    $this->actingAs(penggunaParitas('sales'))->get('/menu/pekerjaan-menu')->assertForbidden();
});
