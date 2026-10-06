<?php

namespace App\Filament\Resources\SupplierResource\Pages;

use App\Filament\Resources\SupplierResource;
use App\Models\Supplier;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Hapus')
                ->before(function (Supplier $record, Actions\DeleteAction $action) {
                    $blockers = $record->transactionBlockers();

                    if ($blockers !== []) {
                        Notification::make()
                            ->danger()
                            ->title('Supplier tidak bisa dihapus')
                            ->body('Supplier sudah dipakai di '.implode(', ', $blockers).'. Nonaktifkan saja supaya tidak muncul di transaksi baru.')
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
