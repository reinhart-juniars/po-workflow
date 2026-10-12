<?php

namespace App\Filament\Resources\PurchaseBillResource\Pages;

use App\Filament\Resources\PurchaseBillResource;
use App\Models\PurchaseBill;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseBills extends ListRecords
{
    protected static string $resource = PurchaseBillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Belanja Lepas')
                ->icon('heroicon-m-plus'),
        ];
    }

    /**
     * Tab per meja: yang masih di gudang, yang menunggu accounting, dan yang
     * sudah selesai. Bawaan = di gudang, karena itulah daftar kerja staf.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $count = fn (array $statuses) => PurchaseBill::query()->whereIn('status', $statuses)->count();
        $gudang = [PurchaseBill::STATUS_DRAFT, PurchaseBill::STATUS_RETURNED];
        $accounting = [PurchaseBill::STATUS_SUBMITTED, PurchaseBill::STATUS_CREDIT];

        return [
            'gudang' => Tab::make('Belum diajukan')
                ->modifyQueryUsing(fn ($query) => $query->whereIn('status', $gudang))
                ->badge($count($gudang) ?: null)
                ->badgeColor('warning'),
            'accounting' => Tab::make('Di accounting')
                ->modifyQueryUsing(fn ($query) => $query->whereIn('status', $accounting))
                ->badge($count($accounting) ?: null),
            'dibayar' => Tab::make('Dibayar')
                ->modifyQueryUsing(fn ($query) => $query->where('status', PurchaseBill::STATUS_PAID)),
            'semua' => Tab::make('Semua'),
        ];
    }
}
