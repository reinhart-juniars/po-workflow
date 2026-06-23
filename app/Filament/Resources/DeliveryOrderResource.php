<?php

namespace App\Filament\Resources;

use Filament\Forms\Components\{TextInput, Select, DateTimePicker, Textarea, Section};
use App\Filament\Resources\DeliveryOrderResource\Pages;
use App\Filament\Resources\DeliveryOrderResource\RelationManagers;
use App\Models\DeliveryOrder;
use App\Support\UiLabel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Auth;
use App\Filament\Resources\DeliveryOrderResource\RelationManagers\PurchaseOrdersRelationManager;

class DeliveryOrderResource extends Resource
{
    protected static ?string $model = DeliveryOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'Delivery';

    public static function form(Form $form): Form
    {
        return $form
        ->schema([
            Forms\Components\Section::make('Delivery Order')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('do_code')
                        ->label('DO Code')
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\Select::make('area_id')
                        ->label('Area')
                        ->relationship('area', 'name')
                        ->preload()
                        ->required(),

                    Forms\Components\DateTimePicker::make('scheduled_at')
                        ->label('Scheduled At')
                        ->seconds(false)
                        ->required(),

                    Forms\Components\Select::make('driver_user_id')
                        ->label('Driver')
                        ->relationship('driver', 'name', modifyQueryUsing: fn (Builder $query) => $query
                            ->where('is_active', true)
                            ->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'delivery')))
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options(UiLabel::deliveryStatusOptions())
                        ->default('ready')
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\Textarea::make('notes')
                        ->rows(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('do_code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('area_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('scheduled_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('driver_user_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => UiLabel::deliveryStatus($state))
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->authorize(fn ($record) => Gate::allows('update', $record)),
                Tables\Actions\DeleteAction::make()
                    ->authorize(fn ($record) => Gate::allows('delete', $record)),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
                    ->authorize(fn () => Gate::allows('deleteAny', static::getModel())),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PurchaseOrdersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeliveryOrders::route('/'),
            'create' => Pages\CreateDeliveryOrder::route('/create'),
            'edit' => Pages\EditDeliveryOrder::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', static::getModel());
    }

    public static function canCreate(): bool
    {
        return Gate::allows('create', static::getModel());
    }

    public static function canEdit(Model $record): bool
    {
        return Gate::allows('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return Gate::allows('delete', $record);
    }
}
