<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Laporan stok per jenis barang, mengikuti format laporan stok harian yang
 * sudah berjalan di gudang klien ("Stok 3S <tanggal>.xlsx"):
 *
 *     Nama Barang | Stok awal | Satuan | Masuk | Keluar | Harga | Mutasi | Sisa | Value
 *
 * dengan satu lembar per jenis (Bahan Baku, Kemasan, Inventaris), bukan per
 * kelompok bahan. Semua angka dari kartu stok:
 *
 *     stok awal = saldo sampai hari sebelum periode
 *     masuk     = pembelian + saldo awal yang tercatat di dalam periode
 *     keluar    = pemakaian (SPK/resep), ditulis positif
 *     mutasi    = koreksi stock opname (+/-)
 *     sisa      = stok awal + masuk - keluar + mutasi   (= saldo akhir periode)
 *     value     = sisa x harga satuan berlaku
 */
class StockReportService
{
    /** Jenis barang => judul lembar, urutan sama dengan berkas klien. */
    public const SHEETS = [
        InventoryItem::CATEGORY_RAW_MATERIAL => 'BAHAN BAKU',
        InventoryItem::CATEGORY_PACKAGING => 'KEMASAN',
        InventoryItem::CATEGORY_FIXED_ASSET => 'INVENTARIS',
    ];

    /**
     * @return Collection<int, array{name: string, opening: float, unit: string, in: float, out: float, price: ?float, mutation: float, closing: float}>
     */
    public function rows(string $category, CarbonInterface $from, CarbonInterface $to): Collection
    {
        // Baris induk ("Bahan Baku", "Packaging", "Inventaris All") hanya pengelompok, bukan barang.
        $parentIds = InventoryItem::query()->whereNotNull('parent_id')->distinct()->pluck('parent_id');

        $items = InventoryItem::query()
            ->where('category', $category)
            ->whereNotIn('id', $parentIds)
            ->orderBy('name')
            ->get();

        if ($items->isEmpty()) {
            return collect();
        }

        $ids = $items->pluck('id')->all();
        $start = $from->copy()->startOfDay()->toDateString();
        $end = $to->copy()->addDay()->startOfDay()->toDateString();

        $opening = InventoryMovement::query()
            ->whereIn('inventory_item_id', $ids)
            ->where('moved_at', '<', $start)
            ->selectRaw('inventory_item_id, SUM(qty) as qty')
            ->groupBy('inventory_item_id')
            ->pluck('qty', 'inventory_item_id');

        $period = InventoryMovement::query()
            ->whereIn('inventory_item_id', $ids)
            ->where('moved_at', '>=', $start)
            ->where('moved_at', '<', $end)
            ->selectRaw('inventory_item_id, type, SUM(qty) as qty')
            ->groupBy('inventory_item_id', 'type')
            ->get()
            ->groupBy('inventory_item_id');

        return $items
            ->map(function (InventoryItem $item) use ($opening, $period) {
                $moves = ($period[$item->id] ?? collect())->pluck('qty', 'type')->map(fn ($qty) => (float) $qty);
                $open = round((float) ($opening[$item->id] ?? 0), 4);
                $in = round((float) ($moves[InventoryMovement::TYPE_PURCHASE] ?? 0) + (float) ($moves[InventoryMovement::TYPE_OPENING] ?? 0), 4);
                $out = round(-(float) ($moves[InventoryMovement::TYPE_USAGE] ?? 0), 4);
                $mutation = round((float) ($moves[InventoryMovement::TYPE_ADJUSTMENT] ?? 0), 4);

                return [
                    'name' => $item->name,
                    'active' => (bool) $item->is_active,
                    'opening' => $open,
                    'unit' => (string) $item->unit,
                    'in' => $in,
                    'out' => $out,
                    'price' => $item->effectiveUnitPrice(),
                    'mutation' => $mutation,
                    'closing' => round($open + $in - $out + $mutation, 4),
                ];
            })
            // Barang nonaktif yang sudah tidak punya stok dan tidak bergerak tidak perlu ikut.
            ->reject(fn (array $row) => ! $row['active'] && abs($row['closing']) < 0.00001 && abs($row['in']) + abs($row['out']) + abs($row['mutation']) < 0.00001)
            ->map(fn (array $row) => collect($row)->except('active')->all())
            ->values();
    }
}
