<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ProductionOrder;
use App\Models\RequisitionLine;
use App\Support\Settings\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Susut bahan: seberapa banyak bahan keluar melebihi kebutuhan resep, dan ke
 * mana perginya.
 *
 * Sumbernya kartu stok per bahan (inventory_movements):
 *
 *   Kebutuhan resep = kebutuhan baris Form Kebutuhan SPK yang ditutup
 *   Pemakaian       = gerakan `usage` (aktual dapur bila dicatat, selain itu resep)
 *   Lebih pakai     = Pemakaian - Kebutuhan resep
 *   Barang Hilang   = penyesuaian negatif: hitung fisik lebih sedikit dari saldo
 *   Barang Temuan   = penyesuaian positif: hitung fisik lebih banyak dari saldo
 *
 *   Susut   = Lebih pakai + Barang Hilang - Barang Temuan
 *   Susut % = Susut / Kebutuhan resep
 *
 * Warna mengikuti pengaturan shrinkage.green_max_pct / yellow_max_pct
 * (bawaan: < 1% hijau, 1-10% kuning, > 10% merah).
 *
 * Barang Hilang/Temuan juga dilaporkan sebagai rincian HPP di Laba Rugi.
 * Nilainya sudah termasuk di Bahan Baku Terpakai, jadi rincian ini tidak
 * mengubah Laba -- hanya menunjukkan bagian HPP yang bukan pemakaian produksi.
 */
class InventoryShrinkageService
{
    public const LEVEL_GREEN = 'hijau';

    public const LEVEL_YELLOW = 'kuning';

    public const LEVEL_RED = 'merah';

    public const LEVEL_NONE = 'tanpa_resep';

    public function __construct(
        protected MaterialBreakdownService $breakdown,
    ) {}

    /**
     * Nilai Barang Hilang dan Barang Temuan (keduanya positif) untuk bahan di
     * bawah bucket stok (Bahan Baku, Kemasan) dalam rentang tanggal.
     *
     * @return array{hilang: float, temuan: float}
     */
    public function lossAndFoundTotals(CarbonInterface $from, CarbonInterface $to): array
    {
        $values = InventoryMovement::query()
            ->join('inventory_items as bahan', 'bahan.id', '=', 'inventory_movements.inventory_item_id')
            ->join('inventory_items as bucket', 'bucket.id', '=', 'bahan.parent_id')
            ->whereIn('bucket.category', InventoryItem::stockCategories())
            ->where('inventory_movements.type', InventoryMovement::TYPE_ADJUSTMENT)
            ->whereDate('inventory_movements.moved_at', '>=', $from->toDateString())->whereDate('inventory_movements.moved_at', '<=', $to->toDateString())
            ->pluck('inventory_movements.total_value');

        return [
            'hilang' => round(-(float) $values->filter(fn ($value) => (float) $value < 0)->sum(), 2) + 0.0,
            'temuan' => round((float) $values->filter(fn ($value) => (float) $value > 0)->sum(), 2),
        ];
    }

    /**
     * Susut per bahan dalam rentang, diurutkan dari yang paling parah.
     *
     * Angka hasil round(-x) di kelas ini diberi "+ 0.0" supaya nol tidak
     * tampil sebagai "-0" di layar (nol negatif IEEE).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function report(CarbonInterface $from, CarbonInterface $to): Collection
    {
        [$fromDate, $toDate] = [$from->toDateString(), $to->toDateString()];

        // Kebutuhan resep dari SPK yang ditutup dalam rentang.
        $recipe = RequisitionLine::query()
            ->join('requisitions', 'requisitions.id', '=', 'requisition_lines.requisition_id')
            ->join('production_orders', 'production_orders.id', '=', 'requisitions.production_order_id')
            ->where('production_orders.status', ProductionOrder::STATUS_COMPLETED)
            ->whereBetween('production_orders.production_date', [$fromDate, $toDate])
            ->whereNotNull('requisition_lines.inventory_item_id')
            ->groupBy('requisition_lines.inventory_item_id')
            ->selectRaw('requisition_lines.inventory_item_id as item_id, SUM(requisition_lines.required_qty) as qty')
            ->pluck('qty', 'item_id');

        $movements = InventoryMovement::query()
            ->whereIn('type', [InventoryMovement::TYPE_USAGE, InventoryMovement::TYPE_ADJUSTMENT])
            ->whereDate('moved_at', '>=', $fromDate)->whereDate('moved_at', '<=', $toDate)
            ->get(['inventory_item_id', 'type', 'qty', 'total_value'])
            ->groupBy('inventory_item_id');

        $itemIds = $recipe->keys()->merge($movements->keys())->map(fn ($id) => (int) $id)->unique()->values();

        if ($itemIds->isEmpty()) {
            return collect();
        }

        $items = InventoryItem::query()
            ->with('parent:id,name')
            ->whereIn('id', $itemIds)
            ->get(['id', 'name', 'unit', 'unit_price', 'parent_id'])
            ->keyBy('id');

        return $itemIds
            ->map(function (int $itemId) use ($items, $recipe, $movements) {
                $item = $items->get($itemId);

                if (! $item) {
                    return null;
                }

                $rows = $movements->get($itemId, collect());
                $usage = $rows->where('type', InventoryMovement::TYPE_USAGE);
                $adjustments = $rows->where('type', InventoryMovement::TYPE_ADJUSTMENT);

                $recipeQty = round((float) ($recipe[$itemId] ?? 0), 4);
                $usageQty = round(-(float) $usage->sum('qty'), 4) + 0.0;
                $usageValue = round(-(float) $usage->sum('total_value'), 2) + 0.0;
                $lossQty = round(-(float) $adjustments->filter(fn ($row) => (float) $row->qty < 0)->sum('qty'), 4) + 0.0;
                $lossValue = round(-(float) $adjustments->filter(fn ($row) => (float) $row->qty < 0)->sum('total_value'), 2) + 0.0;
                $foundQty = round((float) $adjustments->filter(fn ($row) => (float) $row->qty > 0)->sum('qty'), 4);
                $foundValue = round((float) $adjustments->filter(fn ($row) => (float) $row->qty > 0)->sum('total_value'), 2);

                $overUsageQty = round($usageQty - $recipeQty, 4);
                // Harga rata-rata pemakaian bila ada, selain itu harga master bahan.
                $price = $usageQty > 0 ? $usageValue / $usageQty : (float) ($item->unit_price ?? 0);
                $overUsageValue = round($overUsageQty * $price, 2);

                $shrinkQty = round($overUsageQty + $lossQty - $foundQty, 4);
                $shrinkValue = round($overUsageValue + $lossValue - $foundValue, 2);
                $shrinkPct = $recipeQty > 0 ? round($shrinkQty / $recipeQty * 100, 2) : null;

                return [
                    'inventory_item_id' => $itemId,
                    'name' => $item->name,
                    'unit' => $item->unit,
                    'bucket' => $item->parent?->name,
                    'recipe_qty' => $recipeQty,
                    'usage_qty' => $usageQty,
                    'over_usage_qty' => $overUsageQty,
                    'loss_qty' => $lossQty,
                    'loss_value' => $lossValue,
                    'found_qty' => $foundQty,
                    'found_value' => $foundValue,
                    'shrink_qty' => $shrinkQty,
                    'shrink_value' => $shrinkValue,
                    'shrink_pct' => $shrinkPct,
                    'level' => $this->level($shrinkPct, $shrinkQty),
                ];
            })
            ->filter()
            ->sortBy([
                fn (array $a, array $b) => $this->severity($b['level']) <=> $this->severity($a['level']),
                fn (array $a, array $b) => ($b['shrink_pct'] ?? INF) <=> ($a['shrink_pct'] ?? INF),
                fn (array $a, array $b) => strnatcasecmp($a['name'], $b['name']),
            ])
            ->values();
    }

    /**
     * Ke mana susut sebuah bahan pergi: per SPK yang memakainya, dengan menu
     * yang memakai bahan itu dan bagian susut yang ditanggung tiap menu
     * (dibagi menurut porsi kebutuhan resepnya -- perkiraan, karena dapur
     * mencatat pemakaian per bahan, bukan per menu).
     *
     * @return array{item: InventoryItem, orders: list<array<string, mixed>>, standalone: list<array<string, mixed>>}
     */
    public function detail(InventoryItem $item, CarbonInterface $from, CarbonInterface $to): array
    {
        [$fromDate, $toDate] = [$from->toDateString(), $to->toDateString()];

        $lines = RequisitionLine::query()
            ->with('requisition.productionOrder')
            ->where('inventory_item_id', $item->id)
            ->whereHas('requisition.productionOrder', fn ($query) => $query
                ->where('status', ProductionOrder::STATUS_COMPLETED)
                ->whereBetween('production_date', [$fromDate, $toDate]))
            ->get();

        $adjustmentsByLine = InventoryMovement::query()
            ->where('inventory_item_id', $item->id)
            ->where('type', InventoryMovement::TYPE_ADJUSTMENT)
            ->whereIn('requisition_line_id', $lines->pluck('id'))
            ->get(['requisition_line_id', 'qty'])
            ->groupBy('requisition_line_id');

        $orders = $lines
            ->map(function (RequisitionLine $line) use ($item, $adjustmentsByLine) {
                $order = $line->requisition->productionOrder;
                $adjustments = $adjustmentsByLine->get($line->id, collect());
                $lossQty = round(-(float) $adjustments->filter(fn ($row) => (float) $row->qty < 0)->sum('qty'), 4) + 0.0;
                $foundQty = round((float) $adjustments->filter(fn ($row) => (float) $row->qty > 0)->sum('qty'), 4);
                $overUsageQty = round($line->usageQty() - (float) $line->required_qty, 4);
                $shrinkQty = round($overUsageQty + $lossQty - $foundQty, 4);

                return [
                    'production_order_id' => $order->id,
                    'number' => $order->number,
                    'production_date' => $order->production_date?->toDateString(),
                    'recipe_qty' => (float) $line->required_qty,
                    'actual_qty' => $line->actual_used_qty === null ? null : (float) $line->actual_used_qty,
                    'over_usage_qty' => $overUsageQty,
                    'loss_qty' => $lossQty,
                    'found_qty' => $foundQty,
                    'shrink_qty' => $shrinkQty,
                    'menus' => $this->menusUsing($order, $item->id, $shrinkQty),
                ];
            })
            ->sortBy('production_date')
            ->values()
            ->all();

        // Penyesuaian dari Opname Bahan (tidak terikat SPK).
        $standalone = InventoryMovement::query()
            ->where('inventory_item_id', $item->id)
            ->where('type', InventoryMovement::TYPE_ADJUSTMENT)
            ->whereNull('requisition_line_id')
            ->whereDate('moved_at', '>=', $fromDate)->whereDate('moved_at', '<=', $toDate)
            ->orderBy('moved_at')
            ->get(['moved_at', 'qty', 'total_value', 'notes'])
            ->map(fn (InventoryMovement $movement) => [
                'date' => $movement->moved_at?->toDateString(),
                'qty' => (float) $movement->qty,
                'value' => (float) $movement->total_value,
                'notes' => $movement->notes,
            ])
            ->all();

        return ['item' => $item, 'orders' => $orders, 'standalone' => $standalone];
    }

    /** Warna indikator untuk persentase susut. */
    public function level(?float $shrinkPct, float $shrinkQty = 0.0): string
    {
        if ($shrinkPct === null) {
            // Ada yang hilang padahal tidak ada kebutuhan resep: tidak bisa
            // dipersenkan, tapi jelas perlu dilacak.
            return $shrinkQty > 0.00005 ? self::LEVEL_RED : self::LEVEL_NONE;
        }

        $settings = app(Settings::class);

        return match (true) {
            $shrinkPct < (float) $settings->get('shrinkage.green_max_pct') => self::LEVEL_GREEN,
            $shrinkPct <= (float) $settings->get('shrinkage.yellow_max_pct') => self::LEVEL_YELLOW,
            default => self::LEVEL_RED,
        };
    }

    /** @return array<string, string> */
    public static function levelLabels(): array
    {
        return [
            self::LEVEL_GREEN => 'Hijau',
            self::LEVEL_YELLOW => 'Kuning',
            self::LEVEL_RED => 'Merah',
            self::LEVEL_NONE => 'Tanpa resep',
        ];
    }

    protected function severity(string $level): int
    {
        return match ($level) {
            self::LEVEL_RED => 3,
            self::LEVEL_YELLOW => 2,
            self::LEVEL_GREEN => 1,
            default => 0,
        };
    }

    /**
     * Menu di SPK yang memakai bahan ini, dengan bagian susutnya.
     *
     * @return list<array{label: string, porsi: float, unit: string, qty: float, share_pct: float, shrink_qty: float}>
     */
    protected function menusUsing(ProductionOrder $order, int $itemId, float $shrinkQty): array
    {
        $menus = collect($this->breakdown->forProductionOrder($order)['menus'])
            ->map(function (array $menu) use ($itemId) {
                $qty = (float) collect($menu['rows'])->where('inventory_item_id', $itemId)->sum('qty');

                return ['label' => $menu['label'], 'porsi' => (float) $menu['qty'], 'unit' => $menu['unit'], 'qty' => round($qty, 4)];
            })
            ->filter(fn (array $menu) => $menu['qty'] > 0)
            ->values();

        $total = (float) $menus->sum('qty');

        return $menus
            ->map(function (array $menu) use ($total, $shrinkQty) {
                $share = $total > 0 ? $menu['qty'] / $total : 0.0;

                return $menu + [
                    'share_pct' => round($share * 100, 1),
                    'shrink_qty' => round($shrinkQty * $share, 4),
                ];
            })
            ->sortByDesc('qty')
            ->values()
            ->all();
    }
}
