<?php

namespace App\Filament\Resources\RecipeResource\Pages;

use App\Exports\MenuListExport;
use App\Exports\RecipesExport;
use App\Filament\Resources\RecipeResource;
use App\Imports\RecipesImport;
use App\Models\Recipe;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListRecipes extends ListRecords
{
    protected static string $resource = RecipeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Tambah Resep'),

            Actions\Action::make('export')
                ->label('Export Excel')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new RecipesExport,
                    'resep-'.now()->format('Ymd_His').'.xlsx'
                )),

            Actions\Action::make('export_daftar_menu')
                ->label('Export Daftar Menu')
                ->icon('heroicon-m-table-cells')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new MenuListExport,
                    'daftar-menu-'.now()->format('Ymd_His').'.xlsx'
                )),

            Actions\Action::make('import')
                ->authorize('recipe.manage')
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
                            'Gunakan format hasil Export. Rincian bahan sebuah resep diganti seluruhnya '
                            .'oleh baris yang ada di berkas, jadi kolom bahan_id dan sub_resep_id harus ikut terbawa.'
                        )
                        ->storeFiles(false),
                ])
                ->action(function (array $data) {
                    $import = new RecipesImport;

                    Excel::import($import, $data['berkas']);

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
                        ->body($import->created().' resep baru, '.$import->updated().' diperbarui, '
                            .$import->lines().' baris bahan tersimpan.')
                        ->send();
                }),
        ];
    }

    /**
     * Menu utama dan sub-menu dipisahkan di sini, bukan menjadi dua modul.
     *
     * Dua tab terakhir adalah daftar kerja: menu yang belum terhubung ke produk
     * penjualan, dan resep yang masih punya bahan belum tertaut. Keduanya yang
     * menahan HPP berbasis resep dari bisa dipercaya.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'semua' => Tab::make('Semua'),

            'utama' => Tab::make('Menu Utama')
                ->modifyQueryUsing(fn ($query) => $query->utama())
                ->badge(Recipe::query()->utama()->count()),

            'sub' => Tab::make('Sub-Menu')
                ->modifyQueryUsing(fn ($query) => $query->sub())
                ->badge(Recipe::query()->sub()->count()),

            'belum_dipetakan' => Tab::make('Belum Dipetakan')
                ->modifyQueryUsing(fn ($query) => $query->utama()->unmapped())
                ->badge(Recipe::query()->utama()->unmapped()->count())
                ->badgeColor('warning'),

            'bahan_yatim' => Tab::make('Ada Bahan Belum Tertaut')
                ->modifyQueryUsing(fn ($query) => $query->whereHas('items', fn ($items) => $items->unmatched()))
                ->badge(Recipe::query()->whereHas('items', fn ($items) => $items->unmatched())->count())
                ->badgeColor('danger'),
        ];
    }
}
