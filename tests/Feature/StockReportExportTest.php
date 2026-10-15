<?php

use App\Exports\StockReportExport;
use App\Filament\Resources\InventoryItemResource\Pages\ListInventoryItems;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\StockReportService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

/**
 * Laporan Stok mengikuti format laporan harian gudang klien: satu lembar per
 * jenis (Bahan Baku / Kemasan / Inventaris), kolom stok awal, masuk, keluar,
 * harga, mutasi, sisa, value -- bukan dipecah per kelompok bahan.
 */
function siapkanStok(): array
{
    $bucket = InventoryItem::query()->create(['name' => 'Bahan Baku', 'unit' => 'All', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);
    $item = fn (string $name, string $unit, string $category, ?float $price, ?string $group = null) => InventoryItem::query()->create([
        'parent_id' => $category === InventoryItem::CATEGORY_RAW_MATERIAL ? $bucket->id : null,
        'name' => $name, 'unit' => $unit, 'category' => $category, 'ingredient_group' => $group, 'unit_price' => $price, 'is_active' => true,
    ]);

    $ayam = $item('Ayam Fillet', 'Gr', InventoryItem::CATEGORY_RAW_MATERIAL, 50, 'protein');
    $wortel = $item('Wortel', 'Gr', InventoryItem::CATEGORY_RAW_MATERIAL, 15, 'sayur');
    $besek = $item('Besek 16', 'Pcs', InventoryItem::CATEGORY_PACKAGING, 2750);
    $baskom = $item('Baskom plastik', 'Pcs', InventoryItem::CATEGORY_FIXED_ASSET, 26000);

    $move = fn (InventoryItem $i, string $type, float $qty, string $at) => InventoryMovement::query()->create([
        'inventory_item_id' => $i->id, 'type' => $type, 'qty' => $qty, 'unit' => $i->unit, 'moved_at' => $at,
        'unit_price' => $i->unit_price, 'total_value' => round($qty * (float) $i->unit_price, 2),
    ]);

    // Sampai 13 Okt: saldo awal ayam 7.704 g; 14 Okt: beli 10.000, pakai 7.000, koreksi opname -4.
    $move($ayam, InventoryMovement::TYPE_OPENING, 7704, '2026-10-01 00:00:00');
    $move($ayam, InventoryMovement::TYPE_PURCHASE, 10000, '2026-10-14 08:00:00');
    $move($ayam, InventoryMovement::TYPE_USAGE, -7000, '2026-10-14 10:00:00');
    $move($ayam, InventoryMovement::TYPE_ADJUSTMENT, -4, '2026-10-14 20:00:00');
    // Gerakan sesudah periode tidak ikut.
    $move($ayam, InventoryMovement::TYPE_USAGE, -500, '2026-10-15 09:00:00');
    $move($besek, InventoryMovement::TYPE_OPENING, 466, '2026-10-01 00:00:00');
    $move($besek, InventoryMovement::TYPE_USAGE, -114, '2026-10-14 11:00:00');

    return compact('ayam', 'wortel', 'besek', 'baskom');
}

it('menghitung stok awal, masuk, keluar, mutasi, dan sisa per jenis dari kartu stok', function () {
    siapkanStok();
    $service = app(StockReportService::class);
    $day = Carbon::parse('2026-10-14');

    $bahan = $service->rows(InventoryItem::CATEGORY_RAW_MATERIAL, $day, $day);

    // Satu lembar Bahan Baku untuk semua kelompok (protein, sayur, ...); baris induk "Bahan Baku" tidak ikut.
    expect($bahan->pluck('name')->all())->toBe(['Ayam Fillet', 'Wortel'])
        ->and($bahan[0])->toBe([
            'name' => 'Ayam Fillet', 'opening' => 7704.0, 'unit' => 'gram', 'in' => 10000.0, 'out' => 7000.0,
            'price' => 50.0, 'mutation' => -4.0, 'closing' => 10700.0,
        ])
        ->and($service->rows(InventoryItem::CATEGORY_PACKAGING, $day, $day)->sole())->toMatchArray(['name' => 'Besek 16', 'opening' => 466.0, 'out' => 114.0, 'closing' => 352.0])
        ->and($service->rows(InventoryItem::CATEGORY_FIXED_ASSET, $day, $day)->pluck('name')->all())->toBe(['Baskom plastik']);
});

it('menulis berkas Excel tiga lembar dengan rumus sisa, value, dan total yang terhitung', function () {
    siapkanStok();
    $day = Carbon::parse('2026-10-14');

    $path = tempnam(sys_get_temp_dir(), 'stok').'.xlsx';
    file_put_contents($path, Excel::raw(new StockReportExport($day, $day), \Maatwebsite\Excel\Excel::XLSX));
    $book = IOFactory::load($path);

    expect($book->getSheetNames())->toBe(['BAHAN BAKU', 'KEMASAN', 'INVENTARIS']);

    $sheet = $book->getSheetByName('BAHAN BAKU');
    expect($sheet->getCell('B3')->getValue())->toBe('STOK 13 Oktober')
        ->and($sheet->getCell('A4')->getValue())->toBe('Ayam Fillet')
        ->and($sheet->getCell('H4')->getValue())->toBe('=B4+D4-E4+G4')
        ->and($sheet->getCell('H4')->getCalculatedValue())->toEqual(10700)
        ->and($sheet->getCell('I4')->getCalculatedValue())->toEqual(535000)
        ->and($sheet->getCell('A6')->getValue())->toBe('TOTAL')
        ->and($sheet->getCell('I6')->getCalculatedValue())->toEqual(535000);

    expect($book->getSheetByName('KEMASAN')->getCell('I4')->getCalculatedValue())->toEqual(352 * 2750);
});

it('mengunduh Laporan Stok dari halaman Item Inventaris tanpa mengubah ekspor data master', function () {
    siapkanStok();
    Role::findOrCreate('inventory', 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole('inventory');
    $this->actingAs($user);
    $this->freezeTime();
    Excel::fake();

    Livewire::test(ListInventoryItems::class)
        ->callAction('laporan_stok', ['from' => '2026-10-14', 'to' => '2026-10-14'])
        ->assertHasNoActionErrors();
    Excel::assertDownloaded('Stok 3S ONE 14 Oktober 2026.xlsx', fn (StockReportExport $export) => count($export->sheets()) === 3);

    // Kontrol positif: ekspor data master (format impor) tetap tersedia.
    Livewire::test(ListInventoryItems::class)->callAction('export');
    Excel::assertDownloaded('item-inventaris-'.now()->format('Ymd_His').'.xlsx');
});
