<?php

namespace App\Filament\Menu\Resources;

use App\Filament\Menu\Resources\MasterMenuResource\Pages;
use App\Models\Product;
use App\Models\Recipe;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Master Menu di aplikasi Menu (revisi 9 Nov 2026): satu-satunya tempat
 * mengatur menu yang dipesan di PO -- nama, SKU, satuan, resep, Menu 3S,
 * aktif, harga lapak, dan HPP manual untuk menu tanpa resep.
 *
 * Menu baru tidak dibuat di sini melainkan dari Menu Utama (MasterMenuSync),
 * jadi tidak ada tombol tambah. Harga Non Lapak milik admin (Admin App ›
 * Harga Menu) dan hanya ditampilkan.
 */
class MasterMenuResource extends Resource
{
    use \App\Filament\Concerns\InMenuPanel;

    protected static ?string $model = Product::class;

    protected static ?string $slug = 'master-menu';

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Menu & Resep';

    protected static ?string $navigationLabel = 'Master Menu';

    protected static ?int $navigationSort = 12;

    protected static ?string $modelLabel = 'Menu';

    protected static ?string $pluralModelLabel = 'Master Menu';

    protected static ?string $recordTitleAttribute = 'name';

    // ProductPolicy milik admin; di aplikasi Menu aksesnya ikut izin resep.
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('recipe.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('recipe.manage') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Menu')
                ->schema([
                    // Menu jual Menu Utama memakai nama resep; diganti lewat resepnya.
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Menu')
                        ->disabled(fn (?Product $record) => $record?->origin === Product::ORIGIN_MENU)
                        ->helperText(fn (?Product $record) => $record?->origin === Product::ORIGIN_MENU
                            ? 'Ikut nama resep Menu Utama. Ganti nama di resepnya.'
                            : 'Menu lama di luar Menu Utama: tidak bisa dipesan di PO.')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('sku')
                        ->label('SKU')
                        ->helperText('Tampil sebagai nama menu di website.')
                        ->required()
                        ->maxLength(100)
                        ->unique(ignoreRecord: true)
                        ->validationMessages(['unique' => 'SKU ini sudah dipakai menu lain.']),

                    Forms\Components\TextInput::make('unit')
                        ->label('Satuan')
                        ->required()
                        ->maxLength(50),

                    Forms\Components\Select::make('recipe_id')
                        ->label('Resep (Menu Utama)')
                        ->options(fn () => Recipe::query()->where('jenis', Recipe::JENIS_UTAMA)->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->native(false)
                        ->placeholder('Tanpa resep')
                        ->helperText('Menu tanpa resep yang 90 hari tidak dipakai di PO otomatis dinonaktifkan.')
                        ->columnSpan(2),

                    Forms\Components\Toggle::make('is_3s')->label('Menu 3S (Premium)'),

                    Forms\Components\Toggle::make('active')
                        ->label('Aktif')
                        ->helperText('Nonaktif = tidak muncul di pilihan PO.'),
                ])
                ->columns(3),

            Forms\Components\Section::make('Harga & HPP')
                ->schema([
                    // Disabled = tidak ikut tersimpan: nilainya diisi sinkron resep.
                    Forms\Components\TextInput::make('lapak_price')
                        ->label('Harga Lapak')
                        ->prefix('Rp')
                        ->numeric()
                        ->minValue(0)
                        ->disabled(fn (?Product $record) => $record?->lapakFollowsRecipe() ?? false)
                        ->helperText(fn (?Product $record) => $record?->lapakFollowsRecipe()
                            ? 'Dari Harga Jual Target resep. Ubah di resepnya.'
                            : 'Kosong = item lapak memakai Harga Non Lapak.'),

                    Forms\Components\TextInput::make('raw_material_cost')
                        ->label('HPP Bahan Baku')
                        ->prefix('Rp')
                        ->numeric()
                        ->minValue(0)
                        ->disabled(fn (?Product $record) => $record?->costFollowsRecipe() ?? false)
                        ->helperText(fn (?Product $record) => $record?->costFollowsRecipe() ? 'Dari resep.' : 'Manual, untuk menu tanpa resep.'),

                    Forms\Components\TextInput::make('overhead_cost')
                        ->label('OHC')
                        ->prefix('Rp')
                        ->numeric()
                        ->minValue(0)
                        ->disabled(fn (?Product $record) => $record?->costFollowsRecipe() ?? false)
                        ->helperText(fn (?Product $record) => $record?->costFollowsRecipe() ? 'Dari resep (OHC % × HPP).' : 'Manual, untuk menu tanpa resep.'),

                    Forms\Components\Placeholder::make('harga_non_lapak')
                        ->label('Harga Non Lapak')
                        ->content(fn (?Product $record) => (float) ($record?->base_price ?? 0) > 0
                            ? 'Rp '.number_format((float) $record->base_price, 0, ',', '.')
                            : 'Belum diisi')
                        ->helperText('Diisi admin di Admin App › Harga Menu.'),
                ])
                ->columns(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('recipe:id,name'))
            ->columns([
                TextColumn::make('name')
                    ->label('Menu')
                    ->description(fn (Product $record) => $record->sku)
                    ->searchable(['name', 'sku'])
                    ->sortable()
                    ->wrap(),

                TextColumn::make('recipe.name')
                    ->label('Resep')
                    ->placeholder('Tanpa resep')
                    ->wrap(),

                TextColumn::make('unit')->label('Satuan')->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('raw_material_cost')
                    ->label('HPP')
                    ->money('IDR', locale: 'id')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('lapak_price')
                    ->label('Harga Lapak')
                    ->money('IDR', locale: 'id')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('base_price')
                    ->label('Harga Non Lapak')
                    ->formatStateUsing(fn ($state) => (float) $state > 0 ? 'Rp '.number_format((float) $state, 0, ',', '.') : 'Belum diisi')
                    ->color(fn ($state) => (float) $state > 0 ? null : 'warning')
                    ->sortable(),

                IconColumn::make('is_3s')->label('3S')->boolean()->toggleable(),

                IconColumn::make('active')->label('Aktif')->boolean(),
            ])
            ->filters([
                // Bawaan: hanya menu yang dipesan di PO (menu jual Menu Utama).
                Tables\Filters\TernaryFilter::make('menu_jual')
                    ->label('Bisa dipesan di PO')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->orderable(),
                        false: fn (Builder $query) => $query->whereNotIn('id', Product::query()->orderable()->select('id')),
                    ),

                Tables\Filters\TernaryFilter::make('active')->label('Aktif'),

                Tables\Filters\TernaryFilter::make('punya_resep')
                    ->label('Resep')
                    ->trueLabel('Punya resep')
                    ->falseLabel('Tanpa resep')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('recipe_id'),
                        false: fn (Builder $query) => $query->whereNull('recipe_id'),
                    ),

                Tables\Filters\Filter::make('harga_kosong')
                    ->label('Harga Non Lapak belum diisi')
                    ->query(fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNull('base_price')->orWhere('base_price', '<=', 0))),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Edit')->iconButton()->tooltip('Edit'),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMasterMenus::route('/'),
            'edit' => Pages\EditMasterMenu::route('/{record}/edit'),
        ];
    }
}
