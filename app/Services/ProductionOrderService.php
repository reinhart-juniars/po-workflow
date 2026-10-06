<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderLine;
use App\Models\ProductionTask;
use App\Models\PurchaseOrderItem;
use App\Models\Spk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Menyusun SPK Produksi dan meledakkan kebutuhan bahannya.
 *
 * Sumber pesanannya adalah PO yang sudah ada di po-workflow, bukan modul Order
 * baru: item PO -> produk -> resep lewat products.recipe_id. Item yang
 * produknya belum punya resep tetap masuk sebagai baris manual yang ditandai,
 * supaya dapur tetap melihat seluruh pesanan dan tahu mana yang belum bisa
 * dihitung bahannya.
 */
class ProductionOrderService
{
    public function __construct(
        protected RecipeCostService $costService,
        protected IngredientUnitConverter $converter,
    ) {}

    /**
     * Buat atau segarkan SPK Produksi dari sebuah slot jadwal.
     *
     * Dijalankan ulang ketika PO di slot itu berubah: baris yang lahir dari
     * item PO dicocokkan lewat purchase_order_item_id, baris manual yang
     * ditambah dapur tidak disentuh.
     */
    public function generateFromSpk(Spk $spk, ?int $userId = null): ProductionOrder
    {
        return DB::transaction(function () use ($spk, $userId) {
            $order = ProductionOrder::query()->firstOrCreate(
                ['spk_id' => $spk->id],
                [
                    'title' => 'SPK Produksi '.$spk->spk_code,
                    'production_date' => $spk->scheduled_at?->toDateString() ?? now()->toDateString(),
                    'production_time' => $spk->scheduled_at?->format('H.i'),
                    'status' => ProductionOrder::STATUS_DRAFT,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ],
            );

            if (! $order->isEditable()) {
                throw new RuntimeException('SPK Produksi '.$order->number.' sudah '.mb_strtolower($order->statusLabel()).'; barisnya tidak bisa disusun ulang.');
            }

            $items = PurchaseOrderItem::query()
                ->whereIn('purchase_order_id', $spk->purchaseOrders()->pluck('purchase_orders.id'))
                ->with(['product', 'purchaseOrder'])
                ->orderBy('purchase_order_id')
                ->orderBy('id')
                ->get();

            // Resep diambil dari sisi produk: beberapa varian harga boleh
            // berbagi satu resep. Resep nonaktif dianggap tidak ada.
            $recipeByProduct = Product::query()
                ->whereIn('id', $items->pluck('product_id')->filter()->unique())
                ->whereNotNull('recipe_id')
                ->with('recipe')
                ->get()
                ->filter(fn (Product $product) => $product->recipe?->is_active)
                ->mapWithKeys(fn (Product $product) => [$product->id => $product->recipe]);

            $existing = $order->lines()->where('source', ProductionOrderLine::SOURCE_PO)->whereNotNull('purchase_order_item_id')->get()->keyBy('purchase_order_item_id');
            $sort = (int) $order->lines()->max('sort_order');
            $seen = [];

            foreach ($items as $item) {
                $recipe = $item->product_id ? $recipeByProduct->get($item->product_id) : null;
                $label = $item->is_custom ? ($item->custom_name ?: 'Item khusus') : ($item->product?->name ?? 'Produk #'.$item->product_id);

                $attributes = [
                    'kind' => $recipe ? ProductionOrderLine::KIND_MENU : ProductionOrderLine::KIND_MANUAL,
                    'recipe_id' => $recipe?->id,
                    'label' => $label,
                    'qty' => (float) $item->qty,
                    'unit' => $item->unit ?: ($recipe?->yield_unit ?? 'porsi'),
                    'remark' => $item->purchaseOrder?->po_number.($recipe ? '' : ' — belum ada resep'),
                ];

                $line = $existing->get($item->id);

                if ($line) {
                    $line->update($attributes);
                } else {
                    $order->lines()->create($attributes + [
                        'source' => ProductionOrderLine::SOURCE_PO,
                        'purchase_order_item_id' => $item->id,
                        'sort_order' => ++$sort,
                    ]);
                }

                $seen[] = $item->id;
            }

            // Item PO yang sudah tidak ada ikut hilang dari SPK -- termasuk yang
            // purchase_order_item_id-nya sudah menjadi NULL karena item PO-nya
            // dihapus. Baris manual tidak tersentuh.
            $order->lines()
                ->where('source', ProductionOrderLine::SOURCE_PO)
                ->where(fn ($query) => $query
                    ->whereNull('purchase_order_item_id')
                    ->orWhereNotIn('purchase_order_item_id', $seen))
                ->delete();

            $order->update(['updated_by' => $userId]);

            return $order->fresh(['lines']);
        });
    }

    /**
     * Kebutuhan bahan seluruh SPK Produksi, dijumlahkan per bahan.
     *
     * Kuantitasnya dalam satuan harga bahan. Baris yang tidak bisa dihitung
     * -- resep tanpa hasil, satuan tak sepadan, bahan belum tertaut -- masuk
     * daftar masalah, bukan diam-diam dianggap nol.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, total_cost: float, issues: array<int, string>}
     */
    public function requirements(ProductionOrder $order): array
    {
        $accumulator = [];
        $issues = [];

        $lines = $order->relationLoaded('lines') ? $order->lines : $order->lines()->get();

        foreach ($lines as $line) {
            if (! $line->isMenu()) {
                if ($line->kind === ProductionOrderLine::KIND_MENU) {
                    $issues[] = 'Baris "'.$line->displayName().'" belum menunjuk resep; kebutuhannya tidak dihitung.';
                }

                continue;
            }

            $recipe = $line->recipe;

            if (! $recipe) {
                $issues[] = 'Resep untuk "'.$line->displayName().'" sudah tidak ada.';

                continue;
            }

            // Kuantitas baris (mis. "porsi") harus sepadan dengan satuan hasil
            // resep; kalau tidak, resep itu tidak bisa diskalakan.
            $quantity = $this->converter->convertByRegistry((float) $line->qty, $line->unit, $recipe->yield_unit);

            if ($quantity === null) {
                $issues[] = 'Satuan "'.$line->unit.'" pada "'.$line->displayName().'" tidak sepadan dengan satuan hasil resep "'.$recipe->yield_unit.'".';

                continue;
            }

            $result = $this->costService->requirements($recipe, $quantity);

            foreach ($result['issues'] as $issue) {
                $issues[] = $line->displayName().': '.$issue;
            }

            foreach ($result['rows'] as $row) {
                $key = $row['inventory_item_id'];

                if (! isset($accumulator[$key])) {
                    $accumulator[$key] = [
                        'inventory_item_id' => $key,
                        'name' => $row['name'],
                        'unit' => $row['unit'],
                        'qty' => 0.0,
                        'unit_price' => $row['unit_price'],
                        'total_cost' => 0.0,
                        'menus' => [],
                    ];
                }

                $accumulator[$key]['qty'] = round($accumulator[$key]['qty'] + (float) $row['qty'], 4);
                $accumulator[$key]['total_cost'] = round($accumulator[$key]['qty'] * (float) ($row['unit_price'] ?? 0), 2);
                $accumulator[$key]['menus'][$line->displayName()] = true;
            }
        }

        $rows = collect($accumulator)
            ->map(function (array $row) {
                $row['menus'] = array_keys($row['menus']);

                return $row;
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return [
            'rows' => $rows,
            'total_cost' => round((float) $rows->sum('total_cost'), 2),
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /**
     * Salin template kerja tiap menu ke lembar kerja SPK Produksi.
     *
     * Hanya menu yang belum punya baris tugas yang disalin, supaya penyalinan
     * ulang tidak menggandakan pekerjaan yang sudah disunting dapur.
     *
     * @return int Jumlah baris tugas yang dibuat.
     */
    public function copyTasksFromRecipes(ProductionOrder $order): int
    {
        $created = 0;
        $sort = (int) $order->tasks()->max('sort_order');
        $alreadyCovered = $order->tasks()->whereNotNull('recipe_id')->pluck('recipe_id')->unique()->all();

        foreach ($order->lines()->with('recipe.tasks')->get() as $line) {
            if (! $line->isMenu() || ! $line->recipe || in_array($line->recipe_id, $alreadyCovered, true)) {
                continue;
            }

            foreach ($line->recipe->tasks as $template) {
                ProductionTask::query()->create([
                    'production_order_id' => $order->id,
                    'sort_order' => ++$sort,
                    'recipe_id' => $line->recipe_id,
                    'menu_label' => $line->displayName(),
                    'worker_name' => $template->pic,
                    'task' => $template->task,
                    'object' => $template->object,
                    'quantity_text' => $template->quantity_text,
                ]);

                $created++;
            }

            $alreadyCovered[] = $line->recipe_id;
        }

        return $created;
    }
}
