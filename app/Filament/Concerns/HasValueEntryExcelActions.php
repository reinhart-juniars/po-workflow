<?php

namespace App\Filament\Concerns;

use App\Exports\ValueEntriesExport;
use App\Imports\ValueEntriesImport;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Tombol Export/Import Excel untuk resource bernilai per item (Stock Opname,
 * Saldo Awal). Mengikuti pola Item Inventaris: format import = format export,
 * berkas bermasalah ditolak seluruhnya.
 */
trait HasValueEntryExcelActions
{
    /**
     * @param  callable(): ValueEntriesExport  $export
     * @param  callable(?int): ValueEntriesImport  $import
     */
    protected function excelActions(string $filePrefix, callable $export, callable $import): array
    {
        return [
            Actions\Action::make('export')
                ->label('Export Excel')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download($export(), $filePrefix.'-'.now()->format('Ymd_His').'.xlsx')),

            Actions\Action::make('import')
                ->authorize('inventory.manage')
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
                        ->helperText('Gunakan format hasil Export (id, item_id, nama_item, tanggal, nilai, catatan). Baris ber-id memperbarui catatan yang ada; tanpa id dicocokkan berdasarkan item + tanggal.')
                        ->storeFiles(false),
                ])
                ->action(function (array $data) use ($import) {
                    $importer = $import(auth()->id());

                    Excel::import($importer, $data['berkas']);

                    if ($importer->hasErrors()) {
                        Notification::make()
                            ->danger()
                            ->title('Import dibatalkan')
                            ->body(implode("\n", array_slice($importer->errors(), 0, 5)))
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('Import selesai')
                        ->body($importer->created().' catatan baru, '.$importer->updated().' diperbarui.')
                        ->send();
                }),
        ];
    }
}
