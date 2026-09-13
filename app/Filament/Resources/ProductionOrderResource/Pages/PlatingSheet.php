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
            Actions\Action::make('cetak')
                ->label('Cetak Plating')
                ->icon('heroicon-m-printer')
                ->action(fn () => app(ProductionDocumentService::class)->platingPdf($this->getOrder())),

            Actions\Action::make('kembali')
                ->label('SPK')
                ->color('gray')
                ->url(ProductionOrderResource::getUrl('edit', ['record' => $this->getOrder()])),
        ];
    }
}
