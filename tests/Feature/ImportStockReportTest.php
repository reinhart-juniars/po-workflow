<?php

use App\Filament\Pages\ImportStockReport;
use App\Filament\Resources\InventoryItemResource\Pages\ListInventoryItems;
use App\Imports\StockReportReader;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\StockReportImporter;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Laporan pengguna (15 Okt 2026): laporan stok gudang ditolak Import Excel
 * Item Inventaris ("Kolom nama_item tidak ditemukan" berulang per sheet).
 * Laporan gudang punya format sendiri dan diimpor lewat halaman pratinjau,
 * karena nama dan satuannya sering berbeda dengan item di sistem.
 */
function laporanStokGudang(): string
{
    $book = new Spreadsheet;

    // Sheet bahan: judul, tanggal, kepala kolom di baris 3, nama di kolom B.
    $bahan = $book->getActiveSheet()->setTitle('BAHAN BAKU');
    $bahan->fromArray([
        [null, 'STOCK 3S'],
        [null, null, null, null, null, null, null, null, 'TANGGAL :', ExcelDate::PHPToExcel(new DateTime('2026-10-14'))],
        [null, 'NAMA BARANG', 'STOCK 13 Oktober', 'SATUAN', 'MASUK', 'KELUAR', ' HARGA ', 'MUTASI', 'SISA STOK', ' VALUE '],
        [null, 'Arang Kayu', 27, 'Kg', null, null, 15000, null, 27, 405000],
        [null, 'Tepung Terigu', 3, 'Kg', null, null, 12000, null, 3, 36000],     // sama, satuan beda dengan sistem (gram)
        [null, 'Asem Mentah', 500, 'Gr', null, null, 20, null, 500, 10000],      // mirip "Asam Mentah"
        [null, 'Garam Halus', 2000, 'Gr', null, null, 5, null, 2000, 10000],     // sama, satuan sama
        [null, null, null, null, null, null, null, null, null, 461000],          // baris total tanpa nama
    ]);

    // Sheet kemasan: letak kolom berbeda (SATUAN sesudah KELUAR, SISA tanpa "STOK").
    $kemasan = $book->createSheet()->setTitle('KEMASAN');
    $kemasan->fromArray([
        [null, 'STOCK KEMASAN'],
        [],
        [null, 'NAMA BARANG', 'STOCK 13 Okt', 'MASUK', 'KELUAR', 'SATUAN', ' HARGA ', 'SISA', ' VALUE '],
        [null, 'Besek 16', 466, null, 114, 'Pcs', 2750, 352, 968000],
        [null, 'Arang Kayu', 1, null, null, 'Kg', 15000, 1, 15000],              // dobel antar sheet: sekali saja
    ]);

    // Daftar inventaris tanpa kolom harga: harga = total ÷ qty.
    $wwp = $book->createSheet()->setTitle('PERALATAN WWP');
    $wwp->fromArray([
        ['DAFTAR INVENTARIS WARUNG WUENAK POLL'],
        [],
        ['NO', 'NAMA INVENTARIS', 'QTY', 'SATUAN', 'TOTAL'],
        [1, 'Alat Serut Es', 2, 'unit', 1465546],
        [null, 'TOTAL :', null, null, 1465546],
    ]);

    // Catatan kerja: tidak ada kepala kolom -> dilewati dan dilaporkan.
    $catatan = $book->createSheet()->setTitle('Sheet2');
    $catatan->fromArray([['Hasil kerja hari ini :'], ['Bumbu pecel jadi 48 porsi']]);

    $path = tempnam(sys_get_temp_dir(), 'lapstok').'.xlsx';
    (new Xlsx($book))->save($path);

    return $path;
}

beforeEach(function () {
    $this->user = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $this->user->assignRole('inventory');
    $this->actingAs($this->user);

    $bucket = fn (string $name, string $category) => InventoryItem::query()->create(['name' => $name, 'unit' => 'All', 'category' => $category, 'is_active' => true]);
    $this->bahan = $bucket('Bahan Baku', InventoryItem::CATEGORY_RAW_MATERIAL);
    $this->kemasan = $bucket('Kemasan', InventoryItem::CATEGORY_PACKAGING);
    $this->inventaris = $bucket('Inventaris', InventoryItem::CATEGORY_FIXED_ASSET);

    $item = fn (string $name, string $unit, float $price, $parent) => InventoryItem::query()->create([
        'name' => $name, 'unit' => $unit, 'unit_price' => $price, 'parent_id' => $parent->id, 'category' => $parent->category, 'is_active' => true,
    ]);
    $this->tepung = $item('Tepung Terigu', 'gram', 12, $this->bahan);
    $this->asam = $item('Asam Mentah', 'gram', 18, $this->bahan);
    $this->garam = $item('Garam Halus', 'gram', 4, $this->bahan);
});

it('membaca sheet laporan gudang berdasarkan kepala kolomnya, dan melewati sheet tanpa kepala', function () {
    $path = laporanStokGudang();
    $read = app(StockReportReader::class)->read($path);
    $reader = app(StockReportReader::class);
    expect($reader->looksLikeStockReport($path))->toBeTrue();
    unlink($path);

    expect($read['sheets'])->toBe(['BAHAN BAKU' => 4, 'KEMASAN' => 2, 'PERALATAN WWP' => 1])
        ->and($read['skipped'])->toHaveKey('Sheet2')
        ->and($read['date'])->toBe('2026-10-14');

    $rows = collect($read['rows'])->keyBy(fn ($row) => $row['sheet'].'/'.$row['name']);

    expect($rows['BAHAN BAKU/Arang Kayu'])->toMatchArray(['unit' => 'Kg', 'price' => 15000.0, 'qty' => 27.0, 'category' => InventoryItem::CATEGORY_RAW_MATERIAL])
        ->and($rows['KEMASAN/Besek 16'])->toMatchArray(['unit' => 'Pcs', 'price' => 2750.0, 'qty' => 352.0, 'category' => InventoryItem::CATEGORY_PACKAGING])
        ->and($rows['PERALATAN WWP/Alat Serut Es'])->toMatchArray(['qty' => 2.0, 'price' => 732773.0, 'category' => InventoryItem::CATEGORY_FIXED_ASSET])
        ->and($rows->keys())->not->toContain('PERALATAN WWP/TOTAL :');

    // Kontrol: berkas Export Data Master (nama_item di baris 1) bukan laporan gudang.
    $master = new Spreadsheet;
    $master->getActiveSheet()->fromArray([['id', 'nama_item', 'satuan'], [null, 'Garam', 'gram']]);
    $masterPath = tempnam(sys_get_temp_dir(), 'master').'.xlsx';
    (new Xlsx($master))->save($masterPath);
    expect($reader->looksLikeStockReport($masterPath))->toBeFalse();
    unlink($masterPath);
});

it('memberi status Sama, Mirip, Baru dengan bawaan yang tidak merusak item lama', function () {
    $path = laporanStokGudang();
    $preview = collect(app(StockReportImporter::class)->preview(app(StockReportReader::class)->read($path)['rows']))->keyBy('name');
    unlink($path);

    expect($preview['Tepung Terigu'])->toMatchArray(['status' => 'sama', 'default' => 'link:'.$this->tepung->id])
        ->and($preview['Asem Mentah']['status'])->toBe('mirip')
        ->and($preview['Asem Mentah']['default'])->toBe(StockReportImporter::ACTION_SKIP)
        ->and($preview['Asem Mentah']['candidates'][0]['name'])->toBe('Asam Mentah')
        ->and($preview['Arang Kayu'])->toMatchArray(['status' => 'baru', 'default' => StockReportImporter::ACTION_NEW, 'duplicates' => 1])
        // Satuan laporan (Kg) berbeda dengan sistem (gram): tidak ada tawaran perbarui harga.
        ->and($preview['Tepung Terigu']['candidates'][0]['same_unit'])->toBeFalse()
        ->and($preview['Garam Halus']['candidates'][0]['same_unit'])->toBeTrue();
});

it('menyimpan pilihan: item baru per jenis beserta saldo awal, item lama tidak diubah kecuali dipilih perbarui harga', function () {
    $path = laporanStokGudang();
    $preview = app(StockReportImporter::class)->preview(app(StockReportReader::class)->read($path)['rows']);
    unlink($path);
    $key = fn (string $name) => collect($preview)->firstWhere('name', $name)['key'];

    // Tepung yang sudah berjalan di Kartu Stok tidak boleh diberi saldo awal kedua.
    InventoryMovement::query()->create(['inventory_item_id' => $this->tepung->id, 'type' => InventoryMovement::TYPE_OPENING, 'qty' => 1000, 'unit' => 'gram', 'moved_at' => '2026-10-01']);
    // Garam satuannya sama dengan laporan, tetapi sudah berjalan di Kartu Stok.
    InventoryMovement::query()->create(['inventory_item_id' => $this->garam->id, 'type' => InventoryMovement::TYPE_OPENING, 'qty' => 1500, 'unit' => 'gram', 'moved_at' => '2026-10-01']);

    $summary = app(StockReportImporter::class)->apply($preview, [
        $key('Asem Mentah') => 'link:'.$this->asam->id,
        $key('Garam Halus') => 'price:'.$this->garam->id,
    ], true, \Illuminate\Support\Carbon::parse('2026-10-14'), $this->user);

    expect($summary)->toMatchArray(['created' => 3, 'linked' => 3, 'priced' => 1, 'skipped' => 0, 'stock' => 4]);

    $arang = InventoryItem::query()->firstWhere('name', 'Arang Kayu');
    expect($arang)->toMatchArray(['unit' => 'kg', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'parent_id' => $this->bahan->id])
        ->and((float) $arang->unit_price)->toBe(15000.0)
        ->and(InventoryItem::query()->where('name', 'Arang Kayu')->count())->toBe(1)
        ->and(InventoryItem::query()->firstWhere('name', 'Besek 16')->parent_id)->toBe($this->kemasan->id)
        ->and(InventoryItem::query()->firstWhere('name', 'Alat Serut Es')->category)->toBe(InventoryItem::CATEGORY_FIXED_ASSET);

    // Saldo awal di Kartu Stok pada tanggal laporan, dalam satuan item.
    $opening = InventoryMovement::query()->where('inventory_item_id', $arang->id)->sole();
    expect($opening->type)->toBe(InventoryMovement::TYPE_OPENING)
        ->and((float) $opening->qty)->toBe(27.0)
        ->and($opening->moved_at->toDateString())->toBe('2026-10-14');

    // Item lama: tepung tidak berubah dan tidak dapat saldo awal (satuan beda),
    // asam ditautkan tanpa ubah harga tapi dapat saldo awal (satuan sama, belum ada kartu),
    // garam harganya diperbarui karena dipilih.
    expect((float) $this->tepung->fresh()->unit_price)->toBe(12.0)
        ->and($this->tepung->fresh()->unit)->toBe('gram')
        ->and(InventoryMovement::query()->where('inventory_item_id', $this->tepung->id)->count())->toBe(1)
        ->and($summary['stock_skipped'])->toHaveKey('Tepung Terigu')
        ->and((float) $this->asam->fresh()->unit_price)->toBe(18.0)
        ->and((float) InventoryMovement::query()->where('inventory_item_id', $this->asam->id)->sum('qty'))->toBe(500.0)
        ->and((float) $this->garam->fresh()->unit_price)->toBe(5.0)
        // Saldo awal kedua untuk item yang sudah berjalan akan menggandakan stoknya.
        ->and($summary['stock_skipped']['Garam Halus'])->toContain('Kartu Stok')
        ->and((float) InventoryMovement::query()->where('inventory_item_id', $this->garam->id)->sum('qty'))->toBe(1500.0);
});

it('menolak tautan ke item yang bukan saran baris itu, tanpa menyimpan apa pun', function () {
    $path = laporanStokGudang();
    $preview = app(StockReportImporter::class)->preview(app(StockReportReader::class)->read($path)['rows']);
    unlink($path);
    $besek = collect($preview)->firstWhere('name', 'Besek 16');
    // Item yang bukan saran baris Besek: pilihan palsu dari browser.
    $outsider = collect([$this->tepung, $this->asam, $this->garam])
        ->first(fn (InventoryItem $item) => ! in_array($item->id, array_column($besek['candidates'], 'id'), true));
    $before = (float) $outsider->unit_price;

    expect(fn () => app(StockReportImporter::class)->apply($preview, [$besek['key'] => 'price:'.$outsider->id], false, null, $this->user))
        ->toThrow(InvalidArgumentException::class);

    // Satu transaksi: baris yang diproses sebelum Besek pun tidak tersimpan.
    expect(InventoryItem::query()->where('name', 'Arang Kayu')->exists())->toBeFalse()
        ->and((float) $outsider->fresh()->unit_price)->toBe($before);

    // Kontrol positif: pilihan bawaan tersimpan.
    app(StockReportImporter::class)->apply($preview, [], false, null, $this->user);
    expect(InventoryItem::query()->where('name', 'Besek 16')->exists())->toBeTrue();
});

it('menjalankan pratinjau dan simpan lewat halaman, dan mengarahkan laporan gudang dari Import Excel Item Inventaris', function () {
    $path = laporanStokGudang();
    $file = UploadedFile::fake()->createWithContent('Stok 3S 14 Oktober 2026.xlsx', file_get_contents($path));
    unlink($path);

    $page = Livewire::test(ImportStockReport::class)
        ->set('upload', $file)
        ->call('readFile')
        ->assertHasNoErrors()
        ->assertSee('Asem Mentah')
        ->assertSee('Sheet2')
        ->assertSet('stockDate', '2026-10-14');

    // Saringan bawaan: Mirip dulu, karena itu yang perlu diputuskan.
    expect($page->get('statusFilter'))->toBe('mirip');

    $page->call('save')->assertRedirect();

    expect(InventoryItem::query()->where('name', 'Arang Kayu')->exists())->toBeTrue()
        ->and(InventoryItem::query()->where('name', 'Asem Mentah')->exists())->toBeFalse(); // mirip -> dilewati

    // Import Excel Item Inventaris mengenali laporan gudang dan tidak mengimpornya.
    $again = laporanStokGudang();
    $before = InventoryItem::query()->count();
    Livewire::test(ListInventoryItems::class)
        ->callAction('import', ['berkas' => UploadedFile::fake()->createWithContent('stok.xlsx', file_get_contents($again))])
        ->assertNotified('Ini laporan stok gudang');
    unlink($again);
    expect(InventoryItem::query()->count())->toBe($before);
});

it('hanya bisa dibuka peran yang boleh mengelola inventory', function () {
    $this->get(ImportStockReport::getUrl())->assertOk();

    $produksi = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $produksi->assignRole('production');
    $this->actingAs($produksi)->get(ImportStockReport::getUrl())->assertForbidden();
});
