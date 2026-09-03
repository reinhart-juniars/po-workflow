<?php

namespace App\Filament\Resources\InventoryPurchaseResource\Pages;

use App\Filament\Resources\InventoryPurchaseResource;
use App\Filament\Resources\InventoryPurchaseResource\Widgets\InventoryPurchaseKpiWidget;
use Filament\Resources\Pages\ListRecords;

class ListInventoryPurchases extends ListRecords
{
    protected static string $resource = InventoryPurchaseResource::class;

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
