<?php

use App\Exports\InventoryPurchasesExport;
use App\Exports\ValueEntriesExport;
use App\Filament\Resources\InventoryOpeningResource\Pages\ListInventoryOpenings;
use App\Filament\Resources\InventoryPurchaseResource\Pages\ListInventoryPurchases;
use App\Filament\Resources\StockOpnameResource\Pages\ListStockOpnames;
use App\Imports\ValueEntriesImport;
use App\Models\InventoryItem;
use App\Models\InventoryOpening;
use App\Models\InventoryPurchase;
use App\Models\StockOpname;
use App\Models\User;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Import/export Stock Opname & Saldo Awal mengikuti pola Item Inventaris:
 * format import = format export, nilai disimpan dengan konvensi qty 1, berkas
 * bermasalah ditolak seluruhnya. Pembelian hanya diekspor.
 */
function berkasNilai(string $isi): string
{
    $path = tempnam(sys_get_temp_dir(), 'val_').'.csv';
    file_put_contents($path, $isi);

    return $path;
}

beforeEach(function () {
    $this->user = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $this->user->assignRole('admin');
    $this->actingAs($this->user);

    $this->bucket = InventoryItem::query()->create(['name' => 'Bahan Baku', 'unit' => 'All', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);
    $this->tepung = InventoryItem::query()->create(['name' => 'Tepung Terigu', 'unit' => 'kg', 'parent_id' => $this->bucket->id, 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);
});

it('mengekspor opname dan saldo awal dengan kolom yang bisa diimpor kembali', function () {
    $opname = StockOpname::query()->create(['inventory_item_id' => $this->tepung->id, 'opname_date' => '2026-08-31', 'qty' => 1, 'unit_cost' => 250000, 'notes' => 'akhir bulan']);

    $export = ValueEntriesExport::opname();
    $baris = array_combine($export->headings(), $export->map($opname->fresh()->load('item')));

    expect($baris)->toMatchArray(['id' => $opname->id, 'item_id' => $this->tepung->id, 'nama_item' => 'Tepung Terigu', 'tanggal' => '2026-08-31', 'nilai' => 250000.0, 'catatan' => 'akhir bulan'])
        ->and($export->collection())->toHaveCount(1);

    Excel::fake();
    Excel::matchByRegex();
    Livewire::test(ListStockOpnames::class)->callAction('export')->assertHasNoActionErrors();
    Excel::assertDownloaded('/^stock-opname-.*\.xlsx$/');

    Livewire::test(ListInventoryOpenings::class)->callAction('export')->assertHasNoActionErrors();
    Excel::assertDownloaded('/^saldo-awal-.*\.xlsx$/');
});

it('mengimpor opname: baris ber-id memperbarui, tanpa id dicocokkan item + tanggal, sisanya dibuat', function () {
    $lama = StockOpname::query()->create(['inventory_item_id' => $this->tepung->id, 'opname_date' => '2026-07-31', 'qty' => 1, 'unit_cost' => 100000]);
    $agustus = StockOpname::query()->create(['inventory_item_id' => $this->tepung->id, 'opname_date' => '2026-08-31', 'qty' => 1, 'unit_cost' => 200000]);

    $path = berkasNilai(implode("\n", [
        'id,item_id,nama_item,tanggal,nilai,catatan',
        "{$lama->id},,,2026-07-31,\"Rp 1.250.000,50\",koreksi",       // ber-id: nilai berformat rupiah
        ',,Tepung Terigu,2026-08-31,210000,',                         // tanpa id: cocok item+tanggal -> update
        ",{$this->tepung->id},,2026-09-30,300000,opname september",   // baru
    ]));

    $import = ValueEntriesImport::opname($this->user->id);
    Excel::import($import, $path);
    unlink($path);

    expect($import->hasErrors())->toBeFalse()
        ->and($import->updated())->toBe(2)
        ->and($import->created())->toBe(1)
        ->and((float) $lama->fresh()->total_value)->toBe(1250000.5)
        ->and($lama->fresh()->notes)->toBe('koreksi')
        ->and((float) $agustus->fresh()->total_value)->toBe(210000.0)
        ->and((float) $agustus->fresh()->qty)->toBe(1.0);

    $baru = StockOpname::query()->whereDate('opname_date', '2026-09-30')->first();
    expect($baru)->not->toBeNull()
        ->and((float) $baru->total_value)->toBe(300000.0)
        ->and($baru->created_by)->toBe($this->user->id)
        ->and(StockOpname::query()->count())->toBe(3);
});

it('menolak seluruh berkas opname bila satu baris salah, dan positif kontrolnya tersimpan', function () {
    $salah = berkasNilai(implode("\n", [
        'id,item_id,nama_item,tanggal,nilai,catatan',
        ',,Tepung Terigu,2026-08-31,200000,',
        ',,Bahan Tidak Ada,2026-08-31,5000,',
        ',,Tepung Terigu,bukan-tanggal,5000,',
        ',,Tepung Terigu,2026-08-31,-1,',
    ]));

    $import = ValueEntriesImport::opname();
    Excel::import($import, $salah);
    unlink($salah);

    expect($import->hasErrors())->toBeTrue()
        ->and($import->errors())->toHaveCount(3)
        ->and(StockOpname::query()->count())->toBe(0);

    $benar = berkasNilai("id,item_id,nama_item,tanggal,nilai,catatan\n,,Tepung Terigu,2026-08-31,200000,");
    $import = ValueEntriesImport::opname();
    Excel::import($import, $benar);
    unlink($benar);

    expect($import->hasErrors())->toBeFalse()
        ->and(StockOpname::query()->count())->toBe(1);
});

it('mengimpor saldo awal lewat halaman panel dan menolak peran tanpa izin kelola', function () {
    $path = berkasNilai("id,item_id,nama_item,tanggal,nilai,catatan\n,,Tepung Terigu,2026-01-01,750000,saldo awal sistem");

    $import = ValueEntriesImport::opening($this->user->id);
    Excel::import($import, $path);
    unlink($path);

    $opening = InventoryOpening::query()->first();
    expect($opening)->not->toBeNull()
        ->and($opening->balance_date->toDateString())->toBe('2026-01-01')
        ->and((float) $opening->total_value)->toBe(750000.0);

    // Tombol import hanya untuk peran berizin inventory.manage; produksi hanya melihat.
    Livewire::test(ListInventoryOpenings::class)->assertActionVisible('import')->assertActionVisible('export');

    $produksi = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $produksi->assignRole('production');
    Livewire::actingAs($produksi)->test(ListInventoryOpenings::class)->assertActionHidden('import')->assertActionVisible('export');
});

it('mengekspor pembelian bahan baku beserta kondisi dan tautan form kebutuhan', function () {
    $purchase = InventoryPurchase::query()->create([
        'inventory_item_id' => $this->tepung->id, 'transaction_date' => '2026-09-05', 'qty' => 1, 'unit_cost' => 120000, 'total_value' => 120000,
        'payment_type' => 'cash', 'supplier_name' => 'Toko Maju', 'condition' => InventoryPurchase::CONDITION_DAMAGED, 'condition_notes' => 'karung sobek',
    ]);

    $export = new InventoryPurchasesExport;
    $baris = array_combine($export->headings(), $export->map($purchase->fresh()->load('item')));

    expect($baris)->toMatchArray(['nama_item' => 'Tepung Terigu', 'tanggal' => '2026-09-05', 'total' => 120000.0, 'kondisi' => 'Tidak Baik', 'catatan_kondisi' => 'karung sobek', 'supplier' => 'Toko Maju']);

    Excel::fake();
    Excel::matchByRegex();
    Livewire::test(ListInventoryPurchases::class)->callAction('export')->assertHasNoActionErrors();
    Excel::assertDownloaded('/^pembelian-bahan-baku-.*\.xlsx$/');
});
