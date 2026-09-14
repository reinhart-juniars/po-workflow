<?php

namespace App\Filament\Pages;

use App\Filament\Resources\InventoryItemResource;
use App\Services\InventoryStockAlertService;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Dashboard panel. Selain widget stok minimum, alert-nya juga disampaikan
 * sebagai notifikasi sekali per hari per sesi supaya orang yang langsung
 * menuju halaman lain tetap tahu ada bahan di bawah ambang.
 */
class Dashboard extends BaseDashboard
{
    protected const ALERT_SESSION_KEY = 'inventory_stock_alert_notified_on';

    public static function canAccess(): bool
    {
        // Semua peran yang boleh membuka panel boleh melihat dashboard;
        // isinya (widget) menyaring diri lewat izin masing-masing.
        return auth()->check();
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
