<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ModuleSettings;
use App\Filament\Resources\ProductionOrderResource;
use App\Filament\Resources\RequisitionResource;
use App\Models\InventoryItem;
use App\Models\ProductionOrder;
use App\Models\Requisition;
use App\Services\InventoryStockAlertService;
use App\Support\Settings\Settings;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ringkasan Inventory: apa yang perlu ditangani hari ini (stok, SPK, form).
 * Angka resep & HPP ada di dashboard aplikasi Menu (MenuOverviewWidget). Setiap angka
 * menautkan ke halaman tempat menanganinya; angka yang butuh hitungan
 * berat (pasangan konversi) di-cache sebentar.
 */
class InventoryOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('inventory.view') ?? false;
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $stats = [];

        $stokMinimum = app(InventoryStockAlertService::class)->alertCount();
        $stats[] = Stat::make('Bahan di bawah stok minimum', $stokMinimum)
            ->description($stokMinimum > 0 ? 'Rencanakan pembelian' : 'Semua bahan terpantau aman')
            ->descriptionIcon($stokMinimum > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
            ->color($stokMinimum > 0 ? 'danger' : 'success');

        if ($user?->can('production.view')) {
            $spkTerbuka = ProductionOrder::query()->open()->count();
            $formDraft = Requisition::query()->where('status', Requisition::STATUS_DRAFT)->count();

            $stats[] = Stat::make('SPK Produksi terbuka', $spkTerbuka)
                ->description($formDraft.' form kebutuhan masih disusun produksi')
                ->descriptionIcon('heroicon-m-fire')
                ->color('gray')
                ->url(ProductionOrderResource::getUrl('index'));
        }

        // Antrean per meja: supervisor gudang melihat yang menunggu persetujuan,
        // gudang melihat yang menunggu penerimaan barang.
        if ($user?->can('requisition.approve')) {
            $menungguSetuju = Requisition::query()->where('status', Requisition::STATUS_SUBMITTED)->count();

            $stats[] = Stat::make('Form menunggu persetujuan', $menungguSetuju)
                ->description($menungguSetuju > 0 ? 'Diajukan produksi; setujui atau tolak' : 'Tidak ada antrean')
                ->descriptionIcon('heroicon-m-hand-thumb-up')
                ->color($menungguSetuju > 0 ? 'warning' : 'success')
                ->url(RequisitionResource::getUrl('index', ['tableFilters' => ['status' => ['value' => Requisition::STATUS_SUBMITTED]]]));
        }

        if ($user?->can('requisition.check')) {
            $menungguTerima = Requisition::query()->where('status', Requisition::STATUS_APPROVED)->count();

            $stats[] = Stat::make('Form menunggu penerimaan barang', $menungguTerima)
                ->description($menungguTerima > 0 ? 'Disetujui; catat diterima/ditolak & harga beli' : 'Tidak ada antrean')
                ->descriptionIcon('heroicon-m-truck')
                ->color($menungguTerima > 0 ? 'warning' : 'success')
                ->url(RequisitionResource::getUrl('index', ['tableFilters' => ['status' => ['value' => Requisition::STATUS_APPROVED]]]));
        }

        $sumber = app(Settings::class)->get('hpp.usage_source') === 'resep' ? 'Resep × produksi (kartu stok)' : 'Residual opname';
        $stats[] = Stat::make('Sumber HPP aktif', $sumber)
            ->description(InventoryItem::query()->ingredients()->where('is_active', true)->count().' bahan aktif')
            ->descriptionIcon('heroicon-m-cube')
            ->color('gray')
            ->url($user?->can('settings.manage') ? ModuleSettings::getUrl() : null);

        return $stats;
    }
}
