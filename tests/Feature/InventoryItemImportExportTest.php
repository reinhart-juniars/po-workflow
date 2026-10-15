<?php

use App\Exports\InventoryItemsExport;
use App\Imports\InventoryItemsImport;
use App\Models\InventoryItem;
use App\Models\InventoryPurchase;
use App\Models\StockOpname;
use App\Models\User;
use Maatwebsite\Excel\Facades\Excel;

/** Menulis berkas CSV sementara dengan heading yang sama seperti hasil export. */
function berkasImport(string $isi): string
{
    $path = tempnam(sys_get_temp_dir(), 'imp_').'.csv';
    file_put_contents($path, $isi);

    return $path;
}

beforeEach(function () {
    $this->user = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $this->user->assignRole('admin');
    $this->actingAs($this->user);
});

it('mengekspor item beserta ambang dan nilai stok berjalannya', function () {
    $item = InventoryItem::query()->create([
        'name' => 'Tepung Terigu',
        'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'minimum_stock_value' => 500000,
        'is_active' => true,
    ]);

    InventoryPurchase::query()->create([
        'inventory_item_id' => $item->id,
        'transaction_date' => '2026-03-10',
        'qty' => 1,
        'unit_cost' => 120000,
        'total_value' => 120000,
        'payment_type' => 'cash',
    ]);

    $export = new InventoryItemsExport;

    // Dibaca lewat nama kolomnya, bukan nomor kolom: format ini juga menjadi
    // format import, jadi kolomnya akan bertambah seiring waktu dan tes yang
    // menghitung posisi akan rusak setiap kali itu terjadi.
    $baris = array_combine($export->headings(), $export->map($item));

    expect($baris['nama_item'])->toBe('Tepung Terigu')
        ->and($baris['kategori'])->toBe(InventoryItem::CATEGORY_RAW_MATERIAL)
        ->and($baris['nilai_stok_minimum'])->toBe(500000.0)
        ->and($baris['aktif'])->toBe('ya')
        ->and($baris['nilai_stok_berjalan'])->toBe(120000.0);
});

it('membawa kolom bahan agar harga bisa diperbarui borongan lewat excel', function () {
    $bucket = InventoryItem::query()->create([
        'name' => 'Bahan Baku',
        'unit' => 'All',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'is_active' => true,
    ]);

    $item = InventoryItem::query()->create([
        'parent_id' => $bucket->id,
        'name' => 'Tepung Terigu',
        'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'ingredient_group' => 'karbo',
        'pack_qty' => 25,
        'pack_price' => 300000,
        'unit_price' => 12000,
        'is_active' => true,
    ]);

    $export = new InventoryItemsExport;
    $baris = array_combine($export->headings(), $export->map($item));

    expect($baris['induk_id'])->toBe($bucket->id)
        ->and($baris['induk_nama'])->toBe('Bahan Baku')
        ->and($baris['kelompok_bahan'])->toBe('karbo')
        ->and($baris['isi_kemasan'])->toBe(25.0)
        ->and($baris['harga_kemasan'])->toBe(300000.0)
        ->and($baris['harga_satuan'])->toBe(12000.0);
});

it('tidak mengosongkan kolom yang tidak ada di berkas', function () {
    $item = InventoryItem::query()->create([
        'name' => 'Tepung Terigu',
        'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'unit_price' => 12000,
        'minimum_stock_value' => 500000,
        'is_active' => true,
    ]);

    // Berkas lama, hanya berisi kolom yang dulu ada.
    $import = new InventoryItemsImport;
    $import->collection(collect([
        collect(['id' => $item->id, 'nama_item' => 'Tepung Terigu', 'satuan' => 'kg', 'kategori' => 'bahan_baku']),
    ]));

    // Kalau kolom yang absen ikut ditulis sebagai null, harga seluruh bahan
    // terhapus begitu berkas lama diunggah -- dan HPP seluruh menu jadi nol.
    expect($import->hasErrors())->toBeFalse()
        ->and((float) $item->fresh()->unit_price)->toBe(12000.0)
        ->and((float) $item->fresh()->minimum_stock_value)->toBe(500000.0);
});

it('menempatkan item baru hasil import di bawah bucket kategorinya', function () {
    $bucket = InventoryItem::query()->create([
        'name' => 'Bahan Baku',
        'unit' => 'All',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'is_active' => true,
    ]);

    $import = new InventoryItemsImport;
    $import->collection(collect([
        collect(['nama_item' => 'Garam Dapur', 'satuan' => 'kg', 'kategori' => 'bahan_baku', 'harga_satuan' => 8000]),
    ]));

    $garam = InventoryItem::query()->firstWhere('name', 'Garam Dapur');

    // Item tanpa induk diperlakukan sebagai bucket, dan bucket bayangan akan
    // mengacaukan pengelompokan Laba Rugi.
    expect($garam->parent_id)->toBe($bucket->id)
        ->and($garam->isBucket())->toBeFalse()
        ->and((float) $garam->unit_price)->toBe(8000.0);
});

it('membuat item baru dan memperbarui yang sudah ada berdasarkan id', function () {
    $existing = InventoryItem::query()->create([
        'name' => 'Tepung Terigu',
        'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'is_active' => true,
    ]);

    $path = berkasImport(implode("\n", [
        'id,nama_item,satuan,kategori,nilai_stok_minimum,aktif,keterangan',
        "{$existing->id},Tepung Terigu Premium,kg,bahan_baku,\"750000\",ya,Naik kelas",
        ',Plastik Mika,pcs,packaging,"250000",ya,',
    ]));

    $import = new InventoryItemsImport;
    Excel::import($import, $path);

    expect($import->hasErrors())->toBeFalse()
        ->and($import->created())->toBe(1)
        ->and($import->updated())->toBe(1);

    $existing->refresh();

    expect($existing->name)->toBe('Tepung Terigu Premium')
        ->and((float) $existing->minimum_stock_value)->toBe(750000.0);

    $baru = InventoryItem::query()->firstWhere('name', 'Plastik Mika');

    expect($baru)->not->toBeNull()
        ->and($baru->category)->toBe(InventoryItem::CATEGORY_PACKAGING);

    unlink($path);
});

it('menolak seluruh berkas ketika ada kategori yang tidak dikenal', function () {
    $path = berkasImport(implode("\n", [
        'id,nama_item,satuan,kategori,nilai_stok_minimum,aktif,keterangan',
        ',Item Sah,kg,bahan_baku,"100000",ya,',
        ',Item Salah,kg,kategori_ngawur,"100000",ya,',
    ]));

    $import = new InventoryItemsImport;
    Excel::import($import, $path);

    expect($import->hasErrors())->toBeTrue()
        ->and($import->errors()[0])->toContain('kategori_ngawur');

    // Sebagian tersimpan lebih sulit dibereskan daripada tidak sama sekali:
    // baris yang sah pun tidak boleh ikut masuk.
    expect(InventoryItem::query()->count())->toBe(0);

    unlink($path);

    // Positive control: berkas yang seluruh barisnya sah tetap tersimpan.
    $pathSah = berkasImport(implode("\n", [
        'id,nama_item,satuan,kategori,nilai_stok_minimum,aktif,keterangan',
        ',Item Sah,kg,bahan_baku,"100000",ya,',
    ]));

    $importSah = new InventoryItemsImport;
    Excel::import($importSah, $pathSah);

    expect($importSah->hasErrors())->toBeFalse()
        ->and(InventoryItem::query()->count())->toBe(1);

    unlink($pathSah);
});

it('menerima label kategori dan angka berformat indonesia', function () {
    $path = berkasImport(implode("\n", [
        'id,nama_item,satuan,kategori,nilai_stok_minimum,aktif,keterangan',
        ',Plastik Mika,pcs,Kemasan,"1.500.000,50",tidak,',
    ]));

    $import = new InventoryItemsImport;
    Excel::import($import, $path);

    $item = InventoryItem::query()->firstWhere('name', 'Plastik Mika');

    expect($import->hasErrors())->toBeFalse()
        ->and($item->category)->toBe(InventoryItem::CATEGORY_PACKAGING)
        ->and((float) $item->minimum_stock_value)->toBe(1500000.5)
        ->and($item->is_active)->toBeFalse();

    unlink($path);
});

it('menampilkan pemakaian residual dan pemakaian resep berdampingan di laporan pemakaian bahan', function () {
    $item = InventoryItem::query()->create([
        'name' => 'Tepung Terigu',
        'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'is_active' => true,
    ]);

    InventoryPurchase::query()->create([
        'inventory_item_id' => $item->id,
        'transaction_date' => '2026-03-10',
        'qty' => 1,
        'unit_cost' => 100000,
        'total_value' => 100000,
        'payment_type' => 'cash',
    ]);

    InventoryPurchase::query()->create([
        'inventory_item_id' => $item->id,
        'transaction_date' => '2026-03-12',
        'qty' => 1,
        'unit_cost' => 30000,
        'total_value' => 30000,
        'payment_type' => 'cash',
        'condition' => InventoryPurchase::CONDITION_DAMAGED,
    ]);

    StockOpname::query()->create([
        'inventory_item_id' => $item->id,
        'opname_date' => '2026-03-31',
        'qty' => 1,
        'unit_cost' => 40000,
        'total_value' => 40000,
    ]);

    // Laporan Pemakaian Bahan (Blade) adalah satu-satunya halaman mutasi stok:
    // barang rusak tidak ikut, dan kartu Pemakaian Resep tampil di sampingnya.
    $this->user->assignRole('accounting');

    $this->get(route('accountingapp.reports.inventory-usage', [
        'inventory_item_id' => $item->id, 'date_from' => '2026-03-01', 'date_to' => '2026-03-31',
    ]))
        ->assertOk()
        ->assertSeeInOrder(['Pembelian', '100.000,00'])
        ->assertSeeInOrder(['Sisa Stok', '40.000,00'])
        ->assertSeeInOrder(['Pemakaian', '60.000,00', 'Sumber HPP: residual opname'])
        ->assertSee('Pemakaian Resep');
});

it('tidak mengubah harga apa pun saat berkas ekspor diimpor kembali (harga desimal)', function () {
    // Bug lama: impor membuang semua titik, sehingga 147,5 hasil ekspor menjadi 1.475.
    $items = collect([
        ['Keju Prochiz 160gr', 'gram', 147.5, null, null],
        ['Nasi putih', 'gram', 52.9801, null, null],
        ['Air', 'ml', 0.63, null, null],
        ['Cup 75 ml', 'pcs', 533.76, 1, 533.76],
        ['Telur Omega', 'butir', 2187.5, 2187.5, null],
        ['Beras', 'gram', 12000, null, null],
    ])->map(fn ($row) => InventoryItem::query()->create([
        'name' => $row[0], 'unit' => $row[1], 'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'unit_price' => $row[2], 'pack_qty' => $row[3], 'pack_price' => $row[4], 'is_active' => true,
    ]));
    $snapshot = fn () => InventoryItem::query()->whereIn('id', $items->pluck('id'))->orderBy('id')
        ->get(['unit_price', 'pack_qty', 'pack_price'])->map(fn ($i) => [(float) $i->unit_price, $i->pack_qty === null ? null : (float) $i->pack_qty, $i->pack_price === null ? null : (float) $i->pack_price])->all();
    $before = $snapshot();

    $path = tempnam(sys_get_temp_dir(), 'inv').'.xlsx';
    file_put_contents($path, Excel::raw(new InventoryItemsExport, \Maatwebsite\Excel\Excel::XLSX));
    $import = new InventoryItemsImport;
    Excel::import($import, $path);

    expect($import->hasErrors())->toBeFalse()
        ->and($snapshot())->toBe($before)
        ->and($before[0][0])->toBe(147.5);
    unlink($path);
});

it('membaca angka teks gaya Indonesia maupun Inggris tanpa salah skala', function (mixed $input, ?float $expected) {
    expect(\App\Support\NumberParser::parse($input))->toBe($expected);
})->with([
    'sel angka asli' => [147.5, 147.5],
    'titik ribuan + koma desimal' => ['1.500.000,50', 1500000.5],
    'gaya inggris' => ['1,500,000.50', 1500000.5],
    'ribuan satu titik' => ['12.000', 12000.0],
    'desimal titik' => ['147.5', 147.5],
    'desimal empat digit' => ['52.9801', 52.9801],
    'nol koma' => ['0.125', 0.125],
    'koma desimal' => ['10,5', 10.5],
    'rupiah dengan spasi' => ['Rp 15.000', 15000.0],
    'kosong' => ['', null],
    'bukan angka' => ['abc', null],
]);

/**
 * Laporan pengguna (15 Okt 2026): impor ditolak karena "ada jenis item yang
 * kosong di beberapa baris". Baris tanpa nama dan tanpa id -- sisa isian
 * Excel seperti kategori/aktif yang ikut ditarik ke bawah -- tidak menunjuk
 * item mana pun, jadi dilewati dan dilaporkan, bukan membatalkan berkas.
 */
it('melewati baris tanpa nama dan tanpa id lalu melaporkannya, tetapi tetap menolak baris ber-id tanpa nama', function () {
    $path = berkasImport(implode("\n", [
        'id,nama_item,satuan,kategori,nilai_stok_minimum,aktif,keterangan',
        ',Plastik Mika,pcs,packaging,,ya,',
        ',,,bahan_baku,,ya,',
        ',,,,,ya,',
    ]));

    $import = new InventoryItemsImport;
    Excel::import($import, $path);
    unlink($path);

    expect($import->hasErrors())->toBeFalse()
        ->and($import->created())->toBe(1)
        ->and($import->skipped())->toBe([3, 4])
        ->and($import->notes())->toContain('2 baris tanpa nama item dilewati (baris 3, 4)')
        ->and(InventoryItem::query()->pluck('name')->all())->toBe(['Plastik Mika']);

    // Baris ber-id tanpa nama menunjuk item yang ada tetapi isinya rusak: tolak.
    $item = InventoryItem::query()->firstWhere('name', 'Plastik Mika');
    $rusak = berkasImport(implode("\n", [
        'id,nama_item,satuan,kategori',
        "{$item->id},,pcs,packaging",
    ]));

    $import = new InventoryItemsImport;
    Excel::import($import, $rusak);
    unlink($rusak);

    expect($import->hasErrors())->toBeTrue()
        ->and($import->errors()[0])->toContain("nama item kosong (id {$item->id})")
        ->and($item->fresh()->name)->toBe('Plastik Mika');
});

it('tidak mengubah kategori item lama bila selnya kosong; item baru tanpa kategori menjadi Bahan Baku dan dilaporkan', function () {
    $besek = InventoryItem::query()->create(['name' => 'Besek 16', 'unit' => 'pcs', 'category' => InventoryItem::CATEGORY_PACKAGING, 'is_active' => true]);

    $path = berkasImport(implode("\n", [
        'id,nama_item,satuan,kategori,harga_satuan',
        "{$besek->id},Besek 16,pcs,,2750",
        ',Bawang Bombay,gram,,40',
    ]));

    $import = new InventoryItemsImport;
    Excel::import($import, $path);
    unlink($path);

    expect($import->hasErrors())->toBeFalse()
        ->and($besek->fresh()->category)->toBe(InventoryItem::CATEGORY_PACKAGING)
        ->and((float) $besek->fresh()->unit_price)->toBe(2750.0)
        ->and(InventoryItem::query()->firstWhere('name', 'Bawang Bombay')->category)->toBe(InventoryItem::CATEGORY_RAW_MATERIAL)
        ->and($import->defaultedCategory())->toBe(['Bawang Bombay'])
        ->and($import->notes())->toContain('1 item baru tanpa kategori dibuat sebagai Bahan Baku (Bawang Bombay)');
});

it('mengenali judul kolom laporan gudang (Nama Barang, Jenis, Harga) dan kategori Peralatan', function () {
    $path = berkasImport(implode("\n", [
        'NAMA BARANG,SATUAN,JENIS,HARGA',
        'Baskom plastik,pcs,Peralatan,26000',
        'Dus nasi kotak,pcs,Kemasan,1200',
    ]));

    $import = new InventoryItemsImport;
    Excel::import($import, $path);
    unlink($path);

    $baskom = InventoryItem::query()->firstWhere('name', 'Baskom plastik');

    expect($import->hasErrors())->toBeFalse()
        ->and($import->created())->toBe(2)
        ->and($baskom->category)->toBe(InventoryItem::CATEGORY_FIXED_ASSET)
        ->and((float) $baskom->unit_price)->toBe(26000.0)
        ->and(InventoryItem::query()->firstWhere('name', 'Dus nasi kotak')->category)->toBe(InventoryItem::CATEGORY_PACKAGING);
});

it('memberi satu pesan jelas bila kolom nama item tidak ada, bukan satu error per baris', function () {
    $path = berkasImport(implode("\n", [
        'STOK BAHAN BAKU 3S ONE,,',
        'Ayam fillet,gram,50',
        'Wortel,gram,15',
    ]));

    $import = new InventoryItemsImport;
    Excel::import($import, $path);
    unlink($path);

    expect($import->errors())->toHaveCount(1)
        ->and($import->errors()[0])->toContain('Kolom nama_item')
        ->and(InventoryItem::query()->count())->toBe(0);
});
