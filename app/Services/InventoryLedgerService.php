<?php

namespace App\Services;

use App\Models\InventoryMovement;
use Illuminate\Support\Collection;

/**
 * Ledger kuantitas stok per bahan.
 *
 * Satu-satunya pintu untuk menulis inventory_movements. Semua kuantitas
 * bertanda (masuk positif, keluar negatif) dan dalam satuan harga bahan;
 * pemanggil yang harus mengonversi sebelum sampai ke sini, supaya saldo bisa
 * dijumlahkan tanpa berpikir.
 */
class InventoryLedgerService
{
    /**
     * Catat satu gerakan stok.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function post(
        int $inventoryItemId,
        string $type,
        float $qty,
        string $unit,
        string $movedAt,
        ?float $unitPrice = null,
        array $attributes = [],
    ): InventoryMovement {
        $qty = round($qty, 4);

        return InventoryMovement::query()->create($attributes + [
            'inventory_item_id' => $inventoryItemId,
            'moved_at' => $movedAt,
            'type' => $type,
            'qty' => $qty,
            'unit' => $unit,
            'unit_price' => $unitPrice,
            // Nilainya ikut bertanda supaya laporan cukup menjumlahkan kolom ini.
            'total_value' => $unitPrice === null ? null : round($qty * $unitPrice, 2),
        ]);
    }

    /** Saldo kuantitas sebuah bahan sampai tanggal tertentu (inklusif). */
    public function balance(int $inventoryItemId, ?string $asOf = null): float
    {
        return round((float) InventoryMovement::query()
            ->where('inventory_item_id', $inventoryItemId)
            ->when($asOf, fn ($query) => $query->whereDate('moved_at', '<=', $asOf))
            ->sum('qty'), 4);
    }

    /**
     * Saldo beberapa bahan sekaligus, dikunci pada bahan yang diminta.
     *
     * @param  array<int, int>  $inventoryItemIds
     * @return Collection<int, float> id bahan => saldo
     */
    public function balances(array $inventoryItemIds, ?string $asOf = null): Collection
    {
        if ($inventoryItemIds === []) {
            return collect();
        }

        $sums = InventoryMovement::query()
            ->whereIn('inventory_item_id', $inventoryItemIds)
            ->when($asOf, fn ($query) => $query->whereDate('moved_at', '<=', $asOf))
            ->selectRaw('inventory_item_id, SUM(qty) as total')
            ->groupBy('inventory_item_id')
            ->pluck('total', 'inventory_item_id');

        return collect($inventoryItemIds)
            ->mapWithKeys(fn (int $id) => [$id => round((float) ($sums[$id] ?? 0), 4)]);
    }

    /**
     * Nilai gerakan sebuah jenis untuk seluruh bahan di bawah satu bucket.
     *
     * Dipakai laporan pemakaian: pemakaian resep sebuah bucket adalah jumlah
     * gerakan `usage` bahan-bahan anaknya. Hasilnya bertanda seperti ledger
     * (pemakaian negatif); pemanggil yang membalik tandanya untuk laporan.
     */
    public function valueForBucket(int $bucketId, string $type, string $from, string $to): float
    {
        return round((float) InventoryMovement::query()
            ->whereHas('item', fn ($query) => $query->where('parent_id', $bucketId))
            ->where('type', $type)
            ->whereBetween('moved_at', [$from, $to])
            ->sum('total_value'), 2);
    }

    /** Bahan ini sudah pernah tercatat di ledger, apa pun jenisnya. */
    public function hasHistory(int $inventoryItemId): bool
    {
        return InventoryMovement::query()->where('inventory_item_id', $inventoryItemId)->exists();
    }

    /**
     * Bahan-bahan yang belum pernah tercatat di ledger.
     *
     * @param  array<int, int>  $inventoryItemIds
     * @return array<int, int>
     */
    public function withoutHistory(array $inventoryItemIds): array
    {
        if ($inventoryItemIds === []) {
            return [];
        }

        $known = InventoryMovement::query()
            ->whereIn('inventory_item_id', $inventoryItemIds)
            ->distinct()
            ->pluck('inventory_item_id')
            ->all();

        return array_values(array_diff($inventoryItemIds, $known));
    }
}
