<?php

namespace App\Filament\Resources\ProductionOrderResource\Pages;

use App\Filament\Resources\ProductionOrderResource;
use App\Models\ProductionOrder;
use App\Services\MaterialBreakdownService;
use Filament\Actions;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * Breakdown bahan satu SPK Produksi: tiap menu dipecah sampai bahan mentah
 * (padanan "Pra SPK" di Master Menu), direkap per bahan, dan dicocokkan
 * dengan stok Kartu Stok untuk melihat apa yang perlu dibeli.
 */
class MaterialBreakdown extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProductionOrderResource::class;

    protected static string $view = 'filament.resources.production-orders.breakdown';

    protected static ?string $title = 'Breakdown Bahan';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return 'Breakdown Bahan — '.$this->getOrder()->number;
    }

    public function getOrder(): ProductionOrder
    {
        /** @var ProductionOrder $record */
        $record = $this->getRecord();

        return $record;
    }

    public function getBreakdown(): array
    {
        return app(MaterialBreakdownService::class)->forProductionOrder($this->getOrder());
    }

    public function getMatchUrl(): ?string
    {
        return auth()->user()?->can('recipe.view') ? route('filament.admin.pages.pencocokan-menu') : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('excel')
                ->label('Export Excel')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(fn () => \Maatwebsite\Excel\Facades\Excel::download(
                    new \App\Exports\MaterialBreakdownExport($this->getBreakdown()),
                    'breakdown-bahan-'.$this->getOrder()->number.'.xlsx'
                )),

            Actions\Action::make('kebutuhan')
                ->label('Form Kebutuhan')
                ->icon('heroicon-m-clipboard-document-list')
                ->url(ProductionOrderResource::getUrl('kebutuhan', ['record' => $this->getOrder()])),

            Actions\Action::make('kembali')
                ->label('SPK')
                ->color('gray')
                ->url(ProductionOrderResource::getUrl('edit', ['record' => $this->getOrder()])),
        ];
    }
}
