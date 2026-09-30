<?php

namespace App\Services;

use App\Models\InventoryMovement;
use Illuminate\Support\Carbon;
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
            ->when($asOf, fn ($query) => $query->where('moved_at', '<', self::dayAfter($asOf)))
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
            ->when($asOf, fn ($query) => $query->where('moved_at', '<', self::dayAfter($asOf)))
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
            ->where('moved_at', '>=', self::day($from))->where('moved_at', '<', self::dayAfter($to))
            ->sum('total_value'), 2);
    }

    /**
     * valueForBucket() untuk banyak bucket sekaligus: satu query, dijumlah per
     * bucket (parent_id bahan). Bucket tanpa gerakan bernilai 0.
     *
     * @param  array<int, int>  $bucketIds
     * @return array<int, float>
     */
    public function valuesForBuckets(array $bucketIds, string $type, string $from, string $to): array
    {
        if ($bucketIds === []) {
            return [];
        }

        return InventoryMovement::query()
            ->join('inventory_items', 'inventory_items.id', '=', 'inventory_movements.inventory_item_id')
            ->whereIn('inventory_items.parent_id', $bucketIds)
            ->where('inventory_movements.type', $type)
            ->where('inventory_movements.moved_at', '>=', self::day($from))->where('inventory_movements.moved_at', '<', self::dayAfter($to))
            ->groupBy('inventory_items.parent_id')
            ->selectRaw('inventory_items.parent_id as bucket_id, SUM(inventory_movements.total_value) as total')
            ->pluck('total', 'bucket_id')
            ->map(fn ($total) => round((float) $total, 2))
            ->all();
    }

    /**
     * Filter tanggal moved_at ditulis `>= hari` dan `< hari berikutnya` supaya
     * index (type, moved_at) terpakai -- whereDate() membungkus kolom dengan
     * DATE() dan memaksa pemindaian seluruh riwayat kartu stok. Batas atas
     * eksklusif juga benar bila tanggal tersimpan beserta jam (SQLite).
     */
    public static function day(string $date): string
    {
        return Carbon::parse($date)->toDateString();
    }

    public static function dayAfter(string $date): string
    {
        return Carbon::parse($date)->addDay()->toDateString();
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
