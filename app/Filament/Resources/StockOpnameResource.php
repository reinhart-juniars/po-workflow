<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockOpnameResource\Pages;
use App\Models\InventoryItem;
use App\Models\StockOpname;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StockOpnameResource extends Resource
{
    protected static ?string $model = StockOpname::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Stock Opname';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Stock Opname';

    protected static ?string $pluralModelLabel = 'Stock Opname';

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

                Forms\Components\DatePicker::make('opname_date')
                    ->label('Tanggal Opname')
                    ->required()
                    ->default(now())
                    ->native(false),

                // Stok dicatat sebagai nilai, bukan kuantitas: qty selalu 1 dan
                // unit_cost menampung nilai penuh. total_value dihitung model.
                Forms\Components\Hidden::make('qty')->default(1),

                Forms\Components\TextInput::make('unit_cost')
                    ->label('Nilai Stok Akhir')
                    ->prefix('Rp')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->helperText('Nilai sisa stok hasil perhitungan fisik pada tanggal opname.'),

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
                TextColumn::make('opname_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('item.name')
                    ->label('Item')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('total_value')
                    ->label('Nilai Stok')
                    ->money('idr', true)
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
                        ->when($data['dari'] ?? null, fn ($q, $date) => $q->whereDate('opname_date', '>=', $date))
                        ->when($data['sampai'] ?? null, fn ($q, $date) => $q->whereDate('opname_date', '<=', $date))),
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
            ->defaultSort('opname_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockOpnames::route('/'),
            'create' => Pages\CreateStockOpname::route('/create'),
            'edit' => Pages\EditStockOpname::route('/{record}/edit'),
        ];
    }
}
