<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Support\Str;

/**
 * Saran rincian Barang Sisa dari HPP resep (revisi v3.1).
 *
 * Komponen = bagian resep menu (nasi kuning, perkedel, ayam bakar, ...). Baris
 * tanpa bagian menjadi komponen sendiri; kemasan (dus, sendok, stiker)
 * dilewati karena tidak bisa dijual ulang.
 *
 * Nilai saran tidak memakai biaya resep hari ini secara langsung, melainkan
 * membagi HPP yang tercatat saat menu dijual (raw_material_cost Sales Actual)
 * menurut porsi biaya tiap bagian:
 *
 *     nilai bagian per porsi = HPP tercatat x (biaya bagian / biaya resep total)
 *
 * Dengan begitu jumlah nilai saran tidak pernah melebihi HPP porsi (syarat
 * LeftoverStockService::assertValueWithinHpp), walau harga bahan sudah berubah.
 * Harga jual saran memakai rasio yang sama terhadap harga jual menu.
 */
class LeftoverComponentSuggester
{
    public function __construct(private readonly RecipeCostService $costs) {}

    /**
     * Komponen saran untuk SATU porsi.
     *
     * @return list<array{name: string, unit: string, share: float, value_per_portion: float, price_per_portion: float}>
     */
    public function perPortion(?Product $product, float $unitHpp, float $unitPrice): array
    {
        $recipe = $product?->recipe;

        if (! $recipe instanceof Recipe || (float) $recipe->yield_qty <= 0) {
            return [];
        }

        $tree = $this->costs->tree($recipe, 1);
        $total = (float) $tree['total_cost'];

        if ($total <= 0) {
            return [];
        }

        $packaging = InventoryItem::query()
            ->where('category', InventoryItem::CATEGORY_PACKAGING)
            ->whereIn('name', collect($tree['nodes'])->where('kind', 'ingredient')->pluck('name')->unique()->all())
            ->pluck('name')
            ->flip();

        // Kelompokkan simpul teratas per bagian resep, urutan pertama kali muncul.
        $groups = [];
        foreach ($tree['nodes'] as $node) {
            if ($node['kind'] === 'ingredient' && $packaging->has($node['name'])) {
                continue;
            }

            $label = trim((string) ($node['section'] ?: $node['name']));
            $key = mb_strtolower($label);
            $groups[$key] ??= ['name' => Str::title($label), 'cost' => 0.0];
            $groups[$key]['cost'] += (float) ($node['cost'] ?? 0);
        }

        $rows = [];
        foreach ($groups as $group) {
            if ($group['cost'] <= 0) {
                continue;
            }

            $share = $group['cost'] / $total;
            $rows[] = [
                'name' => $group['name'],
                'unit' => 'porsi',
                'share' => round($share, 6),
                'value_per_portion' => round($unitHpp * $share, 4),
                'price_per_portion' => round($unitPrice * $share, 4),
            ];
        }

        return $rows;
    }

    /**
     * Komponen saran untuk sejumlah porsi, siap diisi ke form rincian. Nilai
     * dibulatkan ke bawah (sen) supaya totalnya tidak pernah melebihi HPP porsi.
     *
     * @return list<array{name: string, qty: float, unit: string, value: float}>
     */
    public function forPortions(?Product $product, float $unitHpp, float $portionQty): array
    {
        return array_map(fn (array $row) => [
            'name' => $row['name'],
            'qty' => round($portionQty, 2),
            'unit' => $row['unit'],
            'value' => floor($row['value_per_portion'] * $portionQty * 100) / 100,
        ], $this->perPortion($product, $unitHpp, 0));
    }

    /**
     * Harga jual saran per satuan komponen: nilai HPP per satuan dikali rasio
     * harga jual / HPP menu asalnya (markup menu yang sama).
     */
    public function salePrice(float $componentUnitCost, float $menuUnitPrice, float $menuUnitHpp): ?float
    {
        if ($menuUnitHpp <= 0 || $menuUnitPrice <= 0) {
            return null;
        }

        return round($componentUnitCost * $menuUnitPrice / $menuUnitHpp, 0);
    }
}
