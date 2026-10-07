<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductionWorkerResource\Pages;
use App\Models\ProductionWorker;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Pelaksana dapur untuk lembar kerja; bukan pengguna sistem. */
class ProductionWorkerResource extends Resource
{
    use \App\Filament\Concerns\InInventoryPanel;

    protected static ?string $model = ProductionWorker::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Produksi';

    protected static ?string $navigationLabel = 'Pelaksana';

    protected static ?int $navigationSort = 60;

    protected static ?string $modelLabel = 'Pelaksana';

    protected static ?string $pluralModelLabel = 'Pelaksana';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),

            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Edit'),
                Tables\Actions\DeleteAction::make()->label('Hapus'),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductionWorkers::route('/'),
            'create' => Pages\CreateProductionWorker::route('/create'),
            'edit' => Pages\EditProductionWorker::route('/{record}/edit'),
        ];
    }
}
