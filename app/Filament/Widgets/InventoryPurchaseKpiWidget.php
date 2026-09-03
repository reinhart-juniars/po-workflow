<?php

namespace App\Filament\Widgets;

use App\Models\InventoryItem;
use App\Models\InventoryPurchase;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

/**
 * Ringkasan nilai pembelian bulan berjalan per kategori item, sepadan dengan
 * KPI pada modul Blade yang digantikan.
 */
class InventoryPurchaseKpiWidget extends BaseWidget
{
    protected static ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $from = now()->startOfMonth();
        $to = now()->endOfMonth();

        $totals = $this->totalsByCategory($from, $to);
        $damaged = round((float) InventoryPurchase::query()
            ->damaged()
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->sum('total_value'), 2);

        $periode = $from->translatedFormat('F Y');

        $stats = [
            Stat::make('Bahan Baku', $this->rupiah($totals[InventoryItem::CATEGORY_RAW_MATERIAL] ?? 0))
                ->description($periode)
                ->color('success'),

            Stat::make('Kemasan', $this->rupiah($totals[InventoryItem::CATEGORY_PACKAGING] ?? 0))
                ->description($periode),

            Stat::make('Inventaris', $this->rupiah($totals[InventoryItem::CATEGORY_FIXED_ASSET] ?? 0))
                ->description($periode),
        ];

        // Kartu barang rusak hanya muncul bila memang ada, supaya tidak menjadi
        // angka nol permanen yang lama-lama diabaikan.
        if ($damaged > 0) {
            $stats[] = Stat::make('Barang Rusak', $this->rupiah($damaged))
                ->description('Tidak menambah stok')
                ->color('danger');
        }

        return $stats;
    }

    /** @return Collection<string, float> */
    protected function totalsByCategory(\Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to): Collection
    {
        return InventoryPurchase::query()
            ->join('inventory_items', 'inventory_items.id', '=', 'inventory_purchases.inventory_item_id')
            ->whereBetween('inventory_purchases.transaction_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('inventory_items.category')
            ->selectRaw('inventory_items.category as category, SUM(inventory_purchases.total_value) as total')
            ->pluck('total', 'category')
            ->map(fn ($value) => (float) $value);
    }

    protected function rupiah(float $value): string
    {
        return 'Rp '.number_format($value, 0, ',', '.');
    }
}
