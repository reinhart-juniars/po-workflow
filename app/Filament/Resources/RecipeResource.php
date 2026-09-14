<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RecipeResource\Pages;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Services\RecipeCostService;
use App\Support\Settings\Settings;
use App\Support\Units\Unit;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Resep dan menu.
 *
 * Menu utama dan sub-menu tinggal di satu tabel yang sama dan dibedakan kolom
 * `jenis`, karena keduanya dihitung dengan cara yang persis sama -- sub-menu
 * hanyalah resep yang dipakai resep lain. Memisahkannya menjadi dua modul akan
 * menggandakan seluruh form dan seluruh perhitungannya tanpa menambah apa pun;
 * pemisahannya cukup lewat tab pada daftar.
 */
class RecipeResource extends Resource
{
    protected static ?string $model = Recipe::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Resep & Menu';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Resep';

    protected static ?string $pluralModelLabel = 'Resep & Menu';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Menu')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Menu')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        Forms\Components\Select::make('jenis')
                            ->label('Jenis')
                            ->options(Recipe::jenisOptions())
                            ->default(Recipe::JENIS_UTAMA)
                            ->required()
                            ->native(false)
                            ->helperText('Sub-menu adalah komponen yang dipakai resep lain, mis. bumbu atau sambal.'),

                        Forms\Components\TextInput::make('kategori')
                            ->label('Kategori')
                            ->maxLength(255),

                        Forms\Components\Select::make('product_id')
                            ->label('Produk yang Dijual')
                            ->relationship('product', 'name')
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->columnSpan(2)
                            ->helperText(
                                'Menghubungkan resep ini ke produk pada alur penjualan. '
                                .'Boleh dikosongkan selama pemetaannya belum disepakati.'
                            ),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Hasil Produksi & Harga')
                    ->schema([
                        Forms\Components\TextInput::make('yield_qty')
                            ->label('Jumlah Hasil')
                            ->numeric()
                            ->required()
                            ->minValue(0.0001)
                            ->step('any')
                            ->default(1)
                            ->helperText('Hasil satu kali produksi resep ini.'),

                        Forms\Components\Select::make('yield_unit')
                            ->label('Satuan Hasil')
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->default('porsi')
                            ->options(fn (?Recipe $record, Get $get) => static::unitOptions($record?->yield_unit, $get('yield_unit'))),

                        Forms\Components\TextInput::make('target_price')
                            ->label('Harga Jual Target')
                            ->prefix('Rp')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Bila diisi, angka inilah yang dipakai menilai profit, bukan harga hasil hitungan.'),

                        // Disimpan sebagai pecahan (0,40) tetapi diisi sebagai
                        // persen, karena begitulah angkanya dibicarakan. Nilai
                        // bawaan ikut diberikan sebagai pecahan supaya lewat
                        // formatStateUsing yang sama.
                        Forms\Components\TextInput::make('ohc_pct')
                            ->label('OHC')
                            ->suffix('%')
                            ->numeric()
                            ->required()
                            ->default(fn () => app(Settings::class)->percentAsFraction('recipe.default_ohc_pct'))
                            ->step('any')
                            ->formatStateUsing(fn (?string $state) => $state === null ? app(Settings::class)->get('recipe.default_ohc_pct') : round((float) $state * 100, 2))
                            ->dehydrateStateUsing(fn ($state) => (float) $state / 100),

                        Forms\Components\TextInput::make('profit_pct')
                            ->label('Target Profit')
                            ->suffix('%')
                            ->numeric()
                            ->required()
                            ->default(fn () => app(Settings::class)->percentAsFraction('recipe.default_profit_pct'))
                            ->step('any')
                            ->formatStateUsing(fn (?string $state) => $state === null ? app(Settings::class)->get('recipe.default_profit_pct') : round((float) $state * 100, 2))
                            ->dehydrateStateUsing(fn ($state) => (float) $state / 100)
                            ->helperText('Dipakai menilai apakah harga jualnya sudah memadai.'),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Rincian Bahan')
                    ->description('Sebuah baris menunjuk bahan, atau sub-menu, atau belum keduanya. Baris yang belum menunjuk apa pun masuk daftar bahan belum cocok.')
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->label('')
                            ->relationship()
                            ->orderColumn('sort_order')
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state) => trim(($state['qty'] ?? '').' '.($state['unit'] ?? '').' '.($state['raw_name'] ?? '')) ?: 'Baris baru')
                            ->defaultItems(0)
                            ->addActionLabel('Tambah Baris')
                            ->schema([
                                Forms\Components\TextInput::make('raw_name')
                                    ->label('Nama pada Resep')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(2)
                                    ->helperText('Teks asli dari resep; disimpan apa adanya agar baris salah tautan bisa ditelusuri.'),

                                Forms\Components\TextInput::make('qty')
                                    ->label('Jumlah')
                                    ->numeric()
                                    ->required()
                                    ->step('any')
                                    ->default(0),

                                Forms\Components\Select::make('unit')
                                    ->label('Satuan')
                                    ->searchable()
                                    ->native(false)
                                    ->options(fn (Get $get) => static::unitOptions($get('unit'))),

                                Forms\Components\Select::make('inventory_item_id')
                                    ->label('Bahan')
                                    ->options(fn (Get $get) => static::ingredientOptions($get('inventory_item_id')))
                                    ->searchable()
                                    ->native(false)
                                    ->live()
                                    ->columnSpan(2)
                                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                        if ($state && blank($get('raw_name'))) {
                                            $set('raw_name', (string) InventoryItem::query()->whereKey($state)->value('name'));
                                        }
                                    }),

                                Forms\Components\Select::make('ref_recipe_id')
                                    ->label('Sub-Menu')
                                    ->options(fn (?RecipeItem $record, Get $get) => static::subRecipeOptions($record?->recipe_id))
                                    ->searchable()
                                    ->native(false)
                                    ->live()
                                    ->columnSpan(2)
                                    ->rules([
                                        fn (Get $get): Closure => static::singleLinkRule($get),
                                    ])
                                    ->helperText('Isi salah satu saja: bahan atau sub-menu.'),

                                Forms\Components\TextInput::make('section')
                                    ->label('Kelompok')
                                    ->maxLength(255)
                                    ->placeholder('mis. Bumbu Halus')
                                    ->columnSpan(2),

                                Forms\Components\TextInput::make('notes')
                                    ->label('Catatan')
                                    ->maxLength(255)
                                    ->columnSpan(2),
                            ])
                            ->columns(4),
                    ]),

                Forms\Components\Section::make('Template Kerja')
                    ->description('Pekerjaan paten untuk menu ini; disalin ke lembar kerja tiap SPK Produksi yang memasaknya.')
                    ->collapsed()
                    ->schema([
                        Forms\Components\Repeater::make('tasks')
                            ->label('')
                            ->relationship()
                            ->orderColumn('sort_order')
                            ->reorderable()
                            ->defaultItems(0)
                            ->addActionLabel('Tambah Pekerjaan')
                            ->itemLabel(fn (array $state) => trim(($state['task'] ?? '').' '.($state['object'] ?? '').' '.($state['quantity_text'] ?? '')) ?: 'Pekerjaan baru')
                            ->schema([
                                Forms\Components\TextInput::make('task')->label('Pekerjaan')->placeholder('potong / goreng')->required()->maxLength(255),
                                Forms\Components\TextInput::make('object')->label('Objek')->placeholder('ayam, wortel')->maxLength(255),
                                Forms\Components\TextInput::make('quantity_text')->label('Jumlah')->placeholder('25 gr')->maxLength(100),
                                Forms\Components\Select::make('pic')
                                    ->label('PIC Bawaan')
                                    ->options(fn () => \App\Models\ProductionWorker::options())
                                    ->searchable()
                                    ->native(false),
                            ])
                            ->columns(4),
                    ]),

                Forms\Components\Section::make('Catatan')
                    ->collapsed()
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(3)
                            ->columnSpanFull(),

                        Forms\Components\Placeholder::make('snapshot')
                            ->label('Angka Bawaan Excel')
                            ->content(fn (?Recipe $record) => $record?->snapshot_hpp !== null
                                ? 'HPP '.number_format((float) $record->snapshot_hpp, 2, ',', '.')
                                    .' — dipakai hanya selama resep ini belum punya rincian bahan.'
                                : 'Tidak ada.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama Menu')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(fn (Recipe $record) => $record->kategori),

                TextColumn::make('jenis')
                    ->label('Jenis')
                    ->badge()
                    ->color(fn (?string $state) => $state === Recipe::JENIS_SUB ? 'gray' : 'primary')
                    ->formatStateUsing(fn (?string $state) => Recipe::jenisOptions()[$state] ?? $state)
                    ->sortable(),

                TextColumn::make('items_count')
                    ->label('Baris')
                    ->counts('items')
                    ->alignRight(),

                // HPP tidak tersimpan sebagai kolom: angkanya harus selalu
                // mengikuti harga bahan terbaru, bukan angka yang membeku.
                TextColumn::make('hpp')
                    ->label('HPP / Hasil')
                    ->state(fn (Recipe $record) => app(RecipeCostService::class)->cost($record)['hpp_per_yield'])
                    ->money('IDR', locale: 'id')
                    ->alignRight()
                    ->description(fn (Recipe $record) => rtrim(rtrim(number_format((float) $record->yield_qty, 2, ',', '.'), '0'), ',')
                        .' '.$record->yield_unit),

                TextColumn::make('status_hitung')
                    ->label('Status Hitungan')
                    ->badge()
                    ->state(fn (Recipe $record) => static::costStatus($record))
                    ->color(fn (string $state) => match ($state) {
                        'Lengkap' => 'success',
                        'Angka Excel' => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('product.name')
                    ->label('Produk')
                    ->placeholder('Belum dipetakan')
                    ->searchable()
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('jenis')
                    ->label('Jenis')
                    ->options(Recipe::jenisOptions()),

                Tables\Filters\Filter::make('belum_dipetakan')
                    ->label('Belum dipetakan ke produk')
                    ->toggle()
                    ->query(fn ($query) => $query->unmapped()),

                Tables\Filters\Filter::make('punya_baris_yatim')
                    ->label('Punya bahan belum tertaut')
                    ->toggle()
                    ->query(fn ($query) => $query->whereHas('items', fn ($items) => $items->unmatched())),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif'),
            ])
            ->actions([
                Tables\Actions\Action::make('hpp')
                    ->label('Analisa HPP')
                    ->icon('heroicon-m-calculator')
                    ->color('gray')
                    ->url(fn (Recipe $record) => static::getUrl('hpp', ['record' => $record])),

                Tables\Actions\EditAction::make()->label('Edit'),
                Tables\Actions\DeleteAction::make()->label('Hapus'),
            ])
            ->defaultSort('name');
    }

    /** Ringkas keadaan perhitungan sebuah resep menjadi satu kata. */
    public static function costStatus(Recipe $recipe): string
    {
        $cost = app(RecipeCostService::class)->cost($recipe);

        return match (true) {
            $cost['has_cycle'] => 'Resep Berputar',
            $cost['uses_snapshot'] => 'Angka Excel',
            $cost['issues'] !== [] => 'Belum Lengkap',
            default => 'Lengkap',
        };
    }

    /**
     * Pilihan satuan, termasuk nilai lepas yang sudah ada di data.
     *
     * @return array<string, array<string, string>|string>
     */
    public static function unitOptions(?string ...$current): array
    {
        $options = Unit::groupedOptions();

        foreach ($current as $value) {
            if (filled($value) && Unit::tryFromAlias($value) === null) {
                $options['Satuan Lain'][$value] = $value;
            }
        }

        return $options;
    }

    /**
     * Bahan yang bisa dipilih pada baris resep.
     *
     * Bucket induk sengaja tidak ikut: menautkan baris resep ke bucket "Bahan
     * Baku" akan membuat HPP-nya memakai harga bucket, bukan harga bahannya.
     *
     * @return array<int, string>
     */
    public static function ingredientOptions(mixed $current = null): array
    {
        return InventoryItem::query()
            ->ingredients()
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $current))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Sub-menu yang bisa dirujuk sebuah baris.
     *
     * Resep tidak boleh merujuk dirinya sendiri; putaran yang lebih panjang
     * tetap terdeteksi saat perhitungan, tetapi yang paling mudah terjadi --
     * dan paling mudah dicegah -- adalah rujukan ke diri sendiri.
     *
     * @return array<int, string>
     */
    public static function subRecipeOptions(?int $excludeRecipeId = null): array
    {
        return Recipe::query()
            ->when($excludeRecipeId, fn ($query) => $query->whereKeyNot($excludeRecipeId))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** Sebuah baris menunjuk bahan atau sub-menu, tidak keduanya sekaligus. */
    protected static function singleLinkRule(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get) {
            if (filled($value) && filled($get('inventory_item_id'))) {
                $fail('Baris ini menunjuk bahan sekaligus sub-menu. Pilih salah satu; biayanya tidak bisa dihitung dua kali.');
            }
        };
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Recipe::query()->whereHas('items', fn ($items) => $items->unmatched())->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Resep yang masih punya bahan belum tertaut.';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRecipes::route('/'),
            'create' => Pages\CreateRecipe::route('/create'),
            'hpp' => Pages\RecipeCostBreakdown::route('/{record}/hpp'),
            'edit' => Pages\EditRecipe::route('/{record}/edit'),
        ];
    }
}
