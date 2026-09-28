<?php

namespace App\Filament\Resources\ProductionOrderResource\Pages;

use App\Filament\Resources\ProductionOrderResource;
use App\Models\ProductionOrder;
use App\Services\PlatingService;
use App\Services\ProductionDocumentService;
use Filament\Actions;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/** Lembar plating: tiap menu beserta komponen yang harus ada di piring. */
class PlatingSheet extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProductionOrderResource::class;

    protected static string $view = 'filament.resources.production-orders.plating';

    protected static ?string $title = 'Plating';

    /** global = satu tabel; kartu = komponen per menu (seperti Menu Plating Master Menu). */
    public string $tampilan = 'global';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return 'Plating — '.$this->getOrder()->number;
    }

    public function getOrder(): ProductionOrder
    {
        /** @var ProductionOrder $record */
        $record = $this->getRecord();

        return $record;
    }

    /** @return array{rows: array<int, array<string, mixed>>, total_qty: float} */
    public function getSheet(): array
    {
        return app(PlatingService::class)->sheet($this->getOrder());
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('tampilan_kartu')
                ->label('Komponen per Menu')
                ->icon('heroicon-m-squares-2x2')
                ->color('gray')
                ->visible(fn () => $this->tampilan === 'global')
                ->action(fn () => $this->tampilan = 'kartu'),

            Actions\Action::make('tampilan_global')
                ->label('Komponen Global')
                ->icon('heroicon-m-table-cells')
                ->color('gray')
                ->visible(fn () => $this->tampilan === 'kartu')
                ->action(fn () => $this->tampilan = 'global'),

            Actions\Action::make('cetak')
                ->label('Cetak Plating')
                ->icon('heroicon-m-printer')
                ->action(fn () => app(ProductionDocumentService::class)->platingPdf($this->getOrder(), $this->tampilan)),

            Actions\Action::make('excel')
                ->label('Export Excel')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(fn () => \Maatwebsite\Excel\Facades\Excel::download(
                    new \App\Exports\PlatingExport($this->getOrder()),
                    'plating-'.$this->getOrder()->number.'.xlsx'
                )),

            Actions\Action::make('kembali')
                ->label('SPK')
                ->color('gray')
                ->url(ProductionOrderResource::getUrl('edit', ['record' => $this->getOrder()])),
        ];
    }
}
