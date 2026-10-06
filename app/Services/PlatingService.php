<?php

namespace App\Services;

use App\Models\ProductionOrder;
use App\Models\Recipe;
use App\Models\RecipeItem;

/**
 * Lembar plating sebuah SPK Produksi.
 *
 * Tiap menu ditampilkan beserta komponen yang harus ada di piring: nama
 * sub-menu bila barisnya menunjuk sub-menu, atau nama kelompok (section)
 * bila tidak. Bahan mentah tanpa kelompok tidak ditampilkan -- yang menata
 * piring tidak perlu tahu berapa gram garam di dalam sambal.
 *
 * Meniru lib/plating.ts pada Master Menu Revamp, dengan menu digabung per
 * resep supaya dua item PO untuk menu yang sama tampil sebagai satu baris.
 */
class PlatingService
{
    /** Slot bernomor minimal per kartu menu; sisanya untuk catatan tangan. */
    public const MIN_SLOTS = 8;

    /**
     * @return array{rows: array<int, array<string, mixed>>, total_qty: float}
     */
    public function sheet(ProductionOrder $order): array
    {
        $rows = [];
        $total = 0.0;

        foreach ($order->lines()->with('recipe')->get() as $line) {
            $key = $line->recipe_id ? 'r'.$line->recipe_id : 'm'.$line->id;
            $total += (float) $line->qty;

            if (isset($rows[$key])) {
                $rows[$key]['qty'] = round($rows[$key]['qty'] + (float) $line->qty, 4);

                continue;
            }

            $rows[$key] = [
                'name' => $line->displayName(),
                'qty' => (float) $line->qty,
                'unit' => $line->unit,
                'remark' => $line->remark,
                'components' => $line->recipe ? $this->componentsOf($line->recipe) : [],
            ];
        }

        return [
            'rows' => array_values($rows),
            'total_qty' => round($total, 4),
        ];
    }

    /**
     * Komponen plating sebuah menu, tanpa duplikat, mengikuti urutan resep.
     *
     * @return array<int, string>
     */
    public function componentsOf(Recipe $recipe): array
    {
        $components = [];
        $seen = [];

        $items = $recipe->items()->with('refRecipe')->get();

        foreach ($items as $item) {
            /** @var RecipeItem $item */
            $name = $item->ref_recipe_id
                ? trim((string) ($item->refRecipe?->name ?? ''))
                : trim((string) ($item->section ?? ''));

            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($name);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $components[] = $name;
        }

        return $components;
    }
}
