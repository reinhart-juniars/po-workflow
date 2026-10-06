<?php

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryOpening;
use App\Models\InventoryPurchase;
use App\Models\StockOpname;
use App\Models\User;
use App\Services\InventoryUsageService;
use App\Support\Settings\Settings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * summariesForItems() menggantikan calculateForItem() per bahan di laporan
 * Laba Rugi, Final, Neraca, dan Analisa HPP. Angkanya harus sama persis,
 * karena dari sini Bahan Baku Terpakai dan HPP diambil; yang berubah hanya
 * jumlah query (dulu 7 per bahan per periode, Analisa HPP setahun >20.000
 * query dan melewati batas 30 detik).
 */
function bahanBatchUji(string $name, ?int $parentId = null): InventoryItem
{
    return InventoryItem::query()->create([
        'name' => $name,
        'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'parent_id' => $parentId,
        'is_active' => true,
    ]);
}

function opnameUji(InventoryItem $item, string $date, float $value): void
{
    StockOpname::query()->create([
        'inventory_item_id' => $item->id, 'opname_date' => $date,
        'qty' => 1, 'unit_cost' => $value, 'total_value' => $value,
    ]);
}

function pembelianUji(InventoryItem $item, string $date, float $value, string $condition = InventoryPurchase::CONDITION_GOOD): void
{
    InventoryPurchase::query()->create([
        'inventory_item_id' => $item->id, 'transaction_date' => $date,
        'qty' => 1, 'unit_cost' => $value, 'total_value' => $value,
        'payment_type' => 'cash', 'condition' => $condition,
        'condition_notes' => $condition === InventoryPurchase::CONDITION_DAMAGED ? 'Rusak' : null,
    ]);
}

function gerakanUji(InventoryItem $item, string $type, string $date, float $value): void
{
    InventoryMovement::query()->create([
        'inventory_item_id' => $item->id, 'type' => $type, 'qty' => -1, 'unit' => 'kg',
        'unit_price' => abs($value), 'total_value' => $value, 'moved_at' => $date,
    ]);
}

/** Data yang menyentuh setiap cabang hitungan buildItemReport. */
function skenarioPemakaian(): array
{
    // Bucket dengan dua bahan anak: pemakaian resep dibaca dari kartu stok anaknya.
    $bucket = bahanBatchUji('Bahan Baku');
    $ayam = bahanBatchUji('Ayam', $bucket->id);
    $bawang = bahanBatchUji('Bawang', $bucket->id);

    // Opname bulan sebelumnya: dua di tanggal yang sama (id terbesar menang),
    // satu lebih lama di luar window (tidak dihitung).
    opnameUji($bucket, '2026-01-15', 999);
    opnameUji($bucket, '2026-02-27', 100);
    opnameUji($bucket, '2026-02-27', 120);
    opnameUji($bucket, '2026-03-31', 80);

    // Pembelian: yang rusak tidak menambah stok; yang di luar periode tidak ikut.
    pembelianUji($bucket, '2026-03-05', 200.1);
    pembelianUji($bucket, '2026-03-20', 0.2);
    pembelianUji($bucket, '2026-03-21', 50, InventoryPurchase::CONDITION_DAMAGED);
    pembelianUji($bucket, '2026-04-02', 70);

    gerakanUji($ayam, InventoryMovement::TYPE_USAGE, '2026-03-10', -150);
    gerakanUji($bawang, InventoryMovement::TYPE_USAGE, '2026-03-11', -40.5);
    gerakanUji($ayam, InventoryMovement::TYPE_ADJUSTMENT, '2026-03-31', -9);
    gerakanUji($ayam, InventoryMovement::TYPE_USAGE, '2026-04-05', -33);

    // Periode pertama: tidak ada opname bulan lalu, saldo awal jadi stok lama.
    $kemasan = bahanBatchUji('Kemasan');
    InventoryOpening::query()->create([
        'inventory_item_id' => $kemasan->id, 'balance_date' => '2026-02-20',
        'qty' => 1, 'unit_cost' => 30, 'total_value' => 30,
    ]);
    pembelianUji($kemasan, '2026-03-03', 12);

    // Bahan tanpa catatan apa pun.
    $kosong = bahanBatchUji('Tidak Dipakai');

    return compact('bucket', 'ayam', 'bawang', 'kemasan', 'kosong');
}

it('menghasilkan ringkasan yang sama persis dengan hitungan per bahan', function (string $usageSource) {
    app(Settings::class)->set('hpp.usage_source', $usageSource);
    $items = skenarioPemakaian();
    $service = app(InventoryUsageService::class);
    $ids = collect($items)->pluck('id');

    $windows = [
        ['2026-03-01', '2026-03-31'],
        ['2026-04-01', '2026-04-30'],
        ['2026-02-01', '2026-02-28'],
        ['2026-01-01', '2026-06-30'],
        ['2026-03-15', '2026-04-10'],
    ];

    foreach ($windows as [$from, $to]) {
        $batch = $service->summariesForItems($ids, Carbon::parse($from), Carbon::parse($to));

        foreach ($ids as $id) {
            expect($batch[$id])->toBe($service->calculateForItem($id, Carbon::parse($from), Carbon::parse($to)));
        }
    }

    // Positive control: skenarionya benar-benar menyentuh tiap cabang, jadi
    // kesamaan di atas tidak lolos hanya karena semua angkanya nol.
    $maret = $service->summariesForItems($ids, Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));
    expect($maret[$items['bucket']->id])->toMatchArray([
        'opening' => 120.0, 'opening_source' => 'opname',
        'ending' => 80.0, 'ending_source' => 'opname',
        'usage_residual' => 240.3, 'usage_recipe' => 190.5, 'adjustment_recipe' => 9.0,
    ])
        // 200,1 + 0,2 dalam float = 200,2999...; yang dijaga di atas adalah
        // kesamaan persis dengan jalur per bahan, di sini cukup nilainya.
        ->and(round($maret[$items['bucket']->id]['purchases'], 2))->toBe(200.3)
        ->and($maret[$items['bucket']->id]['usage'])->toBe($usageSource === 'resep' ? 199.5 : 240.3)
        ->and($maret[$items['kemasan']->id])->toMatchArray(['opening' => 30.0, 'opening_source' => 'baseline', 'purchases' => 12.0])
        ->and($maret[$items['kosong']->id])->toMatchArray(['opening' => 0.0, 'purchases' => 0.0, 'ending' => 0.0, 'usage' => 0.0]);
})->with(['residual', 'resep']);

it('menghitung banyak bahan dengan jumlah query tetap, bukan per bahan', function () {
    skenarioPemakaian();
    foreach (range(1, 20) as $n) {
        $item = bahanBatchUji('Bahan '.$n);
        opnameUji($item, '2026-02-28', 10 + $n);
        pembelianUji($item, '2026-03-10', 5);
    }

    $ids = InventoryItem::query()->pluck('id');
    $service = app(InventoryUsageService::class);
    [$from, $to] = [Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31')];

    DB::flushQueryLog();
    DB::enableQueryLog();
    $service->summariesForItems($ids, $from, $to);
    $batchQueries = count(DB::getQueryLog());

    // Positive control: jalur per bahan memang membengkak mengikuti jumlah bahan.
    DB::flushQueryLog();
    $ids->each(fn ($id) => $service->calculateForItem($id, $from, $to));
    $perItemQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($ids->count())->toBeGreaterThan(20)
        ->and($batchQueries)->toBeLessThanOrEqual(8)
        ->and($perItemQueries)->toBeGreaterThan($ids->count() * 4);
});

it('membuka Analisa HPP setahun tanpa ribuan query', function () {
    Role::findOrCreate('owner', 'web');
    $owner = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $owner->assignRole('owner');

    skenarioPemakaian();
    foreach (range(1, 20) as $n) {
        $item = bahanBatchUji('Bahan '.$n);
        opnameUji($item, '2026-02-28', 10 + $n);
        pembelianUji($item, '2026-03-10', 5);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($owner)
        ->get(route('ownerapp.hpp-analysis', ['date_from' => '2026-01-01', 'date_to' => '2026-12-31']))
        ->assertOk()
        ->assertSee('Analisa HPP');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // 13 hitungan Laba Rugi (setahun + 12 bulan) x 25 bahan: jalur per bahan
    // butuh >2.000 query di sini; jalur batch tidak bergantung jumlah bahan.
    expect($queries)->toBeLessThan(600);
});
