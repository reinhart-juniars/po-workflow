<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'Master Data';
    protected static ?string $navigationLabel = 'Produk';
    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('sku')
                    ->label('SKU')
                    ->maxLength(50)
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('SKU dibuat otomatis dari nama menu saat simpan.'),
                Forms\Components\TextInput::make('name')
                    ->label('Nama Produk')
                    ->required()
                    ->maxLength(120)
                    ->autofocus()
                    ->live(debounce: 500)
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get, ?string $operation, ?Product $record): void {
                        $set('name', strtoupper((string) $state));

                        if ($operation === 'edit' && filled($record?->sku)) {
                            $set('sku', $record->sku);

                            return;
                        }

                        $previewName = $get('name') ?: $state;
                        $set('sku', Product::generateUniqueSku((string) $previewName, $record?->id));
                    }),
                Forms\Components\TextInput::make('unit')
                    ->label('Satuan')
                    ->default('PORSI')
                    ->maxLength(30)
                    ->afterStateUpdated(fn ($state, callable $set) => $set('unit', strtoupper($state))),
                Forms\Components\TextInput::make('base_price')
                    ->label('Harga Dasar')
                    ->prefix('Rp')
                    ->numeric()
                    ->required()
                    ->minValue(0),
                Forms\Components\Toggle::make('active')
                    ->label('Aktif')
                    ->default(true),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')
                    ->label('Nama Produk')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('unit')
                    ->label('Satuan')
                    ->sortable(),
                TextColumn::make('base_price')
                    ->label('Harga')
                    ->money('idr', true)
                    ->sortable(),
                IconColumn::make('active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Status Aktif')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif'),
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
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit'   => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
