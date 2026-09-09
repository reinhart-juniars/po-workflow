<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RecipeMismatchResource\Pages;
use App\Models\InventoryItem;
use App\Models\RecipeMismatch;
use App\Services\RecipeMismatchResolver;
use App\Support\Units\Unit;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Bahan resep yang belum punya padanan di master bahan.
 *
 * Dikelompokkan per nama, bukan per baris: 4.878 baris yatim hanya berisi 365
 * nama unik, dan satu keputusan menautkan ratusan baris sekaligus. Daftar ini
 * adalah daftar kerja rekonsiliasi bersama klien, jadi urutan bawaannya adalah
 * yang paling banyak dipakai lebih dulu.
 */
class RecipeMismatchResource extends Resource
{
    protected static ?string $model = RecipeMismatch::class;

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Bahan Belum Cocok';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'Bahan Belum Cocok';

    protected static ?string $pluralModelLabel = 'Bahan Belum Cocok';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('raw_name')
                    ->label('Nama pada Resep')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (RecipeMismatch $record) => $record->sample_unit
                        ? 'Satuan contoh: '.$record->sample_unit
                        : null),

                TextColumn::make('occurrence_count')
                    ->label('Baris')
                    ->sortable()
                    ->alignRight(),

                TextColumn::make('recipe_count')
                    ->label('Resep')
                    ->sortable()
                    ->alignRight(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => RecipeMismatch::statusLabels()[$state] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        RecipeMismatch::STATUS_LINKED, RecipeMismatch::STATUS_CREATED => 'success',
                        RecipeMismatch::STATUS_IGNORED => 'gray',
                        default => 'warning',
                    })
                    ->sortable(),

                TextColumn::make('resolvedItem.name')
                    ->label('Ditautkan ke')
                    ->placeholder('-')
                    ->searchable(),

                TextColumn::make('resolution_note')
                    ->label('Catatan')
                    ->wrap()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(RecipeMismatch::statusLabels())
                    ->default(RecipeMismatch::STATUS_OPEN),
            ])
            ->actions([
                Tables\Actions\Action::make('tautkan')
                    ->label('Tautkan')
                    ->icon('heroicon-m-link')
                    ->visible(fn (RecipeMismatch $record) => ! $record->isResolved())
                    ->form([
                        Forms\Components\Select::make('inventory_item_id')
                            ->label('Bahan')
                            ->options(fn () => RecipeResource::ingredientOptions())
                            ->searchable()
                            ->required()
                            ->native(false),

                        Forms\Components\TextInput::make('note')
                            ->label('Catatan')
                            ->maxLength(255),
                    ])
                    ->modalDescription(fn (RecipeMismatch $record) => 'Seluruh baris resep bernama "'.$record->raw_name
                        .'" akan menunjuk bahan ini sekaligus.')
                    ->action(function (RecipeMismatch $record, array $data) {
                        $item = InventoryItem::query()->findOrFail($data['inventory_item_id']);

                        $affected = app(RecipeMismatchResolver::class)
                            ->linkToItem($record, $item, $data['note'] ?? null, auth()->id());

                        Notification::make()
                            ->success()
                            ->title('Ditautkan ke '.$item->name)
                            ->body($affected.' baris resep ikut selesai.')
                            ->send();
                    }),

                Tables\Actions\Action::make('buat_bahan')
                    ->label('Buat Bahan')
                    ->icon('heroicon-m-plus-circle')
                    ->color('gray')
                    ->visible(fn (RecipeMismatch $record) => ! $record->isResolved())
                    ->fillForm(fn (RecipeMismatch $record) => [
                        'name' => $record->raw_name,
                        'unit' => Unit::tryFromAlias($record->sample_unit)?->value ?? $record->sample_unit,
                        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
                        'unit_price' => $record->assumed_unit_price !== null ? (float) $record->assumed_unit_price : null,
                    ])
                    ->form([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Bahan')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Select::make('unit')
                            ->label('Satuan Harga')
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->options(fn (Forms\Get $get) => RecipeResource::unitOptions($get('unit')))
                            ->helperText('Satuan yang dipakai harga bahan ini, belum tentu sama dengan satuan di resep.'),

                        Forms\Components\Select::make('category')
                            ->label('Kategori')
                            ->options(fn () => collect(InventoryItem::categoryOptions())
                                ->only(InventoryItem::stockCategories())
                                ->all())
                            ->required()
                            ->native(false),

                        Forms\Components\TextInput::make('unit_price')
                            ->label('Harga per Satuan')
                            ->prefix('Rp')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Boleh dikosongkan; barisnya akan terhitung nol sampai harganya diisi.'),

                        Forms\Components\TextInput::make('resolution_note')
                            ->label('Catatan')
                            ->maxLength(255),
                    ])
                    ->action(function (RecipeMismatch $record, array $data) {
                        $hasil = app(RecipeMismatchResolver::class)->createItem($record, $data, auth()->id());

                        Notification::make()
                            ->success()
                            ->title('Bahan "'.$hasil['item']->name.'" dibuat')
                            ->body($hasil['affected'].' baris resep ikut selesai.')
                            ->send();
                    }),

                Tables\Actions\Action::make('abaikan')
                    ->label('Abaikan')
                    ->icon('heroicon-m-eye-slash')
                    ->color('gray')
                    ->visible(fn (RecipeMismatch $record) => ! $record->isResolved())
                    ->form([
                        Forms\Components\TextInput::make('note')
                            ->label('Alasan')
                            ->maxLength(255)
                            ->placeholder('mis. keterangan takaran, bukan bahan'),
                    ])
                    ->action(function (RecipeMismatch $record, array $data) {
                        app(RecipeMismatchResolver::class)->ignore($record, $data['note'] ?? null, auth()->id());

                        Notification::make()
                            ->success()
                            ->title('Ditandai diabaikan')
                            ->body('Baris resepnya tidak diubah; hanya keputusannya yang dicatat.')
                            ->send();
                    }),

                Tables\Actions\Action::make('buka_ulang')
                    ->label('Buka Ulang')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Tautan pada baris resep akan dilepas kembali dan nama ini kembali ke daftar kerja.')
                    ->visible(fn (RecipeMismatch $record) => $record->isResolved())
                    ->action(function (RecipeMismatch $record) {
                        $affected = app(RecipeMismatchResolver::class)->reopen($record);

                        Notification::make()
                            ->warning()
                            ->title('Keputusan dibuka ulang')
                            ->body($affected.' baris resep dilepas kembali.')
                            ->send();
                    }),
            ])
            ->defaultSort('occurrence_count', 'desc');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = RecipeMismatch::query()->open()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRecipeMismatches::route('/'),
        ];
    }
}
