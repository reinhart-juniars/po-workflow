<?php

namespace App\Filament\Resources\InventoryItemResource\Pages;

use App\Exports\InventoryItemsExport;
use App\Filament\Resources\InventoryItemResource;
use App\Imports\InventoryItemsImport;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListInventoryItems extends ListRecords
{
    protected static string $resource = InventoryItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Tambah Item'),

            Actions\Action::make('export')
                ->label('Export Excel')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new InventoryItemsExport,
                    'item-inventaris-'.now()->format('Ymd_His').'.xlsx'
                )),

            Actions\Action::make('import')
                ->label('Import Excel')
                ->icon('heroicon-m-arrow-up-tray')
                ->color('gray')
                ->form([
                    Forms\Components\FileUpload::make('berkas')
                        ->label('Berkas Excel')
                        ->required()
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                            'text/csv',
                        ])
                        ->helperText(
                            'Gunakan format hasil Export. Baris ber-id memperbarui item yang ada, '
                            .'baris tanpa id dicocokkan berdasarkan nama.'
                        )
                        ->storeFiles(false),
                ])
                ->action(function (array $data) {
                    $import = new InventoryItemsImport;

                    Excel::import($import, $data['berkas']);

                    // Berkas bermasalah ditolak seluruhnya; sebagian tersimpan
                    // lebih sulit dibereskan daripada tidak tersimpan sama sekali.
                    if ($import->hasErrors()) {
                        Notification::make()
                            ->danger()
                            ->title('Import dibatalkan')
                            ->body(implode("\n", array_slice($import->errors(), 0, 5)))
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('Import selesai')
                        ->body($import->created().' item baru, '.$import->updated().' item diperbarui.')
                        ->send();
                }),
        ];
    }
}
