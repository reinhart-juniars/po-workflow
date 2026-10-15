<?php

use App\Filament\Pages\ImportReadyStockPlan;
use App\Imports\ReadyStockPlanReader;
use App\Models\MenuNameAlias;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderLine;
use App\Models\Recipe;
use App\Models\User;
use App\Services\ReadyStockPlanMatcher;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;

/**
 * Revisi v3.1 -- impor Excel rencana masak mingguan klien. Bentuk berkas
 * tiruan di sini mengikuti berkas asli (12-17 Okt 2026): blok per hari
 * berdampingan, tanggal berupa rumus (=A3+1), baris total SUM, lalu pesanan
 * khusus customer di bawahnya.
 */
function excelRencanaMasak(): string
{
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->setCellValue('A1', 'rencana masak 19 - 20 Oktober 2026');

    // Senin (kiri) dan Selasa (kanan; tanggalnya rumus dari Senin).
    $sheet->setCellValue('A3', ExcelDate::PHPToExcel(new DateTime('2026-10-19')));
    $sheet->setCellValue('C3', 'senin');
    $sheet->fromArray([
        ['Nasi Goreng Crabstik', 10000, 35],
        ['Ricebowl Dory Cabe Garam', 15000, 25],
        ['Nasi Ayam Teriyaki', 12000, 30],
        ['Nasi Telur Kribo Sambal Tomat', 12000, 20],
    ], null, 'A4');
    $sheet->setCellValue('C8', '=SUM(C4:C7)');
    $sheet->fromArray([['nasi uduk ayam bakar ITS - berangkat jam 9', 31500, 24]], null, 'A9');

    $sheet->setCellValue('E3', '=A3+1');
    $sheet->setCellValue('G3', 'selasa ');
    $sheet->fromArray([
        ['Nasi Goreng Crabstik', 10000, 40],
        ['Nasi Ayam Teriyaki', 18000, 10],
    ], null, 'E4');
    $sheet->setCellValue('E6', 'pesanan');
    $sheet->setCellValue('G6', '=SUM(G4:G5)');
    $sheet->setCellValue('E7', 'Open PO IGA BAKAR');

    $path = tempnam(sys_get_temp_dir(), 'rm').'.xlsx';
    (new Xlsx($book))->save($path);

    return $path;
}

function siapkanMenuImpor(): array
{
    $resep = fn (string $nama) => Recipe::query()->create(['name' => $nama, 'jenis' => Recipe::JENIS_UTAMA, 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0, 'profit_pct' => 0.25, 'is_active' => true]);
    $produk = function (string $nama, float $harga, ?Recipe $recipe = null) {
        $product = Product::query()->create(['name' => $nama, 'unit' => 'porsi', 'base_price' => $harga, 'active' => true]);
        $product->forceFill(['recipe_id' => $recipe?->id])->saveQuietly();

        return $product;
    };

    return [
        'crabstik' => $produk('NASI GORENG CRABSTIK 10K', 10000, $resep('Nasi Goreng Crabstik')),
        'dori' => $produk('RICE BOWL DORI CABE GARAM 15K', 15000, $resep('Ricebowl Dori')),
        'teriyaki12' => $produk('NASI AYAM TERIYAKI 12K', 12000), // tanpa resep
        'teriyaki18' => $produk('NASI AYAM TERIYAKI 18K', 18000, $resep('Nasi Ayam Teriyaki')),
    ];
}

function penggunaImpor(string $role): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

it('membaca blok per hari, tanggal rumus, total SUM, dan memisahkan pesanan khusus', function () {
    $days = (new ReadyStockPlanReader)->read(excelRencanaMasak());

    expect(array_column($days, 'date'))->toBe(['2026-10-19', '2026-10-20'])
        ->and(array_column($days, 'day'))->toBe(['senin', 'selasa'])
        ->and(array_column($days[0]['items'], 'name'))->toBe(['Nasi Goreng Crabstik', 'Ricebowl Dory Cabe Garam', 'Nasi Ayam Teriyaki', 'Nasi Telur Kribo Sambal Tomat'])
        ->and($days[0]['sheet_total'])->toBe(110.0)
        ->and(array_sum(array_column($days[0]['items'], 'qty')))->toEqual(110)
        ->and(array_column($days[0]['specials'], 'name'))->toBe(['nasi uduk ayam bakar ITS - berangkat jam 9'])
        // Label "pesanan" di baris total dilewati; baris tanpa qty jadi pesanan khusus.
        ->and(array_column($days[1]['items'], 'qty'))->toBe([40.0, 10.0])
        ->and(array_column($days[1]['specials'], 'name'))->toBe(['Open PO IGA BAKAR']);
});

it('mencocokkan nama + harga ke varian Master Menu yang tepat', function () {
    $menu = siapkanMenuImpor();
    $matcher = app(ReadyStockPlanMatcher::class);

    expect($matcher->match('Nasi Goreng Crabstik', 10000)['product_id'])->toBe($menu['crabstik']->id)
        // Ejaan dirapikan: Ricebowl/Rice Bowl, Dory/Dori.
        ->and($matcher->match('Ricebowl Dory Cabe Garam', 15000)['product_id'])->toBe($menu['dori']->id)
        // Harga menentukan varian.
        ->and($matcher->match('Nasi Ayam Teriyaki', 12000)['product_id'])->toBe($menu['teriyaki12']->id)
        ->and($matcher->match('Nasi Ayam Teriyaki', 18000)['product_id'])->toBe($menu['teriyaki18']->id);

    $miss = $matcher->match('Nasi Goreng Krabstik Spesial', 10000);
    expect($miss['product_id'])->toBeNull()
        ->and($miss['candidates'][0]['id'])->toBe($menu['crabstik']->id);
});

it('menyimpan Rencana Masak per tanggal dari Excel, mengingat pilihan manual, dan aman diimpor ulang', function () {
    $menu = siapkanMenuImpor();
    $gudang = penggunaImpor('inventory');
    $this->actingAs($gudang);
    $file = fn () => UploadedFile::fake()->createWithContent('rencana masak.xlsx', file_get_contents(excelRencanaMasak()));

    $page = Livewire::test(ImportReadyStockPlan::class)
        ->set('upload', $file())
        ->call('readFile')
        ->assertHasNoErrors()
        ->assertSee('Pesanan khusus')
        ->assertSee('nasi uduk ayam bakar ITS - berangkat jam 9');

    // "Nasi Telur Kribo Sambal Tomat" belum ada padanannya; dipilihkan manual ke produk berresep.
    $key = collect($page->get('preview')[0]['items'])->firstWhere('name', 'Nasi Telur Kribo Sambal Tomat')['key'];
    $page->set("choices.{$key}", $menu['crabstik']->id)->call('save')->assertRedirect();

    $plans = ProductionOrder::query()->readyStock()->orderBy('production_date')->with('lines')->get();
    expect($plans)->toHaveCount(2)
        ->and($plans[0]->title)->toBe('Rencana Masak Senin 19/10/2026')
        ->and($plans[0]->lines->map(fn ($line) => [$line->kind, $line->label, (float) $line->qty])->all())->toBe([
            [ProductionOrderLine::KIND_MENU, 'NASI GORENG CRABSTIK 10K', 35.0],
            [ProductionOrderLine::KIND_MENU, 'RICE BOWL DORI CABE GARAM 15K', 25.0],
            // Tanpa resep: tetap masuk rencana, tapi baris manual (tidak bisa didistribusikan).
            [ProductionOrderLine::KIND_MANUAL, 'NASI AYAM TERIYAKI 12K', 30.0],
            [ProductionOrderLine::KIND_MENU, 'NASI GORENG CRABSTIK 10K', 20.0],
        ])
        // Pesanan khusus tidak menjadi baris rencana.
        ->and($plans[0]->lines->pluck('label'))->not->toContain('nasi uduk ayam bakar ITS - berangkat jam 9')
        ->and(MenuNameAlias::query()->sole()->product_id)->toBe($menu['crabstik']->id);

    // Impor ulang (revisi Excel): pilihan manual sudah diingat, baris tidak dobel, baris manual dapur tetap.
    $plans[0]->lines()->create(['kind' => ProductionOrderLine::KIND_MANUAL, 'source' => ProductionOrderLine::SOURCE_MANUAL, 'label' => 'siapkan es batu', 'qty' => 1, 'unit' => 'karung', 'sort_order' => 99]);
    $again = Livewire::test(ImportReadyStockPlan::class)->set('upload', $file())->call('readFile');
    expect(collect($again->get('preview')[0]['items'])->firstWhere('name', 'Nasi Telur Kribo Sambal Tomat')['via'])->toBe('alias');
    $again->call('save');

    expect(ProductionOrder::query()->readyStock()->count())->toBe(2)
        ->and($plans[0]->fresh()->lines()->count())->toBe(5)
        ->and($plans[0]->fresh()->lines()->where('label', 'siapkan es batu')->exists())->toBeTrue();

    // Rencana yang sudah ditutup tidak diubah oleh impor.
    $plans[1]->forceFill(['status' => ProductionOrder::STATUS_COMPLETED])->save();
    Livewire::test(ImportReadyStockPlan::class)->set('upload', $file())->call('readFile')->call('save');
    expect($plans[1]->fresh()->lines()->count())->toBe(2)
        ->and(ProductionOrder::query()->readyStock()->count())->toBe(2);
});

it('menolak berkas bukan Excel dan membatasi impor ke pemegang plan.manage', function () {
    $this->actingAs(penggunaImpor('inventory'));
    Livewire::test(ImportReadyStockPlan::class)
        ->set('upload', UploadedFile::fake()->create('foto.jpg', 10, 'image/jpeg'))
        ->call('readFile')
        ->assertHasErrors('upload');

    $this->actingAs(penggunaImpor('sales'))->get(ImportReadyStockPlan::getUrl())->assertForbidden();
    // Kontrol positif: gudang membuka halaman yang sama.
    $this->actingAs(penggunaImpor('inventory'))->get(ImportReadyStockPlan::getUrl())->assertOk()->assertSee('Pilih berkas');
});
