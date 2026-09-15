<?php

namespace App\Filament\Pages;

use App\Filament\Resources\InventoryItemResource;
use App\Services\InventoryStockAlertService;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Dashboard aplikasi Inventory: ringkasan yang perlu ditangani (stok
 * minimum, SPK produksi terbuka, resep belum tertaut, konversi), SPK
 * produksi terdekat, dan perubahan harga terakhir. Alert stok minimum juga
 * disampaikan sebagai notifikasi sekali per hari per sesi.
 */
class Dashboard extends BaseDashboard
{
    protected const ALERT_SESSION_KEY = 'inventory_stock_alert_notified_on';

    protected static ?string $navigationIcon = 'heroicon-o-home';

    // Akar /inventory dialihkan ke dashboard peran (Blade); dashboard
    // aplikasi Inventory sendiri ada di /inventory/dashboard.
    protected static string $routePath = 'dashboard';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Dashboard Inventory';

    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\InventoryOverviewWidget::class,
            \App\Filament\Widgets\LowStockAlertWidget::class,
            \App\Filament\Widgets\UpcomingProductionWidget::class,
            \App\Filament\Widgets\RecentPriceChangesWidget::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return 1;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('inventory.view') ?? false;
    }

    public function mount(): void
    {
        $this->notifyLowStock();
    }

    protected function notifyLowStock(): void
    {
        $user = auth()->user();

        if (! $user?->can('inventory.view')) {
            return;
        }

        $today = now()->toDateString();

        if (session(self::ALERT_SESSION_KEY) === $today) {
            return;
        }

        $count = app(InventoryStockAlertService::class)->alertCount();

        if ($count === 0) {
            return;
        }

        session([self::ALERT_SESSION_KEY => $today]);

        Notification::make()
            ->warning()
            ->title("{$count} bahan di bawah stok minimum")
            ->body('Nilai stok berjalan sudah di bawah ambang yang ditetapkan. Rencanakan pembelian.')
            ->persistent()
            ->actions([
                Action::make('lihat')
                    ->label('Lihat daftar')
                    ->url(InventoryItemResource::getUrl('index', ['tableFilters' => ['below_minimum' => ['isActive' => true]]])),
            ])
            ->send();
    }
}
