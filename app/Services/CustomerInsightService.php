<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\PurchaseOrder;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Wawasan per customer untuk Admin (mini CRM): seberapa sering memesan,
 * berapa nilainya, kapan terakhir, dan menu apa yang paling sering dipesan.
 *
 * Dasar angkanya sama dengan laporan penjualan: PO berstatus "completed",
 * nilai = total_amount, tanggal = delivery_date (hari pesanan dikirim).
 * PO draft/batal tidak dihitung -- itu belum (atau tidak jadi) penjualan.
 */
class CustomerInsightService
{
    /** Hari tanpa order sebelum customer dianggap "lama tidak order". */
    public const DORMANT_DAYS = 30;

    /** Rentang "baru-baru ini" untuk omzet & jumlah PO di daftar customer. */
    public const RECENT_DAYS = 90;

    public const STATUS_NEW = 'baru';

    public const STATUS_ACTIVE = 'aktif';

    public const STATUS_DORMANT = 'lama';

    public const STATUS_NEVER = 'belum';

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_NEW => 'Customer baru',
            self::STATUS_ACTIVE => 'Aktif',
            self::STATUS_DORMANT => 'Lama tidak order',
            self::STATUS_NEVER => 'Belum pernah order',
        ];
    }

    /**
     * Kolom agregat untuk daftar customer (satu query, bukan per baris).
     */
    public function withListAggregates(Builder $query, ?CarbonImmutable $today = null): Builder
    {
        $today ??= CarbonImmutable::today();
        $recentFrom = $today->subDays(self::RECENT_DAYS - 1)->toDateString();
        $completed = fn ($q) => $q->where('status', 'completed');

        return $query
            ->withMax(['purchaseOrders as last_order_date' => $completed], 'delivery_date')
            ->withMin(['purchaseOrders as first_order_date' => $completed], 'delivery_date')
            ->withCount(['purchaseOrders as recent_po_count' => fn ($q) => $completed($q)->whereDate('delivery_date', '>=', $recentFrom)])
            ->withSum(['purchaseOrders as recent_revenue' => fn ($q) => $completed($q)->whereDate('delivery_date', '>=', $recentFrom)], 'total_amount');
    }

    /**
     * Saring daftar menurut status (lihat status()). Dihitung di database
     * supaya paginasi tetap benar.
     */
    public function filterByStatus(Builder $query, string $status, ?CarbonImmutable $today = null): Builder
    {
        $today ??= CarbonImmutable::today();
        $cutoff = $today->subDays(self::DORMANT_DAYS)->toDateString();
        $completed = fn ($q) => $q->where('status', 'completed');

        return match ($status) {
            self::STATUS_NEVER => $query->whereDoesntHave('purchaseOrders', $completed),
            self::STATUS_DORMANT => $query->whereHas('purchaseOrders', $completed)
                ->whereDoesntHave('purchaseOrders', fn ($q) => $completed($q)->whereDate('delivery_date', '>', $cutoff)),
            self::STATUS_NEW => $query->whereHas('purchaseOrders', $completed)
                ->whereDoesntHave('purchaseOrders', fn ($q) => $completed($q)->whereDate('delivery_date', '<=', $cutoff)),
            self::STATUS_ACTIVE => $query->whereHas('purchaseOrders', fn ($q) => $completed($q)->whereDate('delivery_date', '>', $cutoff))
                ->whereHas('purchaseOrders', fn ($q) => $completed($q)->whereDate('delivery_date', '<=', $cutoff)),
            default => $query,
        };
    }

    /**
     * Status dari tanggal order pertama & terakhir:
     * belum pernah -> baru (order pertama <= 30 hari) -> aktif -> lama (> 30 hari tanpa order).
     */
    public function status(?string $firstOrder, ?string $lastOrder, ?CarbonImmutable $today = null): string
    {
        $today ??= CarbonImmutable::today();

        if ($lastOrder === null) {
            return self::STATUS_NEVER;
        }

        if (Carbon::parse($lastOrder)->lt($today->subDays(self::DORMANT_DAYS))) {
            return self::STATUS_DORMANT;
        }

        return Carbon::parse($firstOrder)->gte($today->subDays(self::DORMANT_DAYS)) ? self::STATUS_NEW : self::STATUS_ACTIVE;
    }

    /**
     * Ringkasan profil satu customer.
     *
     * @return array<string, mixed>
     */
    public function profile(Customer $customer, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $orders = PurchaseOrder::query()->where('customer_id', $customer->id)->where('status', 'completed');

        $totals = (clone $orders)
            ->selectRaw('COUNT(*) as po_count, COALESCE(SUM(total_amount), 0) as revenue, MIN(delivery_date) as first_order, MAX(delivery_date) as last_order')
            ->first();

        $recentFrom = $today->subDays(self::RECENT_DAYS - 1)->toDateString();
        $recent = (clone $orders)->whereDate('delivery_date', '>=', $recentFrom)
            ->selectRaw('COUNT(*) as po_count, COALESCE(SUM(total_amount), 0) as revenue')
            ->first();

        $poCount = (int) $totals->po_count;
        $first = $totals->first_order ? CarbonImmutable::parse($totals->first_order) : null;
        $last = $totals->last_order ? CarbonImmutable::parse($totals->last_order) : null;

        // Jarak rata-rata antar order = rentang order pertama..terakhir dibagi
        // jumlah jeda (PO - 1). Satu order saja belum punya pola.
        $avgInterval = $poCount > 1 && $first && $last ? round($first->diffInDays($last) / ($poCount - 1), 1) : null;

        return [
            'po_count' => $poCount,
            'revenue' => round((float) $totals->revenue, 2),
            'avg_po_value' => $poCount > 0 ? round((float) $totals->revenue / $poCount, 2) : 0.0,
            'first_order' => $first,
            'last_order' => $last,
            'days_since_last' => $last ? (int) $last->diffInDays($today) : null,
            'avg_interval_days' => $avgInterval,
            'recent_po_count' => (int) $recent->po_count,
            'recent_revenue' => round((float) $recent->revenue, 2),
            'status' => $this->status($totals->first_order, $totals->last_order, $today),
        ];
    }

    /**
     * Omzet per bulan untuk N bulan terakhir (termasuk bulan berjalan),
     * bulan tanpa order tetap muncul dengan nilai 0.
     *
     * @return list<array{month: string, label: string, revenue: float, po_count: int}>
     */
    public function monthlyRevenue(Customer $customer, int $months = 12, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $start = $today->startOfMonth()->subMonths($months - 1);

        $rows = PurchaseOrder::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->whereDate('delivery_date', '>=', $start->toDateString())
            ->whereDate('delivery_date', '<=', $today->toDateString())
            ->get(['delivery_date', 'total_amount'])
            ->groupBy(fn (PurchaseOrder $po) => $po->delivery_date->format('Y-m'));

        $result = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $start->addMonths($i);
            $group = $rows->get($month->format('Y-m'), collect());
            $result[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->translatedFormat('M y'),
                'revenue' => round((float) $group->sum('total_amount'), 2),
                'po_count' => $group->count(),
            ];
        }

        return $result;
    }

    /**
     * Menu yang paling sering dipesan (porsi terbanyak) dari PO completed.
     *
     * @return Collection<int, object{name: string, qty: float, revenue: float, orders: int}>
     */
    public function favoriteMenus(Customer $customer, int $limit = 5): Collection
    {
        return DB::table('purchase_order_items as i')
            ->join('purchase_orders as po', 'po.id', '=', 'i.purchase_order_id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->where('po.customer_id', $customer->id)
            ->where('po.status', 'completed')
            ->selectRaw("COALESCE(NULLIF(i.custom_name, ''), p.name, 'Item tanpa nama') as name")
            ->selectRaw('SUM(i.qty) as qty, SUM(i.subtotal) as revenue, COUNT(DISTINCT po.id) as orders')
            ->groupByRaw("COALESCE(NULLIF(i.custom_name, ''), p.name, 'Item tanpa nama')")
            ->orderByDesc('qty')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (object) ['name' => $row->name, 'qty' => (float) $row->qty, 'revenue' => (float) $row->revenue, 'orders' => (int) $row->orders]);
    }
}
