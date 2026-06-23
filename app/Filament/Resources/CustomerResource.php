<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'Master Data';
    protected static ?string $navigationLabel = 'Customer';
    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nama Customer')
                    ->required()
                    ->maxLength(120)
                    ->reactive()
                    ->afterStateUpdated(fn($state, $set) => $set('name', strtoupper($state))),
                Forms\Components\TextInput::make('phone')
                    ->label('Nomor WA')
                    ->tel()
                    ->maxLength(20)
                    ->prefix('+62')
                    ->placeholder('812xxxxxxx'),
                Forms\Components\Textarea::make('address')
                    ->label('Alamat')
                    ->rows(2)
                    ->maxLength(255)
                    ->afterStateUpdated(fn($state, $set) => $set('address', strtoupper($state))),
                Forms\Components\Select::make('area_id')
                    ->label('Area')
                    ->relationship('area','name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Toggle::make('active')
                    ->label('Aktif')
                    ->default(true),
                Forms\Components\Toggle::make('is_lapak')
                    ->label('Lapak')
                    ->default(false),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('WA')
                    ->sortable(),
                TextColumn::make('area.name')
                    ->label('Area')
                    ->sortable(),
                IconColumn::make('active')
                    ->label('Aktif')
                    ->boolean(),
                IconColumn::make('is_lapak')
                    ->label('Lapak')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Status Aktif')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif')
                    ->placeholder('Semua'),
                Tables\Filters\TernaryFilter::make('is_lapak')
                    ->label('Lapak')
                    ->trueLabel('Lapak')
                    ->falseLabel('Non Lapak')
                    ->placeholder('Semua'),
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
            'index'  => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit'   => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
