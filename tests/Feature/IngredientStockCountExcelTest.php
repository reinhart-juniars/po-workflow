<?php

use App\Filament\Pages\IngredientStockCount;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\InventoryLedgerService;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Role;

/**
 * Opname Bahan lewat Excel: gudang mengekspor lembar cetak, menghitung di
 * gudang, mengisi kolom Hitung Fisik, lalu mengimpornya. Import hanya mengisi
 * form; kartu stok baru berubah setelah Simpan Opname.
 */
function opnameGudang(string $role = 'inventory'): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

/** @return array<string, InventoryItem> */
function bahanOpname(): array
{
    $baku = InventoryItem::query()->create(['name' => 'Bahan Baku Kering', 'unit' => 'All', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);
    $kemasan = InventoryItem::query()->create(['name' => 'Kemasan', 'unit' => 'All', 'category' => InventoryItem::CATEGORY_PACKAGING, 'is_active' => true]);
    $alat = InventoryItem::query()->create(['name' => 'Peralatan', 'unit' => 'All', 'category' => InventoryItem::CATEGORY_FIXED_ASSET, 'is_active' => true]);

    $make = fn (InventoryItem $parent, string $name, string $unit, ?float $price) => InventoryItem::query()->create([
        'parent_id' => $parent->id, 'name' => $name, 'unit' => $unit, 'unit_price' => $price,
        'category' => $parent->category, 'is_active' => true,
    ]);

    $d = [
        'tepung' => $make($baku, 'Tepung Terigu', 'kg', 12000),
        'gula' => $make($baku, 'Gula Pasir', 'kg', 15000),
        'box' => $make($kemasan, 'Box Nasi', 'pcs', 1000),
        'panci' => $make($alat, 'Panci Besar', 'pcs', 200000), // bukan bahan stok: tidak boleh diopname
    ];

    $ledger = app(InventoryLedgerService::class);
    $ledger->post($d['tepung']->id, InventoryMovement::TYPE_OPENING, 10, 'kg', '2026-11-01', 12000);
    $ledger->post($d['box']->id, InventoryMovement::TYPE_OPENING, 500, 'pcs', '2026-11-01', 1000);
    // Gula belum pernah tercatat di kartu stok.

    return $d;
}

/** Jalankan Export lewat tombolnya dan kembalikan berkas yang diunduh. */
function exportOpname($component): Spreadsheet
{
    $download = $component->callAction('export')->effects['download'];
    $path = tempnam(sys_get_temp_dir(), 'opname').'.xlsx';
    file_put_contents($path, base64_decode($download['content']));

    return tap(IOFactory::load($path), fn () => unlink($path));
}

/** Simpan lembar sebagai berkas unggahan. */
function unggahLembar(Spreadsheet $book, string $name = 'opname.xlsx'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'isi').'.xlsx';
    IOFactory::createWriter($book, 'Xlsx')->save($path);

    return tap(UploadedFile::fake()->createWithContent($name, file_get_contents($path)), fn () => unlink($path));
}

/** Nomor baris Excel untuk bahan tertentu di lembar ekspor. */
function barisBahan(Spreadsheet $book, InventoryItem $item): int
{
    $sheet = $book->getActiveSheet();
    for ($r = 6; $r <= $sheet->getHighestRow(); $r++) {
        if ((int) $sheet->getCell("B{$r}")->getValue() === $item->id) {
            return $r;
        }
    }

    throw new RuntimeException('Bahan '.$item->name.' tidak ada di lembar.');
}

/** Isi notifikasi Filament terakhir (judul + isi); notifikasi tidak ikut HTML halaman di tes Livewire. */
function isiNotifikasi(): string
{
    $last = collect(session('filament.notifications', []))->last() ?? [];

    return ($last['title'] ?? '').' '.($last['body'] ?? '');
}

beforeEach(fn () => $this->travelTo(now()->setDate(2026, 11, 27)->setTime(10, 0)));

it('mengekspor lembar cetak sesuai tanggal dan kategori, hanya bahan stok', function () {
    $d = bahanOpname();
    $this->actingAs(opnameGudang());

    $book = exportOpname(Livewire::test(IngredientStockCount::class)->set('data.count_date', '2026-11-20'));
    $sheet = $book->getActiveSheet();

    expect(Date::excelToDateTimeObject($sheet->getCell('B2')->getValue())->format('Y-m-d'))->toBe('2026-11-20')
        ->and($sheet->getCell('B3')->getValue())->toBe('Semua')
        ->and($sheet->rangeToArray('A5:I5')[0])->toBe(['No', 'ID', 'Kategori', 'Bahan', 'Satuan', 'Saldo Kartu Stok', 'Hitung Fisik', 'Selisih', 'Catatan'])
        // Urut per kategori lalu nama; peralatan tidak ikut.
        ->and(array_column($sheet->rangeToArray('C6:D8'), 1))->toBe(['Gula Pasir', 'Tepung Terigu', 'Box Nasi'])
        ->and($sheet->getHighestDataRow())->toBe(8)
        // Gula belum tercatat: saldo kosong (hitungan pertama jadi saldo awal).
        ->and($sheet->getCell('F'.barisBahan($book, $d['gula']))->getValue())->toBeNull()
        ->and((float) $sheet->getCell('F'.barisBahan($book, $d['tepung']))->getValue())->toBe(10.0)
        ->and($sheet->getPageSetup()->getFitToWidth())->toBe(1)
        ->and($sheet->getPageSetup()->getRowsToRepeatAtTop())->toEqual([5, 5]);

    // Selisih dihitung Excel begitu Hitung Fisik diisi.
    $r = barisBahan($book, $d['tepung']);
    $sheet->setCellValue("G{$r}", 9.5);
    expect($sheet->getCell("H{$r}")->getCalculatedValue())->toEqual(-0.5);

    // Filter kategori ikut ke lembar.
    $kemasan = InventoryItem::query()->buckets()->where('name', 'Kemasan')->value('id');
    $sheet = exportOpname(Livewire::test(IngredientStockCount::class)->set('data.bucket_id', $kemasan))->getActiveSheet();
    expect($sheet->getCell('B3')->getValue())->toBe('Kemasan')
        ->and($sheet->getHighestDataRow())->toBe(6)
        ->and($sheet->getCell('D6')->getValue())->toBe('Box Nasi');
});

it('menampilkan pratinjau import dulu, lalu menyimpan ke kartu stok dari pratinjau', function () {
    $d = bahanOpname();
    $d['minyak'] = InventoryItem::query()->create([
        'parent_id' => $d['tepung']->parent_id, 'name' => 'Minyak Goreng', 'unit' => 'liter', 'unit_price' => 20000,
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true,
    ]);
    app(InventoryLedgerService::class)->post($d['minyak']->id, InventoryMovement::TYPE_OPENING, 4, 'liter', '2026-11-01', 20000);
    $gudang = opnameGudang();
    $this->actingAs($gudang);

    $book = exportOpname(Livewire::test(IngredientStockCount::class)->set('data.count_date', '2026-11-25'));
    $sheet = $book->getActiveSheet();
    $sheet->setCellValue('G'.barisBahan($book, $d['tepung']), 9.5);      // hilang 0,5 kg x 12.000 = 6.000
    $sheet->setCellValue('G'.barisBahan($book, $d['minyak']), 5);        // temuan 1 liter x 20.000
    $sheet->setCellValue('G'.barisBahan($book, $d['gula']), '3,25');     // ditulis cara Indonesia; saldo awal
    // Box dibiarkan kosong: tidak diubah.

    $movements = InventoryMovement::query()->count();

    $page = Livewire::test(IngredientStockCount::class)
        ->set('data.count_date', '2026-11-27')
        ->set('data.notes', 'opname akhir bulan')
        ->callAction('import', ['berkas' => unggahLembar($book, 'opname-gudang.xlsx')])
        ->assertHasNoActionErrors()
        ->assertSet('importCounts', [$d['tepung']->id => '9.5', $d['minyak']->id => '5', $d['gula']->id => '3.25'])
        ->assertSet('importDate', '2026-11-25') // tanggal dari berkas, bukan form
        ->assertSet('importBlank', 1)
        ->assertSet('importNotes', 'opname akhir bulan')
        ->assertSee('Pratinjau import: opname-gudang.xlsx')
        ->assertSee('Rp 6.000')
        ->assertSee('Rp 20.000')
        // Urutan: hilang, temuan, saldo awal.
        ->assertSeeInOrder(['Tepung Terigu', 'Minyak Goreng', 'Gula Pasir'])
        // Grid isian manual disembunyikan selama pratinjau.
        ->assertDontSee('Box Nasi');

    $preview = $page->instance()->getImportPreview();
    expect($preview['summary'])->toMatchArray(['hilang' => 1, 'hilang_value' => 6000.0, 'temuan' => 1, 'temuan_value' => 20000.0, 'saldo_awal' => 1, 'sesuai' => 0])
        ->and($preview['rows']->pluck('status')->all())->toBe(['hilang', 'temuan', 'saldo_awal']);

    // Pratinjau belum menyimpan apa pun.
    expect(InventoryMovement::query()->count())->toBe($movements);

    $page->call('saveImport')->assertHasNoErrors()
        ->assertNotified('Opname bahan tersimpan')
        ->assertSet('importCounts', [])
        ->assertSee('Box Nasi'); // kembali ke grid

    $hilang = InventoryMovement::query()->where('type', InventoryMovement::TYPE_ADJUSTMENT)->where('inventory_item_id', $d['tepung']->id)->sole();
    expect((float) $hilang->qty)->toBe(-0.5)
        ->and($hilang->moved_at->toDateString())->toBe('2026-11-25')
        ->and($hilang->created_by)->toBe($gudang->id)
        ->and($hilang->notes)->toBe('Opname bahan 25/11/2026 — opname akhir bulan')
        ->and((float) InventoryMovement::query()->where('type', InventoryMovement::TYPE_ADJUSTMENT)->where('inventory_item_id', $d['minyak']->id)->sole()->qty)->toBe(1.0)
        ->and((float) InventoryMovement::query()->where('inventory_item_id', $d['gula']->id)->sole()->qty)->toBe(3.25)
        ->and(InventoryMovement::query()->where('inventory_item_id', $d['box']->id)->count())->toBe(1); // hanya saldo awal
});

it('membuang pratinjau saat Batal tanpa mencatat apa pun', function () {
    $d = bahanOpname();
    $this->actingAs(opnameGudang());

    $book = exportOpname(Livewire::test(IngredientStockCount::class));
    $book->getActiveSheet()->setCellValue('G'.barisBahan($book, $d['tepung']), 2);
    $movements = InventoryMovement::query()->count();

    Livewire::test(IngredientStockCount::class)
        ->callAction('import', ['berkas' => unggahLembar($book)])
        ->assertSee('Pratinjau import')
        ->call('cancelImport')
        ->assertSet('importCounts', [])
        ->assertDontSee('Pratinjau import')
        ->assertSee('Box Nasi')
        // Simpan tanpa pratinjau aktif tidak melakukan apa-apa.
        ->call('saveImport');

    expect(InventoryMovement::query()->count())->toBe($movements);
});

it('membatalkan import bila ada baris bermasalah, tanpa pratinjau', function (Closure $rusak, string $pesan) {
    $d = bahanOpname();
    $this->actingAs(opnameGudang());

    $book = exportOpname(Livewire::test(IngredientStockCount::class));
    $sheet = $book->getActiveSheet();
    $sheet->setCellValue('G'.barisBahan($book, $d['tepung']), 9);
    $rusak($sheet, $book, $d);

    Livewire::test(IngredientStockCount::class)
        ->set('counts', [$d['box']->id => '480'])
        ->callAction('import', ['berkas' => unggahLembar($book)])
        ->assertSet('importCounts', [])
        ->assertSet('counts', [$d['box']->id => '480']); // isian manual tidak tersentuh

    expect(isiNotifikasi())->toContain('Import dibatalkan, tidak ada yang berubah')->toContain($pesan);

    // Kontrol positif: lembar yang sama tanpa baris rusak masuk pratinjau.
    $bersih = exportOpname(Livewire::test(IngredientStockCount::class));
    $bersih->getActiveSheet()->setCellValue('G'.barisBahan($bersih, $d['tepung']), 9);
    Livewire::test(IngredientStockCount::class)
        ->callAction('import', ['berkas' => unggahLembar($bersih)])
        ->assertSet('importCounts', [$d['tepung']->id => '9']);
})->with([
    'bukan angka' => [fn ($sheet, $book, $d) => $sheet->setCellValue('G'.barisBahan($book, $d['gula']), 'dua'), 'bukan angka'],
    'negatif' => [fn ($sheet, $book, $d) => $sheet->setCellValue('G'.barisBahan($book, $d['gula']), -1), 'tidak boleh negatif'],
    'ID dobel' => [fn ($sheet, $book, $d) => $sheet->fromArray([[99, $d['tepung']->id, '', 'Tepung lagi', 'kg', '', 2]], null, 'A20'), 'muncul dua kali'],
    'bahan bukan stok' => [fn ($sheet, $book, $d) => $sheet->fromArray([[99, $d['panci']->id, '', 'Panci', 'pcs', '', 1]], null, 'A20'), 'tidak ada di daftar'],
]);

it('menolak berkas yang bukan lembar opname dan berkas tanpa isian', function () {
    $d = bahanOpname();
    $this->actingAs(opnameGudang());

    $lain = new Spreadsheet;
    $lain->getActiveSheet()->fromArray([['Nama Barang', 'Stok'], ['Tepung', 3]]);

    Livewire::test(IngredientStockCount::class)
        ->callAction('import', ['berkas' => unggahLembar($lain)])
        ->assertSet('importCounts', []);
    expect(isiNotifikasi())->toContain('Import dibatalkan')->toContain('tidak ditemukan');

    Livewire::test(IngredientStockCount::class)
        ->callAction('import', ['berkas' => unggahLembar(exportOpname(Livewire::test(IngredientStockCount::class)))])
        ->assertSet('importCounts', []);
    expect(isiNotifikasi())->toContain('Import dibatalkan')->toContain('Belum ada Hitung Fisik');

    expect(InventoryMovement::query()->count())->toBe(2);
});

it('memakai tanggal form bila tanggal di berkas di masa depan', function () {
    $d = bahanOpname();
    $this->actingAs(opnameGudang());

    $book = exportOpname(Livewire::test(IngredientStockCount::class));
    $sheet = $book->getActiveSheet();
    $sheet->setCellValue('B2', '30/11/2026');
    $sheet->setCellValue('G'.barisBahan($book, $d['tepung']), 9);

    Livewire::test(IngredientStockCount::class)
        ->set('data.count_date', '2026-11-26')
        ->callAction('import', ['berkas' => unggahLembar($book)])
        ->assertSet('importDate', '2026-11-26')
        ->assertSee('di masa depan')
        ->assertSet('importCounts', [$d['tepung']->id => '9']);
});

it('menutup Export dan Import untuk peran tanpa izin kelola inventory', function () {
    bahanOpname();

    $this->actingAs(opnameGudang('production'))->get(IngredientStockCount::getUrl())->assertForbidden();

    // Kontrol positif: gudang membuka halaman dan melihat kedua tombol.
    $this->actingAs(opnameGudang('inventory'))->get(IngredientStockCount::getUrl())->assertOk()
        ->assertSee('Export Excel')->assertSee('Import Excel');
});
