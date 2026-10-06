<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeMismatch;
use Illuminate\Support\Facades\DB;

/**
 * Menyelesaikan bahan resep yang belum punya padanan di master bahan.
 *
 * Dari 11.595 baris resep, 4.878 tidak menunjuk bahan maupun sub-resep --
 * tetapi hanya 365 nama unik. Karena itu keputusannya diambil per nama: sekali
 * "garam" ditautkan, ratusan baris di puluhan resep ikut selesai.
 *
 * Pencocokan barisnya memakai nama ternormalisasi yang sama dengan yang dipakai
 * saat daftar ini disusun, dan dikerjakan di PHP: perapatan spasi ganda tidak
 * bisa dinyatakan sebagai kondisi SQL yang sama persis di MySQL dan SQLite,
 * dan beda tipis di antara keduanya berarti sebagian baris diam-diam terlewat.
 */
class RecipeMismatchResolver
{
    /**
     * Tautkan seluruh baris bernama sama ke sebuah bahan.
     *
     * @return int Jumlah baris resep yang ikut selesai.
     */
    public function linkToItem(RecipeMismatch $mismatch, InventoryItem $item, ?string $note = null, ?int $userId = null): int
    {
        return DB::transaction(function () use ($mismatch, $item, $note, $userId) {
            $affected = $this->applyToRows($mismatch->raw_name_norm, $item->id);

            $mismatch->update([
                'status' => RecipeMismatch::STATUS_LINKED,
                'resolved_inventory_item_id' => $item->id,
                'resolved_at' => now(),
                'resolved_by' => $userId,
                'resolution_note' => $note,
            ]);

            return $affected;
        });
    }

    /**
     * Buat bahan baru untuk nama ini, lalu tautkan seluruh barisnya.
     *
     * Bahan baru selalu bergabung ke salah satu bucket lama, bukan berdiri
     * sendiri: bucket itulah yang memegang seluruh histori pembelian dan opname
     * dan menjadi sumber angka Laba Rugi.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{item: InventoryItem, affected: int}
     */
    public function createItem(RecipeMismatch $mismatch, array $attributes, ?int $userId = null): array
    {
        return DB::transaction(function () use ($mismatch, $attributes, $userId) {
            $category = $attributes['category'] ?? InventoryItem::CATEGORY_RAW_MATERIAL;

            $item = InventoryItem::query()->create([
                'parent_id' => $attributes['parent_id'] ?? $this->bucketIdFor($category),
                'name' => trim((string) ($attributes['name'] ?? $mismatch->raw_name)),
                'unit' => $attributes['unit'] ?? ($mismatch->sample_unit ?: 'pcs'),
                'category' => $category,
                'ingredient_group' => $attributes['ingredient_group'] ?? null,
                'unit_price' => $attributes['unit_price'] ?? $mismatch->assumed_unit_price,
                'is_active' => true,
            ]);

            $affected = $this->applyToRows($mismatch->raw_name_norm, $item->id);

            $mismatch->update([
                'status' => RecipeMismatch::STATUS_CREATED,
                'resolved_inventory_item_id' => $item->id,
                'resolved_at' => now(),
                'resolved_by' => $userId,
                'resolution_note' => $attributes['resolution_note'] ?? null,
            ]);

            return ['item' => $item, 'affected' => $affected];
        });
    }

    /**
     * Tandai nama ini bukan bahan, mis. keterangan atau langkah memasak.
     *
     * Barisnya sengaja tidak disentuh: yang diabaikan adalah keputusannya,
     * bukan datanya, dan baris itu tetap harus terlihat apa adanya di resep.
     */
    public function ignore(RecipeMismatch $mismatch, ?string $note = null, ?int $userId = null): void
    {
        $mismatch->update([
            'status' => RecipeMismatch::STATUS_IGNORED,
            'resolved_inventory_item_id' => null,
            'resolved_at' => now(),
            'resolved_by' => $userId,
            'resolution_note' => $note,
        ]);
    }

    /** Kembalikan sebuah keputusan ke keadaan belum diputuskan. */
    public function reopen(RecipeMismatch $mismatch): int
    {
        return DB::transaction(function () use ($mismatch) {
            $affected = $mismatch->resolved_inventory_item_id !== null
                ? $this->detachRows($mismatch->raw_name_norm, $mismatch->resolved_inventory_item_id)
                : 0;

            $mismatch->update([
                'status' => RecipeMismatch::STATUS_OPEN,
                'resolved_inventory_item_id' => null,
                'resolved_at' => null,
                'resolved_by' => null,
            ]);

            return $affected;
        });
    }

    /**
     * Terapkan ulang seluruh keputusan yang sudah pernah diambil.
     *
     * Perpindahan data dari Master Menu menyusun ulang seluruh baris resep
     * setiap kali dijalankan. Tanpa langkah ini, tiap penyegaran data akan
     * menghapus hasil rekonsiliasi bersama klien dan pekerjaannya harus
     * diulang dari nol.
     *
     * @return array{keputusan: int, baris: int}
     */
    public function reapplyAll(): array
    {
        $decisions = RecipeMismatch::query()
            ->whereNotNull('resolved_inventory_item_id')
            ->whereIn('status', [RecipeMismatch::STATUS_LINKED, RecipeMismatch::STATUS_CREATED])
            ->get(['raw_name_norm', 'resolved_inventory_item_id']);

        $rows = 0;

        foreach ($decisions as $decision) {
            $rows += $this->applyToRows($decision->raw_name_norm, (int) $decision->resolved_inventory_item_id);
        }

        return ['keputusan' => $decisions->count(), 'baris' => $rows];
    }

    /**
     * Susun ulang daftar bahan yang belum punya padanan.
     *
     * Dikelompokkan per nama, bukan per baris: 4.878 baris yatim hanya berisi
     * ratusan nama unik, dan satu keputusan menautkan ratusan baris sekaligus.
     * Baris yang sudah pernah diputuskan hanya disegarkan hitungannya -- kalau
     * statusnya ikut disetel ulang, seluruh kerja rekonsiliasi hilang setiap
     * kali data resep disegarkan.
     *
     * Dipakai perpindahan data Master Menu maupun import resep, supaya daftar
     * kerjanya tidak pernah tertinggal dari data resep yang sebenarnya.
     *
     * @return array<string, int>
     */
    public function rebuild(): array
    {
        $groups = [];

        RecipeItem::query()
            ->unmatched()
            ->select(['raw_name', 'recipe_id', 'unit', 'unit_price_snapshot'])
            ->cursor()
            ->each(function (RecipeItem $item) use (&$groups) {
                $norm = Recipe::normalizeName($item->raw_name);

                if ($norm === '') {
                    return;
                }

                if (! isset($groups[$norm])) {
                    $groups[$norm] = [
                        'raw_name' => $item->raw_name,
                        'occurrence_count' => 0,
                        'recipes' => [],
                        'sample_unit' => $item->unit,
                        'assumed_unit_price' => $item->unit_price_snapshot,
                    ];
                }

                $groups[$norm]['occurrence_count']++;
                $groups[$norm]['recipes'][$item->recipe_id] = true;

                if ($groups[$norm]['assumed_unit_price'] === null && $item->unit_price_snapshot !== null) {
                    $groups[$norm]['assumed_unit_price'] = $item->unit_price_snapshot;
                }
            });

        $touched = 0;

        foreach ($groups as $norm => $group) {
            $counts = [
                'occurrence_count' => $group['occurrence_count'],
                'recipe_count' => count($group['recipes']),
                'sample_unit' => $group['sample_unit'],
                'assumed_unit_price' => $group['assumed_unit_price'],
            ];

            $existing = RecipeMismatch::query()->where('raw_name_norm', $norm)->first();

            if ($existing) {
                $existing->update($counts);
                $touched++;

                continue;
            }

            RecipeMismatch::query()->create($counts + [
                'raw_name' => $group['raw_name'],
                'raw_name_norm' => $norm,
                'status' => RecipeMismatch::STATUS_OPEN,
            ]);
            $touched++;
        }

        return [
            'nama_unik' => $touched,
            'belum_diputuskan' => RecipeMismatch::query()->open()->count(),
        ];
    }

    /** Jumlah baris resep yang akan ikut terpengaruh oleh sebuah keputusan. */
    public function affectedRowCount(string $rawNameNorm): int
    {
        return count($this->unmatchedRowIds($rawNameNorm));
    }

    /** Tautkan seluruh baris yatim bernama sama ke sebuah bahan. */
    protected function applyToRows(string $rawNameNorm, int $inventoryItemId): int
    {
        $ids = $this->unmatchedRowIds($rawNameNorm);

        if ($ids === []) {
            return 0;
        }

        return RecipeItem::query()->whereIn('id', $ids)->update([
            'inventory_item_id' => $inventoryItemId,
        ]);
    }

    /** Lepaskan kembali baris yang tertaut karena sebuah keputusan. */
    protected function detachRows(string $rawNameNorm, int $inventoryItemId): int
    {
        $ids = RecipeItem::query()
            ->where('inventory_item_id', $inventoryItemId)
            ->whereNull('ref_recipe_id')
            ->get(['id', 'raw_name'])
            ->filter(fn (RecipeItem $item) => Recipe::normalizeName($item->raw_name) === $rawNameNorm)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        return RecipeItem::query()->whereIn('id', $ids)->update(['inventory_item_id' => null]);
    }

    /**
     * Id baris yatim yang namanya sama setelah dinormalkan.
     *
     * @return array<int, int>
     */
    protected function unmatchedRowIds(string $rawNameNorm): array
    {
        return RecipeItem::query()
            ->unmatched()
            ->get(['id', 'raw_name'])
            ->filter(fn (RecipeItem $item) => Recipe::normalizeName($item->raw_name) === $rawNameNorm)
            ->pluck('id')
            ->all();
    }

    /** Bucket induk untuk kategori bahan baru. */
    protected function bucketIdFor(string $category): ?int
    {
        return InventoryItem::query()
            ->buckets()
            ->where('category', $category)
            ->orderBy('id')
            ->value('id');
    }
}
