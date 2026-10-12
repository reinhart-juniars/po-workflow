<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryOpeningResource\Pages;
use App\Models\InventoryItem;
use App\Models\InventoryOpening;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InventoryOpeningResource extends Resource
{
    use \App\Filament\Concerns\InInventoryPanel;

    protected static ?string $model = InventoryOpening::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Saldo Awal Stok';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'Saldo Awal';

    protected static ?string $pluralModelLabel = 'Saldo Awal';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('inventory_item_id')
                    ->label('Item')
                    ->relationship('item', 'name', fn ($query) => $query->orderBy('name'))
                    ->required()
                    ->searchable()
                    ->preload()
                    ->native(false),

                Forms\Components\DatePicker::make('balance_date')
                    ->label('Tanggal Saldo Awal')
                    ->required()
                    ->default(now())
                    ->native(false),

                // Konvensi sama seperti opname dan pembelian: stok dicatat sebagai
                // nilai, qty selalu 1 dan unit_cost menampung nilai penuh.
                Forms\Components\Hidden::make('qty')->default(1),

                Forms\Components\TextInput::make('unit_cost')
                    ->label('Nilai Saldo Awal')
                    ->prefix('Rp')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->helperText('Nilai persediaan awal yang dibawa masuk pada tanggal tersebut.'),

                Forms\Components\Textarea::make('notes')
                    ->label('Catatan')
                    ->rows(3)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('balance_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('item.name')
                    ->label('Item')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('total_value')
                    ->label('Nilai Stok')
                    ->money('IDR', locale: 'id')
                    ->sortable(),

                TextColumn::make('notes')
                    ->label('Catatan')
                    ->limit(50)
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('inventory_item_id')
                    ->label('Item')
                    ->options(fn () => InventoryItem::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),

                Tables\Filters\Filter::make('periode')
                    ->form([
                        Forms\Components\DatePicker::make('dari')->label('Dari Tanggal')->native(false),
                        Forms\Components\DatePicker::make('sampai')->label('Sampai Tanggal')->native(false),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['dari'] ?? null, fn ($q, $date) => $q->whereDate('balance_date', '>=', $date))
                        ->when($data['sampai'] ?? null, fn ($q, $date) => $q->whereDate('balance_date', '<=', $date))),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Edit'),
                Tables\Actions\DeleteAction::make()->label('Hapus'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('balance_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryOpenings::route('/'),
            'create' => Pages\CreateInventoryOpening::route('/create'),
            'edit' => Pages\EditInventoryOpening::route('/{record}/edit'),
        ];
    }
}
