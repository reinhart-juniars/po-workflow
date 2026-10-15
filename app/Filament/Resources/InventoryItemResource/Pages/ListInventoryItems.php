<?php

namespace App\Filament\Resources\InventoryItemResource\Pages;

use App\Exports\InventoryItemsExport;
use App\Exports\StockReportExport;
use App\Filament\Resources\InventoryItemResource;
use App\Imports\InventoryItemsImport;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class ListInventoryItems extends ListRecords
{
    protected static string $resource = InventoryItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Tambah Item'),

            // Format laporan stok harian gudang: satu lembar per jenis (Bahan Baku,
            // Kemasan, Inventaris) dengan stok awal / masuk / keluar / mutasi / sisa / value.
            Actions\Action::make('laporan_stok')
                ->label('Export Laporan Stok')
                ->icon('heroicon-m-document-chart-bar')
                ->color('gray')
                ->form([
                    Forms\Components\DatePicker::make('from')->label('Dari tanggal')->default(now())->required()->native(false)->displayFormat('d/m/Y'),
                    Forms\Components\DatePicker::make('to')->label('Sampai tanggal')->default(now())->required()->native(false)->displayFormat('d/m/Y')
                        ->afterOrEqual('from')
                        ->helperText('Stok awal = sisa akhir hari sebelum tanggal awal. Satu hari: isi tanggal yang sama.'),
                ])
                ->action(function (array $data) {
                    $from = Carbon::parse($data['from']);
                    $to = Carbon::parse($data['to']);

                    return Excel::download(
                        new StockReportExport($from, $to),
                        'Stok 3S ONE '.$to->translatedFormat('d F Y').'.xlsx'
                    );
                }),

            Actions\Action::make('export')
                ->label('Export Data Master')
                ->tooltip('Daftar item beserta kelompok, kemasan, dan harga -- format yang sama dipakai untuk Import Excel.')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new InventoryItemsExport,
                    'item-inventaris-'.now()->format('Ymd_His').'.xlsx'
                )),

            Actions\Action::make('export_riwayat_harga')
                ->label('Export Riwayat Harga')
                ->icon('heroicon-m-clock')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new \App\Exports\PriceHistoryExport,
                    'riwayat-harga-bahan-'.now()->format('Ymd_His').'.xlsx'
                )),

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

                    $summary = $import->created().' item baru, '.$import->updated().' item diperbarui.';
                    $notes = $import->notes();

                    // Ada baris dilewati / kategori diisi otomatis: tetap tampil
                    // sampai ditutup supaya sempat dibaca.
                    Notification::make()
                        ->status($notes ? 'warning' : 'success')
                        ->title('Import selesai')
                        ->body($notes ? $summary."\n".$notes : $summary)
                        ->duration($notes !== null ? 'persistent' : 6000)
                        ->send();
                }),
        ];
    }
}
