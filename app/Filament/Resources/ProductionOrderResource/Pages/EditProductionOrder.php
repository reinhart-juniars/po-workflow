<?php

namespace App\Filament\Resources\ProductionOrderResource\Pages;

use App\Filament\Resources\ProductionOrderResource;
use App\Models\ProductionOrder;
use App\Services\ProductionDocumentService;
use App\Services\ProductionOrderService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditProductionOrder extends EditRecord
{
    protected static string $resource = ProductionOrderResource::class;

    protected function getHeaderActions(): array
    {
        /** @var ProductionOrder $order */
        $order = $this->getRecord();

        return [
            Actions\Action::make('segarkan')
                ->label('Segarkan dari PO')
                ->icon('heroicon-m-arrow-path')
                ->color('gray')
                ->visible(fn () => $order->spk_id !== null && $order->isEditable())
                ->requiresConfirmation()
                ->modalDescription('Baris dari PO disusun ulang mengikuti item PO terkini. Baris manual tidak disentuh.')
                ->action(function () use ($order) {
                    try {
                        app(ProductionOrderService::class)->generateFromSpk($order->spk, auth()->id());
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Gagal menyegarkan')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title('Baris dari PO disegarkan')->send();
                    $this->redirect(ProductionOrderResource::getUrl('edit', ['record' => $order]));
                }),

            Actions\Action::make('siap')
                ->label('Tandai Siap Produksi')
                ->icon('heroicon-m-check')
                ->color('info')
                ->visible(fn () => $order->status === ProductionOrder::STATUS_DRAFT)
                ->action(function () use ($order) {
                    $order->update(['status' => ProductionOrder::STATUS_PLANNED, 'updated_by' => auth()->id()]);
                    Notification::make()->success()->title('SPK siap produksi')->send();
                    $this->redirect(ProductionOrderResource::getUrl('kebutuhan', ['record' => $order]));
                }),

            Actions\Action::make('kebutuhan')
                ->label('Form Kebutuhan')
                ->icon('heroicon-m-clipboard-document-list')
                ->url(ProductionOrderResource::getUrl('kebutuhan', ['record' => $order])),

            Actions\Action::make('pekerjaan')
                ->label('Lembar Kerja')
                ->icon('heroicon-m-users')
                ->color('gray')
                ->url(ProductionOrderResource::getUrl('pekerjaan', ['record' => $order])),

            Actions\Action::make('plating')
                ->label('Plating')
                ->icon('heroicon-m-squares-2x2')
                ->color('gray')
                ->url(ProductionOrderResource::getUrl('plating', ['record' => $order])),

            Actions\Action::make('cetak')
                ->label('Cetak SPK')
                ->icon('heroicon-m-printer')
                ->color('gray')
                ->action(fn () => app(ProductionDocumentService::class)->productionOrderPdf($order)),

            Actions\Action::make('batalkan')
                ->label('Batalkan')
                ->icon('heroicon-m-x-mark')
                ->color('danger')
                ->visible(fn () => $order->isEditable())
                ->requiresConfirmation()
                ->action(function () use ($order) {
                    $order->update(['status' => ProductionOrder::STATUS_CANCELLED, 'updated_by' => auth()->id()]);
                    Notification::make()->warning()->title('SPK dibatalkan')->send();
                    $this->redirect(ProductionOrderResource::getUrl('index'));
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = auth()->id();

        return $data;
    }
}
