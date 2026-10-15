<?php

namespace App\Services;

use App\Models\Area;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderLine;
use App\Models\PurchaseOrderItem;
use App\Models\ReadyStockClosing;
use App\Models\Recipe;
use App\Models\SalesActual;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stok siap dari Rencana Masak (revisi v3.1).
 *
 * Inventory menyusun rencana masak per tanggal produksi; admin mendistribusikan
 * porsinya ke item PO yang tanggal kirimnya sama. Stok dihitung per RESEP,
 * bukan per produk, karena beberapa varian harga (NASI AYAM 12K / 18K) boleh
 * berbagi satu resep -- keduanya mengambil dari masakan yang sama.
 *
 *     sisa(tanggal, resep) = disiapkan - terdistribusi
 *     disiapkan            = qty baris menu rencana masak (tidak dibatalkan)
 *     terdistribusi        = qty item PO from_ready_stock (PO tidak dibatalkan)
 *
 * Tidak ada tautan id item PO -> baris rencana: item PO dihapus dan dibuat
 * ulang setiap PO diubah, jadi pencocokan lewat tanggal + resep yang stabil.
 */
class ReadyStockService
{
    public function __construct(private readonly RecipeCostService $costs) {}

    /** @return Collection<int, float> recipe_id => qty disiapkan */
    public function planned(CarbonInterface $date): Collection
    {
        return ProductionOrderLine::query()
            ->where('kind', ProductionOrderLine::KIND_MENU)
            ->whereNotNull('recipe_id')
            ->whereHas('productionOrder', fn ($query) => $query->readyStock()
                ->whereDate('production_date', $date->toDateString())
                ->where('status', '!=', ProductionOrder::STATUS_CANCELLED))
            ->selectRaw('recipe_id, SUM(qty) as qty')
            ->groupBy('recipe_id')
            ->pluck('qty', 'recipe_id')
            ->map(fn ($qty) => (float) $qty);
    }

    /** @return Collection<int, float> recipe_id => qty sudah didistribusikan ke PO */
    public function distributed(CarbonInterface $date, ?int $exceptPurchaseOrderId = null): Collection
    {
        return PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->join('products', 'products.id', '=', 'purchase_order_items.product_id')
            ->where('purchase_order_items.from_ready_stock', true)
            ->whereNotNull('products.recipe_id')
            ->whereDate('purchase_orders.delivery_date', $date->toDateString())
            ->where('purchase_orders.status', '!=', 'cancelled')
            ->when($exceptPurchaseOrderId, fn ($query) => $query->where('purchase_orders.id', '!=', $exceptPurchaseOrderId))
            ->selectRaw('products.recipe_id as recipe_id, SUM(purchase_order_items.qty) as qty')
            ->groupBy('products.recipe_id')
            ->pluck('qty', 'recipe_id')
            ->map(fn ($qty) => (float) $qty);
    }

    /**
     * Sisa stok siap per PRODUK untuk form PO (dipakai JS untuk petunjuk).
     *
     * @return array<int, float> product_id => sisa porsi
     */
    public function remainingByProduct(CarbonInterface $date, ?int $exceptPurchaseOrderId = null): array
    {
        $remaining = $this->remaining($date, $exceptPurchaseOrderId);

        if ($remaining->isEmpty()) {
            return [];
        }

        return Product::query()
            ->whereIn('recipe_id', $remaining->keys())
            ->pluck('recipe_id', 'id')
            ->map(fn ($recipeId) => $remaining[$recipeId])
            ->all();
    }

    /** @return Collection<int, float> recipe_id => sisa */
    public function remaining(CarbonInterface $date, ?int $exceptPurchaseOrderId = null): Collection
    {
        $distributed = $this->distributed($date, $exceptPurchaseOrderId);

        return $this->planned($date)->map(fn (float $qty, int $recipeId) => round($qty - (float) ($distributed[$recipeId] ?? 0), 2));
    }

    /**
     * Tolak item "ambil dari stok siap" yang melebihi sisa rencana masak.
     *
     * Dipanggil di dalam transaksi simpan PO; rencana masak tanggal itu dikunci
     * dulu supaya dua admin yang mendistribusikan bersamaan tidak sama-sama
     * mengambil porsi terakhir.
     *
     * @param  list<array{product_id: int|string, qty: int|float|string, from_ready_stock?: mixed}>  $rows
     */
    public function assertAvailable(CarbonInterface|string $date, array $rows, ?int $exceptPurchaseOrderId = null): void
    {
        $date = $date instanceof CarbonInterface ? $date : Carbon::parse($date);
        $wanted = collect($rows)
            ->filter(fn (array $row) => filter_var($row['from_ready_stock'] ?? false, FILTER_VALIDATE_BOOLEAN))
            ->map(fn (array $row, int $index) => ['index' => $index, 'product_id' => (int) $row['product_id'], 'qty' => (float) $row['qty']]);

        if ($wanted->isEmpty()) {
            return;
        }

        ProductionOrder::query()->readyStock()->whereDate('production_date', $date->toDateString())->lockForUpdate()->get(['id']);

        $products = Product::query()->whereIn('id', $wanted->pluck('product_id'))->get(['id', 'name', 'recipe_id'])->keyBy('id');
        $remaining = $this->remaining($date, $exceptPurchaseOrderId);

        // Sudah ditutup: sisanya sudah jadi Barang Sisa. PO lama tetap boleh diubah
        // selama tidak mengambil lebih banyak dari porsi yang sudah dipegangnya.
        $closed = $this->closingFor($date) !== null;
        if ($closed) {
            $others = $this->distributed($date, $exceptPurchaseOrderId);
            $remaining = $this->distributed($date)->map(fn (float $qty, int $recipeId) => round($qty - (float) ($others[$recipeId] ?? 0), 2));
        }
        $errors = [];

        foreach ($wanted->groupBy(fn (array $row) => $products->get($row['product_id'])?->recipe_id ?? 'tanpa-resep') as $recipeId => $group) {
            $first = $group->first();
            $product = $products->get($first['product_id']);

            if ($recipeId === 'tanpa-resep') {
                $errors["items.{$first['index']}.from_ready_stock"] = ($product?->name ?? 'Menu ini').' belum punya resep, jadi tidak bisa diambil dari stok siap.';

                continue;
            }

            $available = (float) ($remaining[$recipeId] ?? 0);
            $asked = (float) $group->sum('qty');

            if ($asked > $available + 0.00001) {
                $recipe = Recipe::query()->whereKey($recipeId)->value('name');
                $errors["items.{$first['index']}.from_ready_stock"] = $closed
                    ? sprintf('Stok siap tanggal %s sudah ditutup; sisa %s sudah menjadi Barang Sisa. Jual dari halaman Barang Sisa, atau hapus centang "Ambil dari stok siap".', $date->translatedFormat('d M Y'), $recipe ?: $product?->name)
                    : sprintf(
                        'Stok siap %s tanggal %s tinggal %s porsi, diminta %s. Kurangi qty atau hapus centang "Ambil dari stok siap".',
                        $recipe ?: $product?->name,
                        $date->translatedFormat('d M Y'),
                        $this->number($available),
                        $this->number($asked),
                    );
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Ringkasan KPI per tanggal & menu dalam rentang.
     *
     * @return Collection<int, array{date: string, recipe_id: int, recipe: string, planned: float, distributed: float, remaining: float}>
     */
    public function summary(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $rows = collect();

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day = $day->copy()->addDay()) {
            $planned = $this->planned($day);

            if ($planned->isEmpty()) {
                continue;
            }

            $distributed = $this->distributed($day);
            $names = Recipe::query()->whereIn('id', $planned->keys())->pluck('name', 'id');

            foreach ($planned as $recipeId => $qty) {
                $taken = (float) ($distributed[$recipeId] ?? 0);
                $rows->push([
                    'date' => $day->toDateString(),
                    'recipe_id' => (int) $recipeId,
                    'recipe' => (string) ($names[$recipeId] ?? 'Resep #'.$recipeId),
                    'planned' => $qty,
                    'distributed' => $taken,
                    'remaining' => round($qty - $taken, 2),
                ]);
            }
        }

        return $rows->sortBy([['date', 'asc'], ['recipe', 'asc']])->values();
    }

    public function closingFor(CarbonInterface $date): ?ReadyStockClosing
    {
        return ReadyStockClosing::query()->whereDate('production_date', $date->toDateString())->first();
    }

    /**
     * Tutup stok siap satu tanggal: porsi yang tidak terdistribusi menjadi Barang Sisa.
     *
     * Syarat: semua Rencana Masak tanggal itu sudah ditutup (pemakaian bahannya
     * sudah masuk kartu stok & HPP). Tanpa syarat ini sisa akan tercatat sebagai
     * persediaan -- mengurangi HPP -- padahal bahannya belum pernah dibebankan.
     *
     * Sisa dicatat sebagai Sales Actual internal yang sudah disubmit, qty actual
     * 0 dan retur = sisa, dinilai HPP resep per porsi. Mesin Barang Sisa
     * (LeftoverStockService) membacanya seperti retur biasa.
     */
    public function closeDay(CarbonInterface $date, User $user): ReadyStockClosing
    {
        $date = Carbon::parse($date->toDateString());

        return DB::transaction(function () use ($date, $user) {
            $plans = ProductionOrder::query()->readyStock()
                ->whereDate('production_date', $date->toDateString())
                ->where('status', '!=', ProductionOrder::STATUS_CANCELLED)
                ->lockForUpdate()
                ->get();

            if ($this->closingFor($date)) {
                throw ValidationException::withMessages(['date' => 'Stok siap tanggal '.$date->translatedFormat('d M Y').' sudah ditutup.']);
            }

            if ($plans->isEmpty()) {
                throw ValidationException::withMessages(['date' => 'Tidak ada Rencana Masak tanggal '.$date->translatedFormat('d M Y').'.']);
            }

            if ($date->isAfter(now()->startOfDay())) {
                throw ValidationException::withMessages(['date' => 'Stok siap baru bisa ditutup pada hari produksinya atau sesudahnya.']);
            }

            $open = $plans->reject(fn (ProductionOrder $plan) => $plan->isCompleted());
            if ($open->isNotEmpty()) {
                throw ValidationException::withMessages(['date' => 'Tutup dulu SPK Rencana Masak '.$open->pluck('number')->implode(', ').' supaya pemakaian bahannya tercatat, baru stok siapnya ditutup.']);
            }

            $leftover = $this->remaining($date)->filter(fn (float $qty) => $qty > 0.00001);
            $salesActual = $leftover->isEmpty() ? null : $this->recordLeftover($date, $leftover, $user);

            $closing = ReadyStockClosing::query()->create([
                'production_date' => $date->toDateString(),
                'sales_actual_id' => $salesActual?->id,
                'leftover_qty' => round((float) $leftover->sum(), 2),
                'leftover_value' => round((float) ($salesActual?->items->sum(fn ($item) => (float) $item->qty_return * (float) $item->raw_material_cost) ?? 0), 2),
                'closed_by' => $user->id,
            ]);

            AuditLog::create([
                'user_id' => $user->id,
                'entity' => 'ready_stock_closing',
                'entity_id' => $closing->id,
                'action' => 'ready_stock_closed',
                'message' => sprintf('Stok siap %s ditutup: sisa %s porsi menjadi Barang Sisa (Rp %s).',
                    $date->translatedFormat('d M Y'),
                    $this->number((float) $closing->leftover_qty),
                    number_format((float) $closing->leftover_value, 0, ',', '.')),
            ]);

            return $closing;
        });
    }

    /** @param  Collection<int, float>  $leftover  recipe_id => sisa porsi */
    private function recordLeftover(Carbon $date, Collection $leftover, User $user): SalesActual
    {
        $customer = Customer::query()->firstOrCreate(
            ['name' => Customer::INTERNAL_READY_STOCK, 'is_internal' => true],
            ['active' => false, 'is_lapak' => false, 'area_id' => Area::query()->value('id')],
        );

        $salesActual = SalesActual::query()->create([
            'customer_id' => $customer->id,
            'sales_date' => $date->toDateString(),
            'status' => 'submitted',
            'is_internal' => true,
            // Masuk persediaan Barang Sisa di akhir hari produksi (atau sekarang
            // bila ditutup pada hari yang sama).
            'submitted_at' => min($date->copy()->endOfDay(), now()),
            'submitted_by' => $user->id,
            'notes' => 'Sisa Rencana Masak '.$date->translatedFormat('d M Y').' yang tidak terdistribusi ke PO.',
        ]);

        $recipes = Recipe::query()->whereIn('id', $leftover->keys())->get()->keyBy('id');
        $distributedProducts = PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_order_items.from_ready_stock', true)
            ->whereDate('purchase_orders.delivery_date', $date->toDateString())
            ->selectRaw('purchase_order_items.product_id, SUM(purchase_order_items.qty) as qty')
            ->groupBy('purchase_order_items.product_id')
            ->pluck('qty', 'product_id');

        foreach ($leftover as $recipeId => $qty) {
            $recipe = $recipes->get($recipeId);
            // Produk wakil: varian yang paling banyak didistribusikan hari itu,
            // selain itu varian aktif termurah. Dipakai untuk harga jual saran.
            $product = Product::query()->where('recipe_id', $recipeId)->get()
                ->sortBy([
                    fn (Product $a, Product $b) => (float) ($distributedProducts[$b->id] ?? 0) <=> (float) ($distributedProducts[$a->id] ?? 0),
                    fn (Product $a, Product $b) => [! $a->active, (float) $a->base_price] <=> [! $b->active, (float) $b->base_price],
                ])
                ->first();
            $unitCost = $recipe ? round((float) $this->costs->tree($recipe, 1)['total_cost'], 2) : (float) ($product?->raw_material_cost ?? 0);

            $salesActual->items()->create([
                'product_id' => $product?->id,
                'item_name' => $recipe?->name ?? $product?->name ?? 'Resep #'.$recipeId,
                'unit' => $recipe?->yield_unit ?: 'porsi',
                'qty_delivery' => $qty,
                'qty_actual' => 0,
                'qty_return' => $qty,
                'unit_price' => (float) ($product?->base_price ?? 0),
                'raw_material_cost' => $unitCost,
                'overhead_cost' => 0,
                'subtotal_actual' => 0,
                'notes' => 'Sisa stok siap Rencana Masak',
            ]);
        }

        return $salesActual->load('items');
    }

    /**
     * Pesanan PO per tanggal kirim & menu, supaya gudang bisa menyusun rencana
     * masak dari pesanan yang sudah masuk.
     *
     * @return Collection<int, array{date: string, product: string, qty: float, from_ready_stock: float, po_count: int}>
     */
    public function orderDemand(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->leftJoin('products', 'products.id', '=', 'purchase_order_items.product_id')
            ->whereBetween('purchase_orders.delivery_date', [$from->toDateString(), $to->toDateString()])
            ->where('purchase_orders.status', '!=', 'cancelled')
            ->selectRaw('DATE(purchase_orders.delivery_date) as date')
            ->selectRaw("COALESCE(products.name, purchase_order_items.custom_name, 'Item khusus') as product")
            ->selectRaw('SUM(purchase_order_items.qty) as qty')
            ->selectRaw('SUM(CASE WHEN purchase_order_items.from_ready_stock = 1 THEN purchase_order_items.qty ELSE 0 END) as from_ready_stock')
            ->selectRaw('COUNT(DISTINCT purchase_orders.id) as po_count')
            ->groupByRaw("DATE(purchase_orders.delivery_date), COALESCE(products.name, purchase_order_items.custom_name, 'Item khusus')")
            ->orderBy('date')
            ->orderBy('product')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'product' => (string) $row->product,
                'qty' => (float) $row->qty,
                'from_ready_stock' => (float) $row->from_ready_stock,
                'po_count' => (int) $row->po_count,
            ]);
    }

    /**
     * Rencana belanja: baris Form Kebutuhan dari Rencana Masak dalam rentang,
     * dijumlahkan per bahan. Rencana yang Form Kebutuhan-nya belum disusun
     * dihitung sebagai "belum disusun" supaya tidak terlewat.
     *
     * @return array{items: Collection<int, array{item: string, unit: string, required: float, purchase: float}>, plans: Collection<int, ProductionOrder>}
     */
    public function shoppingPlan(CarbonInterface $from, CarbonInterface $to): array
    {
        $plans = ProductionOrder::query()
            ->readyStock()
            ->whereBetween('production_date', [$from->toDateString(), $to->toDateString()])
            ->where('status', '!=', ProductionOrder::STATUS_CANCELLED)
            ->with(['requisition.lines', 'lines'])
            ->orderBy('production_date')
            ->get();

        $items = $plans
            ->flatMap(fn (ProductionOrder $plan) => $plan->requisition?->lines ?? collect())
            ->groupBy(fn ($line) => mb_strtolower($line->name).'|'.$line->unit)
            ->map(fn (Collection $lines) => [
                'item' => (string) $lines->first()->name,
                'unit' => (string) $lines->first()->unit,
                'required' => round((float) $lines->sum('required_qty'), 4),
                'purchase' => round((float) $lines->sum('purchase_qty'), 4),
            ])
            ->sortBy('item')
            ->values();

        return ['items' => $items, 'plans' => $plans];
    }

    private function number(float $value): string
    {
        return number_format($value, fmod($value, 1.0) === 0.0 ? 0 : 2, ',', '.');
    }
}
