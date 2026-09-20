<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderLine;
use App\Support\Settings\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bagian B.3 -- Laporan Menu Tidak Diproduksi.
 *
 * Menu aktif yang tidak pernah muncul di SPK Produksi (baris menu lewat
 * resep, maupun baris manual dari item PO) selama rentang tertentu --
 * bawaan N bulan terakhir dari Pengaturan (report.idle_menu_months). Untuk
 * tiap menu disertakan kapan terakhir diproduksi dan terakhir terjual
 * (sales actual), supaya Owner bisa membedakan "tidak laku" dari "baru
 * dipetakan".
 */
class IdleMenuReportService
{
    public function defaultMonths(): int
    {
        return max(1, (int) app(Settings::class)->get('report.idle_menu_months'));
    }

    /**
     * @return Collection<int, array{id: int, sku: ?string, name: string, unit: ?string, base_price: float, has_recipe: bool, last_produced: ?string, last_sold: ?string}>
     */
    public function rows(Carbon $since, ?Carbon $until = null, bool $activeOnly = true): Collection
    {
        $until ??= now();

        // Tanggal produksi terakhir per produk: baris menu via resep (recipes ->
        // products.recipe_id) atau baris manual via item PO. SPK batal tidak dihitung.
        $viaRecipe = ProductionOrderLine::query()
            ->join('production_orders as po', 'po.id', '=', 'production_order_lines.production_order_id')
            ->join('products as p', 'p.recipe_id', '=', 'production_order_lines.recipe_id')
            ->where('po.status', '!=', ProductionOrder::STATUS_CANCELLED)
            ->whereNotNull('production_order_lines.recipe_id')
            ->groupBy('p.id')
            ->select('p.id as product_id', DB::raw('MAX(po.production_date) as last_date'));

        $viaPoItem = ProductionOrderLine::query()
            ->join('production_orders as po', 'po.id', '=', 'production_order_lines.production_order_id')
            ->join('purchase_order_items as poi', 'poi.id', '=', 'production_order_lines.purchase_order_item_id')
            ->where('po.status', '!=', ProductionOrder::STATUS_CANCELLED)
            ->whereNotNull('production_order_lines.purchase_order_item_id')
            ->groupBy('poi.product_id')
            ->select('poi.product_id', DB::raw('MAX(po.production_date) as last_date'));

        $lastProduced = collect();

        foreach ([$viaRecipe, $viaPoItem] as $q) {
            foreach ($q->get() as $row) {
                $date = substr((string) $row->last_date, 0, 10); // MAX() bisa mengembalikan datetime
                $current = $lastProduced->get($row->product_id);
                $lastProduced->put($row->product_id, $current === null || $date > $current ? $date : $current);
            }
        }

        $lastSold = DB::table('sales_actual_items as sai')
            ->join('sales_actuals as sa', 'sa.id', '=', 'sai.sales_actual_id')
            ->whereNotNull('sai.product_id')
            ->groupBy('sai.product_id')
            ->select('sai.product_id', DB::raw('MAX(sa.sales_date) as last_date'))
            ->pluck('last_date', 'product_id')
            ->map(fn ($d) => substr((string) $d, 0, 10));

        $sinceDate = $since->toDateString();
        $untilDate = $until->toDateString();

        return Product::query()
            ->when($activeOnly, fn ($q) => $q->where('active', true))
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'unit', 'base_price', 'recipe_id'])
            ->filter(function (Product $p) use ($lastProduced, $sinceDate, $untilDate) {
                $last = $lastProduced->get($p->id);

                // "Tidak diproduksi dalam rentang" = tidak ada produksi antara since..until.
                return $last === null || $last < $sinceDate || $last > $untilDate;
            })
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'unit' => $p->unit,
                'base_price' => (float) $p->base_price,
                'has_recipe' => $p->recipe_id !== null,
                'last_produced' => $lastProduced->get($p->id),
                'last_sold' => $lastSold->get($p->id),
            ])
            ->values();
    }

    /** Jumlah untuk kartu dashboard, memakai rentang bawaan. */
    public function count(): int
    {
        return $this->rows(now()->subMonths($this->defaultMonths()))->count();
    }
}
