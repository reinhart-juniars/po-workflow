<?php

namespace App\Filament\Resources\PurchaseBillResource\Pages;

use App\Filament\Resources\PurchaseBillResource;
use App\Models\PurchaseBill;
use App\Services\PurchaseBillService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

/**
 * Detail tagihan. Selama di tangan gudang (draft / dikembalikan): nota,
 * supplier, dan catatan bisa dilengkapi lalu diajukan. Sesudahnya halaman
 * ini hanya untuk dilihat -- keputusan ada di aplikasi Accounting.
 */
class EditPurchaseBill extends EditRecord
{
    protected static string $resource = PurchaseBillResource::class;

    protected static string $view = 'filament.resources.purchase-bills.edit';

    public function getTitle(): string
    {
        return 'Tagihan '.$this->getBill()->number;
    }

    public function getBill(): PurchaseBill
    {
        /** @var PurchaseBill $record */
        $record = $this->getRecord();

        return $record;
    }

    /** Melihat cukup izin lihat; mengubah dijaga policy update di bawah. */
    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canView($this->getRecord()), 403);
    }

    public function form(\Filament\Forms\Form $form): \Filament\Forms\Form
    {
        // Sudah di accounting: tampil sebagai bacaan, bukan isian.
        return parent::form($form)->disabled(! $this->canEditBill());
    }

    public function canEditBill(): bool
    {
        return auth()->user()?->can('update', $this->getBill()) ?? false;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        abort_unless($this->canEditBill(), 403);

        return $data;
    }

    protected function afterSave(): void
    {
        // Supplier bisa berubah -> nama di hutangnya ikut.
        app(PurchaseBillService::class)->syncPayable($this->getBill());
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Tagihan disimpan';
    }

    protected function getFormActions(): array
    {
        return $this->canEditBill() ? [$this->getSaveFormAction()->label('Simpan')] : [];
    }

    protected function getHeaderActions(): array
    {
        $bill = $this->getBill();

        return [
            Actions\Action::make('ajukan')
                ->label('Ajukan ke Accounting')
                ->icon('heroicon-m-paper-airplane')
                ->visible(fn () => $this->canEditBill())
                ->requiresConfirmation()
                ->modalHeading('Ajukan '.$bill->number.' ke accounting?')
                ->modalDescription(fn () => 'Total Rp '.number_format((float) $this->getBill()->total, 0, ',', '.').'. '
                    .($this->getBill()->receipt_path ? 'Nota terlampir.' : 'Belum ada foto nota — accounting bisa mengembalikannya.'))
                ->modalSubmitActionLabel('Ajukan')
                ->action(function () {
                    // Isian yang belum disimpan ikut terkirim.
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                    try {
                        app(PurchaseBillService::class)->submit($this->getBill()->refresh(), auth()->id());
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();

                        return;
                    }

                    Notification::make()->success()->title('Tagihan diajukan ke accounting')->send();
                    $this->redirect(PurchaseBillResource::getUrl('edit', ['record' => $this->getBill()]));
                }),

            Actions\Action::make('nota')
                ->label('Lihat nota')
                ->icon('heroicon-m-paper-clip')
                ->color('gray')
                ->visible(fn () => filled($this->getBill()->receipt_path))
                ->url(fn () => route('purchase-bills.receipt', $this->getBill()), shouldOpenInNewTab: true),
        ];
    }

    protected function getRedirectUrl(): ?string
    {
        return null;
    }
}
