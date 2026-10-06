<?php

namespace App\Filament\Resources\InventoryPurchaseResource\Pages;

use App\Exports\InventoryPurchasesExport;
use App\Filament\Resources\InventoryPurchaseResource;
use App\Filament\Resources\InventoryPurchaseResource\Widgets\InventoryPurchaseKpiWidget;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListInventoryPurchases extends ListRecords
{
    protected static string $resource = InventoryPurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')
                ->label('Export Excel')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(new InventoryPurchasesExport, 'pembelian-bahan-baku-'.now()->format('Ymd_His').'.xlsx')),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            InventoryPurchaseKpiWidget::class,
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Pembelian dicatat lewat menu Pengeluaran agar jurnal kas atau hutangnya ikut terbentuk. '
            .'Halaman ini untuk meninjau, mengoreksi, dan mencatat kondisi barang saat datang.';
    }
}
