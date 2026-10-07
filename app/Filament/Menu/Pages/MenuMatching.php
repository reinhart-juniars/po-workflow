<?php

namespace App\Filament\Menu\Pages;

use App\Exports\MenuMatchingExport;
use App\Filament\Menu\Resources\RecipeResource;
use App\Imports\MenuMatchingImport;
use App\Models\Product;
use App\Models\Recipe;
use App\Services\MenuMatchSuggester;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Pencocokan Menu: daftar kerja menautkan master produk (Admin App) ke resep.
 *
 * Berbasis produk, bukan resep, dan diurutkan porsi terjual 90 hari terakhir:
 * 20 produk terlaris menutup dua pertiga porsi yang belum punya resep, jadi
 * sesi kerja bersama klien dimulai dari atas. Tiap baris punya tiga jalan
 * keluar -- tautkan ke resep yang ada, tandai memang tanpa resep, atau buat
 * resep baru -- supaya daftar ini bisa benar-benar kosong, bukan hanya berkurang.
 */
class MenuMatching extends Page implements HasTable
{
    use \App\Filament\Concerns\PageInPanel;

    protected static string $ownerPanel = 'menu';

    use InteractsWithTable;

    public const STATUS_BELUM = 'belum';

    public const STATUS_TERTAUT = 'tertaut';

    public const STATUS_TANPA = 'tanpa';

    /** Rentang hari untuk kolom porsi terjual. */
    public const HARI_PENJUALAN = 90;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Menu & Resep';

    protected static ?string $navigationLabel = 'Pencocokan Menu';

    protected static ?int $navigationSort = 15;

    protected static ?string $slug = 'pencocokan-menu';

    protected static string $view = 'filament.pages.menu-matching';

    protected static ?string $title = 'Pencocokan Menu: Produk ↔ Resep';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('recipe.view') ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Product::query()->where('active', true)->awaitingRecipe()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Lembar kerja Excel: ditarik, digarap staf pemilik, diunggah kembali.
     * Ekspor selalu seluruh produk (bukan mengikuti filter) supaya berkas yang
     * beredar satu bentuk dan bisa diunggah kapan saja.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')
                ->label('Export Excel')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(new MenuMatchingExport, 'pencocokan-menu-'.now()->format('Ymd_His').'.xlsx')),

            Actions\Action::make('import')
                ->label('Import Excel')
                ->icon('heroicon-m-arrow-up-tray')
                ->color('gray')
                ->authorize('recipe.manage')
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
                            'Gunakan berkas hasil Export. Isi salah satu per baris: terima_usulan = ya, '
                            .'resep_id / nama_resep, atau tanpa_resep = ya. Baris yang dibiarkan kosong tidak diubah; '
                            .'import tidak pernah melepas tautan yang sudah ada.'
                        )
                        ->storeFiles(false),
                ])
                ->action(function (array $data) {
                    $import = new MenuMatchingImport;

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
                        ->body($import->linked().' produk ditautkan ke resep, '.$import->withoutRecipe()
                            .' ditandai tanpa resep, '.$import->skipped().' baris tidak berubah.')
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $suggester = app(MenuMatchSuggester::class);

        return $table
            ->query(fn () => Product::query()->with('recipe')->withPorsiTerjual(self::HARI_PENJUALAN))
            ->defaultSort('porsi_terjual', 'desc')
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Produk (Admin App)')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Product $record) => $record->active ? null : 'Nonaktif'),

                TextColumn::make('porsi_terjual')
                    ->label('Porsi '.self::HARI_PENJUALAN.' hari')
                    ->numeric(0, ',', '.')
                    ->sortable()
                    ->alignRight(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Product $record) => static::statusOf($record))
                    ->formatStateUsing(fn (string $state) => static::statusLabels()[$state])
                    ->color(fn (string $state) => match ($state) {
                        self::STATUS_TERTAUT => 'success',
                        self::STATUS_TANPA => 'gray',
                        default => 'warning',
                    }),

                // Satu kolom untuk keduanya: resep yang sudah tertaut, atau -- selama
                // belum -- usulan terdekat beserta skornya, supaya tabel tetap muat
                // satu layar bersama tombol aksinya.
                TextColumn::make('resep')
                    ->label('Resep / Usulan')
                    ->state(function (Product $record) use ($suggester) {
                        if ($record->recipe) {
                            return $record->recipe->name;
                        }

                        if (static::statusOf($record) !== self::STATUS_BELUM) {
                            return null;
                        }

                        $usulan = $suggester->suggest($record);

                        return $usulan ? 'Usulan: '.$usulan['recipe']->name.' ('.number_format($usulan['score'], 0).'%)' : null;
                    })
                    ->placeholder(fn (Product $record) => static::statusOf($record) === self::STATUS_BELUM ? 'Tidak ada yang mirip' : '-')
                    ->color(fn (Product $record) => $record->recipe ? null : 'gray')
                    ->wrap()
                    ->url(fn (Product $record) => $record->recipe
                        ? RecipeResource::getUrl('edit', ['record' => $record->recipe])
                        : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(static::statusLabels())
                    ->default(self::STATUS_BELUM)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        self::STATUS_BELUM => $query->awaitingRecipe(),
                        self::STATUS_TERTAUT => $query->whereNotNull('recipe_id'),
                        self::STATUS_TANPA => $query->where('needs_recipe', false),
                        default => $query,
                    }),

                Tables\Filters\TernaryFilter::make('active')
                    ->label('Produk aktif')
                    ->default(true),
            ])
            ->actions([
                Tables\Actions\Action::make('tautkan')
                    ->label('Tautkan')
                    ->button()
                    ->size('sm')
                    ->icon('heroicon-m-link')
                    ->authorize('recipe.manage')
                    ->visible(fn (Product $record) => static::statusOf($record) !== self::STATUS_TERTAUT)
                    ->modalHeading(fn (Product $record) => 'Tautkan "'.$record->name.'" ke resep')
                    ->modalSubmitActionLabel('Tautkan')
                    ->form(fn (Product $record) => [
                        Forms\Components\Select::make('recipe_id')
                            ->label('Resep')
                            ->options(fn () => Recipe::query()->utama()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->default($suggester->suggest($record)['recipe']->id ?? null)
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required()
                            ->helperText('Satu resep boleh dipakai beberapa produk (varian harga 10K/12K).'),
                    ])
                    ->action(function (Product $record, array $data) {
                        $record->forceFill(['recipe_id' => (int) $data['recipe_id'], 'needs_recipe' => true])->save();

                        Notification::make()->title('Produk ditautkan ke resep.')->success()->send();
                    }),

                // Aksi sekunder dilipat supaya kolom aksi tetap terlihat tanpa
                // menggulir tabel ke samping.
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('tanpa_resep')
                        ->label('Tanpa Resep')
                        ->icon('heroicon-m-no-symbol')
                        ->color('gray')
                        ->authorize('recipe.manage')
                        ->visible(fn (Product $record) => static::statusOf($record) === self::STATUS_BELUM)
                        ->requiresConfirmation()
                        ->modalHeading(fn (Product $record) => 'Tandai "'.$record->name.'" tidak perlu resep')
                        ->modalDescription('Untuk produk yang memang tidak dimasak: biaya tambahan, selisih harga, barang beli jadi. Produk keluar dari daftar kerja dan tidak ikut SPK Produksi.')
                        ->action(function (Product $record) {
                            $record->forceFill(['recipe_id' => null, 'needs_recipe' => false])->save();

                            Notification::make()->title('Produk ditandai tanpa resep.')->success()->send();
                        }),

                    Tables\Actions\Action::make('buat_resep')
                        ->label('Buat Resep')
                        ->icon('heroicon-m-plus')
                        ->color('gray')
                        ->authorize('recipe.manage')
                        ->visible(fn (Product $record) => static::statusOf($record) === self::STATUS_BELUM)
                        ->url(fn (Product $record) => RecipeResource::getUrl('create', ['produk' => $record->id])),

                    Tables\Actions\Action::make('lepas')
                        ->label('Lepas')
                        ->icon('heroicon-m-arrow-uturn-left')
                        ->color('gray')
                        ->authorize('recipe.manage')
                        ->visible(fn (Product $record) => static::statusOf($record) !== self::STATUS_BELUM)
                        ->requiresConfirmation()
                        ->modalHeading(fn (Product $record) => 'Kembalikan "'.$record->name.'" ke daftar kerja')
                        ->action(function (Product $record) {
                            $record->forceFill(['recipe_id' => null, 'needs_recipe' => true])->save();

                            Notification::make()->title('Produk dikembalikan ke daftar kerja.')->success()->send();
                        }),
                ])->label('Lainnya')->icon('heroicon-m-ellipsis-vertical')->color('gray')->iconButton(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('tanpa_resep_massal')
                    ->label('Tandai Tanpa Resep')
                    ->icon('heroicon-m-no-symbol')
                    ->color('gray')
                    ->authorize('recipe.manage')
                    ->requiresConfirmation()
                    ->modalDescription('Semua produk terpilih dianggap tidak dimasak dan keluar dari daftar kerja.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        Product::query()->whereKey($records->modelKeys())->update(['recipe_id' => null, 'needs_recipe' => false]);

                        Notification::make()->title($records->count().' produk ditandai tanpa resep.')->success()->send();
                    }),
            ])
            ->emptyStateHeading('Tidak ada produk pada status ini')
            ->emptyStateDescription('Ubah filter Status untuk melihat produk yang sudah tertaut atau ditandai tanpa resep.')
            ->paginated([25, 50, 100]);
    }

    public static function statusOf(Product $product): string
    {
        return match (true) {
            $product->recipe_id !== null => self::STATUS_TERTAUT,
            ! $product->needs_recipe => self::STATUS_TANPA,
            default => self::STATUS_BELUM,
        };
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_BELUM => 'Belum dicocokkan',
            self::STATUS_TERTAUT => 'Sudah tertaut',
            self::STATUS_TANPA => 'Tanpa resep',
        ];
    }

    /**
     * Ringkasan untuk strip KPI di atas tabel: berapa porsi terjual yang
     * sudah bisa dihitung HPP-nya lewat resep.
     *
     * @return array{produk_belum: int, produk_tertaut: int, produk_tanpa: int, porsi_total: float, porsi_tercakup: float}
     */
    public function getSummary(): array
    {
        $rows = Product::query()->where('active', true)->withPorsiTerjual(self::HARI_PENJUALAN)->get(['id', 'recipe_id', 'needs_recipe']);

        $dihitung = $rows->filter(fn (Product $p) => $p->recipe_id !== null || ! $p->needs_recipe);

        return [
            'produk_belum' => $rows->filter(fn (Product $p) => static::statusOf($p) === self::STATUS_BELUM)->count(),
            'produk_tertaut' => $rows->filter(fn (Product $p) => static::statusOf($p) === self::STATUS_TERTAUT)->count(),
            'produk_tanpa' => $rows->filter(fn (Product $p) => static::statusOf($p) === self::STATUS_TANPA)->count(),
            'porsi_total' => (float) $rows->sum('porsi_terjual'),
            'porsi_tercakup' => (float) $dihitung->sum('porsi_terjual'),
        ];
    }
}
