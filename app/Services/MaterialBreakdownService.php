<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\Recipe;
use Illuminate\Support\Collection;

/**
 * Breakdown menu -> bahan mentah untuk sebuah pesanan (PO) atau SPK Produksi,
 * dicocokkan dengan stok di kartu stok.
 *
 * Padanan "Pra SPK" di aplikasi Master Menu: tiap menu dipecah sampai bahan
 * mentah (sub-resep ikut diurai), lalu direkap per bahan. Bedanya, rekap di
 * sini sekaligus menunjukkan stok sistem dan yang perlu dibeli:
 *
 *   Perlu Beli = max(0, Kebutuhan - Stok Sistem)
 *
 * Stok sistem = saldo kartu stok per hari ini. Bahan yang belum pernah punya
 * gerakan di kartu stok dianggap "belum tercatat" -- bukan nol -- dan seluruh
 * kebutuhannya dihitung sebagai perlu beli, dengan tanda, supaya angka nol
 * yang sebenarnya "tidak tahu" tidak dibaca sebagai "stok habis".
 *
 * Menu tanpa resep (atau yang resepnya belum bisa dihitung) tidak diam-diam
 * dianggap nol: dicantumkan di daftar `missing` / `issues`, dan cakupan
 * (berapa porsi yang terhitung) ikut dilaporkan.
 */
class MaterialBreakdownService
{
    /** @var array<int, array{rows: Collection<int, array<string, mixed>>, issues: array<int, string>}> */
    protected array $perYield = [];

    public function __construct(
        protected RecipeCostService $costService,
        protected IngredientUnitConverter $converter,
        protected InventoryLedgerService $ledger,
    ) {}

    /** Breakdown satu pesanan: satu kartu per item PO. */
    public function forPurchaseOrder(PurchaseOrder $po): array
    {
        $po->loadMissing('items.product.recipe');

        $lines = $po->items->map(fn ($item) => [
            'label' => $item->is_custom ? ($item->custom_name ?: 'Item khusus') : ($item->product?->name ?? 'Produk #'.$item->product_id),
            'recipe' => $this->activeRecipe($item->product),
            'qty' => (float) $item->qty,
            'unit' => $item->unit ?: 'porsi',
        ]);

        return $this->build($lines);
    }

    /**
     * Breakdown satu SPK Produksi (gabungan semua PO di slotnya). Menu yang
     * sama dari beberapa PO digabung menjadi satu kartu dengan total porsinya,
     * seperti Total Produksi di lembar plating.
     */
    public function forProductionOrder(ProductionOrder $order): array
    {
        $order->loadMissing('lines.recipe');

        $lines = $order->lines
            ->groupBy(fn ($line) => $line->recipe_id ? 'r'.$line->recipe_id.'|'.mb_strtolower((string) $line->unit) : 'l'.$line->id)
            ->map(function (Collection $group) {
                $first = $group->first();

                return [
                    'label' => $first->recipe?->name ?? $first->displayName(),
                    'recipe' => $first->recipe_id ? $first->recipe : null,
                    'qty' => (float) $group->sum('qty'),
                    'unit' => $first->unit ?: 'porsi',
                ];
            })
            ->values();

        return $this->build($lines);
    }

    /**
     * @param  Collection<int, array{label: string, recipe: ?Recipe, qty: float, unit: string}>  $lines
     * @return array{menus: list<array<string, mixed>>, recap: list<array<string, mixed>>, missing: list<array{label: string, qty: float, unit: string}>, issues: list<string>, summary: array<string, mixed>}
     */
    protected function build(Collection $lines): array
    {
        $menus = [];
        $missing = [];
        $issues = [];
        $recap = [];

        foreach ($lines as $line) {
            $recipe = $line['recipe'];

            if (! $recipe) {
                $missing[] = ['label' => $line['label'], 'qty' => $line['qty'], 'unit' => $line['unit']];

                continue;
            }

            $menu = [
                'label' => $line['label'],
                'recipe' => $recipe->name,
                'qty' => $line['qty'],
                'unit' => $line['unit'],
                'rows' => [],
                'total_cost' => 0.0,
                'issues' => [],
            ];

            // Porsi pesanan harus sepadan dengan satuan hasil resep.
            $quantity = $this->converter->convertByRegistry($line['qty'], $line['unit'], $recipe->yield_unit);

            if ($quantity === null) {
                $menu['issues'][] = 'Satuan "'.$line['unit'].'" tidak sepadan dengan satuan hasil resep "'.$recipe->yield_unit.'".';
            } else {
                $result = $this->scaled($recipe, $quantity);
                $menu['rows'] = $result['rows'];
                $menu['total_cost'] = round(array_sum(array_column($result['rows'], 'total_cost')), 2);
                $menu['issues'] = $result['issues'];

                foreach ($result['rows'] as $row) {
                    $key = $row['inventory_item_id'];
                    $recap[$key] ??= [
                        'inventory_item_id' => $key,
                        'name' => $row['name'],
                        'unit' => $row['unit'],
                        'qty' => 0.0,
                        'unit_price' => $row['unit_price'],
                        'menus' => [],
                    ];
                    $recap[$key]['qty'] = round($recap[$key]['qty'] + $row['qty'], 4);
                    $recap[$key]['menus'][$line['label']] = true;
                }
            }

            foreach ($menu['issues'] as $issue) {
                $issues[] = $line['label'].': '.$issue;
            }

            $menus[] = $menu;
        }

        $recap = $this->matchStock($recap);

        $totalQty = (float) $lines->sum('qty');
        $countedQty = (float) collect($menus)->filter(fn ($menu) => $menu['issues'] === [])->sum('qty');

        return [
            'menus' => $menus,
            'recap' => $recap,
            'missing' => $missing,
            'issues' => array_values(array_unique($issues)),
            'summary' => [
                'menu_count' => $lines->count(),
                'menu_with_recipe' => count($menus),
                // Porsi yang breakdown-nya lengkap: punya resep dan tanpa masalah hitung.
                'coverage_pct' => $totalQty > 0 ? round($countedQty / $totalQty * 100, 1) : null,
                'ingredient_count' => count($recap),
                'to_buy_count' => count(array_filter($recap, fn ($row) => $row['to_buy'] > 0)),
                'unknown_stock_count' => count(array_filter($recap, fn ($row) => $row['stock'] === null)),
                'total_cost' => round(array_sum(array_column($recap, 'total_cost')), 2),
                'to_buy_cost' => round(array_sum(array_column($recap, 'to_buy_cost')), 2),
            ],
        ];
    }

    /**
     * Kebutuhan resep untuk sejumlah hasil. Resep diurai sekali per resep
     * (untuk satu kali hasil) lalu diskalakan -- kebutuhan bersifat linear
     * terhadap jumlah, dan satu SPK bisa memuat menu yang sama berkali-kali.
     *
     * @return array{rows: list<array<string, mixed>>, issues: array<int, string>}
     */
    protected function scaled(Recipe $recipe, float $quantity): array
    {
        $yield = (float) $recipe->yield_qty;

        if ($yield <= 0) {
            return ['rows' => [], 'issues' => ['Resep "'.$recipe->name.'" tidak punya jumlah hasil (yield).']];
        }

        $base = $this->perYield[$recipe->id] ??= $this->costService->requirements($recipe, $yield);
        $factor = $quantity / $yield;

        $rows = $base['rows']
            ->map(function (array $row) use ($factor) {
                $qty = round((float) $row['qty'] * $factor, 4);

                return [
                    'inventory_item_id' => $row['inventory_item_id'],
                    'name' => $row['name'],
                    'unit' => $row['unit'],
                    'qty' => $qty,
                    'unit_price' => $row['unit_price'],
                    'total_cost' => round($qty * (float) ($row['unit_price'] ?? 0), 2),
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return ['rows' => $rows, 'issues' => $base['issues']];
    }

    /**
     * Tempel stok sistem dan hitung perlu beli per bahan.
     *
     * @param  array<int, array<string, mixed>>  $recap
     * @return list<array<string, mixed>>
     */
    protected function matchStock(array $recap): array
    {
        $ids = array_keys($recap);
        $tracked = InventoryMovement::query()->whereIn('inventory_item_id', $ids)->distinct()->pluck('inventory_item_id')->flip();
        $balances = $this->ledger->balances($ids, now()->toDateString());

        return collect($recap)
            ->map(function (array $row) use ($tracked, $balances) {
                $stock = $tracked->has($row['inventory_item_id']) ? max(0.0, (float) $balances[$row['inventory_item_id']]) : null;
                $toBuy = round(max(0.0, $row['qty'] - ($stock ?? 0.0)), 4);
                $price = (float) ($row['unit_price'] ?? 0);

                return array_merge($row, [
                    'stock' => $stock,
                    'to_buy' => $toBuy,
                    'total_cost' => round($row['qty'] * $price, 2),
                    'to_buy_cost' => round($toBuy * $price, 2),
                    'menus' => array_keys($row['menus']),
                ]);
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    protected function activeRecipe(?Product $product): ?Recipe
    {
        $recipe = $product?->recipe;

        return $recipe && $recipe->is_active ? $recipe : null;
    }
}
