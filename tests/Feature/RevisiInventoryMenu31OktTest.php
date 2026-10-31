<?php

use App\Filament\Menu\Resources\RecipeResource;
use App\Filament\Pages\ModuleSettings;
use App\Filament\Resources\ProductionOrderResource\Pages\RequisitionForm;
use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\InventoryUnitConversion;
use App\Models\ProductionWorker;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeTask;
use App\Models\User;
use App\Services\ProductionDocumentService;
use App\Services\ProductionOrderService;
use App\Services\RecipeCostService;
use App\Services\RequisitionService;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\Settings;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Revisi 31 Okt 2026 dari user:
 * 1-2. Pengaturan Inventory dilipat per seksi; tanggal kategori "Mengurangi
 *      Kekayaan" (Biaya Marketing) pindah ke Accounting › Kategori Pengeluaran.
 * 3.   Nama pemeriksa barang masuk (orang tanpa akun) dipilih saat Periksa,
 *      digabung ke master Pelaksana.
 * 4.   Cetak Form Kebutuhan mendatar dan hanya bahan yang dipilih.
 * 5.   Satuan baris resep dibatasi pada yang bisa dihitung; rincian bahan
 *      dikelompokkan per komponen seperti Master Menu Revamp.
 */
function penggunaRevisi(string $role): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

/** @return array<string, mixed> form kebutuhan yang sudah disetujui gudang */
function formRevisiDisetujui(): array
{
    $d = siapkanProduksi();
    $order = app(ProductionOrderService::class)->generateFromSpk($d['spk']);
    $service = app(RequisitionService::class);
    $requisition = $service->build($order)['requisition'];

    // Tepung habis (perlu dibeli), minyak masih cukup (tidak perlu dibeli).
    foreach ($requisition->lines as $line) {
        $service->fillOpeningStock($line, $line->inventory_item_id === $d['tepung']->id ? 0 : 100);
    }

    $service->submit($requisition->fresh());
    $service->approve($requisition->fresh());

    return $d + ['order' => $order, 'requisition' => $requisition->fresh(), 'service' => $service];
}

// ---------- 1-2. Pengaturan ----------

it('tidak lagi menampilkan atau menyimpan tanggal Mengurangi Kekayaan di Pengaturan Inventory', function () {
    expect(SettingRegistry::appOf('wealth_reduction.accounting_start'))->toBe(SettingRegistry::APP_ACCOUNTING)
        ->and(collect(SettingRegistry::grouped())->flatten()->all())->not->toContain('wealth_reduction.accounting_start')
        ->and(SettingRegistry::grouped(SettingRegistry::APP_ACCOUNTING))->toBe(['Laporan Keuangan' => ['wealth_reduction.accounting_start']]);

    $this->actingAs(penggunaRevisi('owner'));

    // Kunci accounting yang diselundupkan lewat state halaman inventory diabaikan,
    // sementara pengaturan inventory di sebelahnya tetap tersimpan (kontrol positif).
    expect(ModuleSettings::fromState([
        ModuleSettings::stateKey('wealth_reduction.accounting_start') => '2026-12-01',
        ModuleSettings::stateKey('recipe.default_ohc_pct') => 35,
    ]))->toBe(['recipe.default_ohc_pct' => 35]);

    Livewire::test(ModuleSettings::class)
        ->assertOk()
        ->assertDontSee('Laporan Keuangan')
        ->assertSee('Resep &amp; HPP', false)
        ->fillForm([ModuleSettings::stateKey('recipe.default_ohc_pct') => 35])
        ->call('save')
        ->assertNotified('Pengaturan tersimpan');

    expect(app(Settings::class)->get('recipe.default_ohc_pct'))->toBe(35.0)
        ->and(app(Settings::class)->get('wealth_reduction.accounting_start'))->toBe('2026-10-01');
});

it('mengatur tanggal Mengurangi Kekayaan dari Accounting › Kategori Pengeluaran', function () {
    $accounting = penggunaRevisi('accounting');

    $this->actingAs($accounting)
        ->get(route('accountingapp.categories.index'))
        ->assertOk()
        ->assertSee('Kategori "Mengurangi Kekayaan" berlaku mulai', false)
        ->assertSee('value="2026-10-01"', false);

    // Tanggal tidak sah ditolak, nilai lama tetap.
    $this->actingAs($accounting)
        ->from(route('accountingapp.categories.index'))
        ->put(route('accountingapp.categories.settings'), ['wealth_reduction_accounting_start' => '31/12/2026'])
        ->assertSessionHasErrors('wealth_reduction_accounting_start');
    expect(app(Settings::class)->get('wealth_reduction.accounting_start'))->toBe('2026-10-01');

    $this->actingAs($accounting)
        ->put(route('accountingapp.categories.settings'), ['wealth_reduction_accounting_start' => '2026-11-01'])
        ->assertRedirect(route('accountingapp.categories.index'))
        ->assertSessionHas('success');

    app(Settings::class)->flush();
    expect(app(Settings::class)->get('wealth_reduction.accounting_start'))->toBe('2026-11-01')
        ->and(AuditLog::query()->where('entity', 'app_setting')->where('user_id', $accounting->id)->exists())->toBeTrue();

    // Peran di luar accounting tidak bisa mengubahnya.
    $this->actingAs(penggunaRevisi('sales'))
        ->put(route('accountingapp.categories.settings'), ['wealth_reduction_accounting_start' => '2026-12-01'])
        ->assertForbidden();
    app(Settings::class)->flush();
    expect(app(Settings::class)->get('wealth_reduction.accounting_start'))->toBe('2026-11-01');
});

// ---------- 3. Pemeriksa barang masuk ----------

it('memisahkan pilihan pelaksana dan pemeriksa dari satu master', function () {
    ProductionWorker::query()->create(['name' => 'Bu Ani', 'is_executor' => true, 'is_inspector' => false]);
    ProductionWorker::query()->create(['name' => 'Pak Slamet', 'is_executor' => false, 'is_inspector' => true]);
    ProductionWorker::query()->create(['name' => 'Mas Budi', 'is_executor' => true, 'is_inspector' => true]);
    ProductionWorker::query()->create(['name' => 'Lama', 'is_executor' => true, 'is_inspector' => true, 'is_active' => false]);

    expect(ProductionWorker::options())->toBe(['Bu Ani' => 'Bu Ani', 'Mas Budi' => 'Mas Budi'])
        ->and(ProductionWorker::inspectorOptions())->toBe(['Mas Budi' => 'Mas Budi', 'Pak Slamet' => 'Pak Slamet']);

    // Nama baru dari modal Periksa masuk master sebagai pemeriksa; nama
    // pelaksana yang sudah ada hanya ditandai, tidak digandakan.
    expect(RequisitionForm::registerInspector(' bu ani '))->toBe('Bu Ani')
        ->and(RequisitionForm::registerInspector('Mbak Rini'))->toBe('Mbak Rini')
        ->and(ProductionWorker::query()->count())->toBe(5)
        ->and(ProductionWorker::query()->where('name', 'Bu Ani')->value('is_inspector'))->toBeTrue()
        ->and(ProductionWorker::query()->where('name', 'Mbak Rini')->first()->only(['is_executor', 'is_inspector']))
        ->toBe(['is_executor' => false, 'is_inspector' => true]);
});

it('mencatat nama pemeriksa terpisah dari akun yang mencatat, sampai ke PDF', function () {
    $d = formRevisiDisetujui();
    $gudang = penggunaRevisi('inventory');
    ProductionWorker::query()->create(['name' => 'Pak Slamet', 'is_executor' => false, 'is_inspector' => true]);
    bayarTunai($d['requisition']);

    // Nama di luar daftar pemeriksa ditolak.
    Livewire::actingAs($gudang)
        ->test(RequisitionForm::class, ['record' => $d['order']->id])
        ->callAction('periksa', ['inspector_name' => 'Orang Asing'])
        ->assertHasActionErrors(['inspector_name']);
    expect($d['requisition']->fresh()->isApproved())->toBeTrue();

    Livewire::actingAs($gudang)
        ->test(RequisitionForm::class, ['record' => $d['order']->id])
        ->callAction('periksa', ['inspector_name' => 'Pak Slamet'])
        ->assertHasNoActionErrors()
        ->assertSee('Diperiksa: Pak Slamet (dicatat '.$gudang->name.')');

    $requisition = $d['requisition']->fresh();
    expect($requisition->isChecked())->toBeTrue()
        ->and($requisition->inspector_name)->toBe('Pak Slamet')
        ->and($requisition->checked_by)->toBe($gudang->id);

    $html = view('pdf.requisition', ['requisition' => $requisition->load(['lines', 'productionOrder', 'checkedBy'])])->render();
    expect($html)->toContain('Pak Slamet')->toContain('dicatat '.$gudang->name);
});

// ---------- 4. Cetak Form Kebutuhan ----------

it('mencetak hanya bahan yang dicentang, bawaannya yang masih perlu dibeli', function () {
    $d = formRevisiDisetujui();
    $requisition = $d['requisition']->load('lines');
    $tepung = $requisition->lines->firstWhere('inventory_item_id', $d['tepung']->id);
    $minyak = $requisition->lines->firstWhere('inventory_item_id', $d['minyak']->id);

    // Minyak masih cukup di stok -> tidak dicentang bawaan.
    expect(app(ProductionDocumentService::class)->defaultPrintLineIds($requisition))->toBe([$tepung->id]);

    $requisition->load(['productionOrder', 'preparedBy', 'approvedBy', 'checkedBy', 'purchaseBill']);
    $sebagian = view('pdf.requisition', ['requisition' => $requisition, 'lines' => $requisition->lines->whereIn('id', [$tepung->id])->values()])->render();
    $semua = view('pdf.requisition', ['requisition' => $requisition])->render();

    expect($sebagian)->toContain('Tepung Terigu')->not->toContain('Minyak Goreng')->toContain('Dicetak <b>1</b> dari 2 bahan')
        ->and($semua)->toContain('Tepung Terigu')->toContain('Minyak Goreng')->not->toContain('Dicetak');

    $this->actingAs(penggunaRevisi('inventory'));
    Livewire::test(RequisitionForm::class, ['record' => $d['order']->id])
        ->mountAction('cetak')
        ->assertActionDataSet(['lines' => [$tepung->id]])
        ->setActionData(['lines' => []])
        ->callMountedAction()
        ->assertHasActionErrors(['lines' => 'required']);

    Livewire::test(RequisitionForm::class, ['record' => $d['order']->id])
        ->callAction('cetak', ['lines' => [$tepung->id, $minyak->id]])
        ->assertHasNoActionErrors()
        ->assertFileDownloaded($requisition->number.'.pdf');
});

// ---------- 5. Satuan & tampilan resep ----------

it('hanya menawarkan satuan yang bisa dihitung ke satuan harga bahan atau hasil sub-menu', function () {
    $ayam = InventoryItem::query()->create(['name' => 'Ayam Utuh', 'unit' => 'ekor', 'unit_price' => 40000, 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);
    $gula = InventoryItem::query()->create(['name' => 'Gula Pasir', 'unit' => 'kg', 'unit_price' => 17600, 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);
    InventoryUnitConversion::query()->create(['inventory_item_id' => $ayam->id, 'from_unit' => 'ekor', 'to_unit' => 'potong', 'factor' => 8]);
    $sub = Recipe::query()->create(['name' => 'Ayam Goreng Racik', 'jenis' => Recipe::JENIS_SUB, 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25]);

    $opsiGula = RecipeResource::lineUnitOptions($gula->id, null, 'gram');
    expect(array_keys($opsiGula['Bisa dihitung']))->toEqualCanonicalizing(['kg', 'gram'])
        ->and($opsiGula['Bisa dihitung']['gram'])->toBe('gram — 1 kg = 1000 gram')
        ->and($opsiGula)->not->toHaveKey('Satuan sekarang — tidak terhitung');

    // Konversi yang sudah diinput ikut ditawarkan beserta bacaannya.
    $opsiAyam = RecipeResource::lineUnitOptions($ayam->id, null, 'potong');
    expect(array_keys($opsiAyam['Bisa dihitung']))->toEqualCanonicalizing(['ekor', 'potong'])
        ->and($opsiAyam['Bisa dihitung']['potong'])->toBe('potong — 1 ekor = 8 potong');

    // Satuan lama yang salah tetap terlihat, ditandai, dan dijelaskan.
    $opsiSub = RecipeResource::lineUnitOptions(null, $sub->id, 'potong');
    expect(array_keys($opsiSub['Bisa dihitung']))->toBe(['porsi'])
        ->and($opsiSub['Satuan sekarang — tidak terhitung'])->toBe(['potong' => 'potong — tidak bisa dihitung'])
        ->and(RecipeResource::lineUnitHint(null, $sub->id, 'potong'))->toContain('tidak bisa dihitung')
        ->and(RecipeResource::lineUnitHint(null, $sub->id, 'porsi'))->not->toContain('tidak bisa dihitung');

    // Baris yang belum menunjuk apa pun tetap bebas memilih satuan.
    expect(RecipeResource::lineUnitOptions(null, null, null))->toHaveKey('Satuan Hitung');
});

it('menghitung harga baris dari isian form dan mengelompokkan rincian bahan per komponen', function () {
    Role::findOrCreate('menu', 'web');
    $this->actingAs(penggunaRevisi('menu'));

    $cabe = InventoryItem::query()->create(['name' => 'Cabe Merah', 'unit' => 'kg', 'unit_price' => 65000, 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);
    $plastik = InventoryItem::query()->create(['name' => 'Plastik Pembatas', 'unit' => 'lembar', 'unit_price' => 50, 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);
    $sub = Recipe::query()->create(['name' => 'Ayam Goreng Racik', 'jenis' => Recipe::JENIS_SUB, 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25]);
    $menu = Recipe::query()->create(['name' => 'Nasi Ayam Balado', 'jenis' => Recipe::JENIS_UTAMA, 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25]);

    // Urutan berselang seperti data MMR: kemasan di tengah bahan Ayam Balado.
    RecipeItem::query()->create(['recipe_id' => $menu->id, 'sort_order' => 1, 'section' => 'Ayam Balado', 'inventory_item_id' => $cabe->id, 'raw_name' => 'cabe merah', 'qty' => 4, 'unit' => 'gram']);
    RecipeItem::query()->create(['recipe_id' => $menu->id, 'sort_order' => 2, 'section' => 'Kemasan', 'inventory_item_id' => $plastik->id, 'raw_name' => 'Plastik Pembatas', 'qty' => 1, 'unit' => 'lembar']);
    RecipeItem::query()->create(['recipe_id' => $menu->id, 'sort_order' => 3, 'section' => 'Ayam Balado', 'ref_recipe_id' => $sub->id, 'raw_name' => 'Ayam Goreng Racik', 'qty' => 1, 'unit' => 'potong']);

    // Pratinjau memakai isian yang belum disimpan: 4 gram x Rp 65/gram.
    $preview = RecipeResource::linePreview(['raw_name' => 'cabe', 'inventory_item_id' => (string) $cabe->id, 'qty' => '4', 'unit' => 'gr'], $menu->id);
    expect($preview['line_total'])->toBe(260.0)
        ->and($preview['linked_name'])->toBe('Cabe Merah')
        ->and($preview['issue'])->toBeNull()
        ->and(RecipeResource::linePreview(['raw_name' => 'cabe', 'inventory_item_id' => $cabe->id, 'qty' => 4, 'unit' => 'ikat'], $menu->id)['issue'])
        ->toContain('tidak bisa dikonversi');

    expect(collect(app(RecipeCostService::class)->cost($menu)['lines'])->pluck('section')->all())
        ->toBe(['Ayam Balado', 'Kemasan', 'Ayam Balado']);

    // Halaman edit: satu blok per kelompok (Ayam Balado 2 baris, 1 belum terhitung).
    Livewire::test(RecipeResource\Pages\EditRecipe::class, ['record' => $menu->getRouteKey()])
        ->assertOk()
        ->assertSeeInOrder(['Ayam Balado', '2 baris', '1 belum terhitung', 'cabe merah', 'Ayam Goreng Racik', 'Kemasan', '1 baris', 'Plastik Pembatas'])
        ->assertSee('Rp 260,00')
        ->assertSee('tidak sepadan dengan satuan hasil');

    $this->get(RecipeResource::getUrl('hpp', ['record' => $menu]))
        ->assertOk()
        ->assertSeeInOrder(['Ayam Balado', '2 baris', 'cabe merah', 'Ayam Goreng Racik', 'Kemasan', '1 baris', 'Plastik Pembatas']);

    // Menyimpan tanpa perubahan tidak menggeser takaran / satuan.
    Livewire::test(RecipeResource\Pages\EditRecipe::class, ['record' => $menu->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();
    expect(RecipeItem::query()->where('recipe_id', $menu->id)->orderBy('id')->pluck('unit')->all())->toBe(['gram', 'lembar', 'potong']);
});

it('mengisi satuan harga bahan saat bahan dipilih pada baris tanpa satuan', function () {
    Role::findOrCreate('menu', 'web');
    $this->actingAs(penggunaRevisi('menu'));
    $gula = InventoryItem::query()->create(['name' => 'Gula Pasir', 'unit' => 'kg', 'unit_price' => 17600, 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);
    $menu = Recipe::query()->create(['name' => 'Es Teh', 'jenis' => Recipe::JENIS_UTAMA, 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25]);
    $baris = RecipeItem::query()->create(['recipe_id' => $menu->id, 'raw_name' => 'gula', 'qty' => 10, 'unit' => null]);
    $terisi = RecipeItem::query()->create(['recipe_id' => $menu->id, 'raw_name' => 'gula lagi', 'qty' => 5, 'unit' => 'gram']);

    $page = Livewire::test(RecipeResource\Pages\EditRecipe::class, ['record' => $menu->getRouteKey()]);
    $items = $page->get('data.items');
    $kosong = collect($items)->search(fn ($row) => $row['raw_name'] === 'gula');
    $berisi = collect($items)->search(fn ($row) => $row['raw_name'] === 'gula lagi');

    $page->set("data.items.{$kosong}.inventory_item_id", $gula->id)
        ->set("data.items.{$berisi}.inventory_item_id", $gula->id)
        ->assertSet("data.items.{$kosong}.unit", 'kg')
        // Satuan yang sudah diisi tidak ditimpa (kontrol).
        ->assertSet("data.items.{$berisi}.unit", 'gram');

    expect(RecipeTask::query()->count())->toBe(0); // tidak ada efek samping ke template kerja
    expect($baris->fresh()->unit)->toBeNull();
});
