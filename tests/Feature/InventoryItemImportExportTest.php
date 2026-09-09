<?php

use App\Exports\InventoryItemsExport;
use App\Filament\Pages\StockMutationReport;
use App\Imports\InventoryItemsImport;
use App\Models\InventoryItem;
use App\Models\InventoryPurchase;
use App\Models\StockOpname;
use App\Models\User;
use Livewire\Livewire;
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

it('menampilkan mutasi stok memakai angka yang sama dengan laporan pemakaian bahan', function () {
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

    $page = Livewire::test(StockMutationReport::class)
        ->set('data.inventory_item_id', $item->id)
        ->set('data.date_from', '2026-03-01')
        ->set('data.date_to', '2026-03-31');

    $summary = $page->instance()->getSummary();

    // Barang rusak tidak ikut, jadi angkanya identik dengan Laporan Pemakaian Bahan.
    expect((float) $summary['purchases'])->toBe(100000.0)
        ->and((float) $summary['ending'])->toBe(40000.0)
        ->and((float) $summary['usage'])->toBe(60000.0);

    $page->assertOk()->assertSee('Rincian Mutasi');
});
