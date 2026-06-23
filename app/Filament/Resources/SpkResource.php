<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SpkResource\Pages;
use App\Filament\Resources\SpkResource\RelationManagers\PurchaseOrdersRelationManager;
use App\Models\Spk;
use App\Support\UiLabel;
use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Section;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Get;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Auth;

class SpkResource extends \Filament\Resources\Resource
{
    protected static ?string $model = Spk::class;
    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';
    protected static ?string $navigationGroup = 'Production';
    protected static ?string $navigationLabel = 'SPK';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('SPK Info')->columns(2)->schema([
                TextInput::make('spk_code')
                    ->label('SPK Code')
                    ->disabled()
                    ->dehydrated(false),

                DateTimePicker::make('scheduled_at')
                    ->label('Scheduled At')
                    ->seconds(false)
                    ->required(),

                Select::make('slot_type')
                    ->label('Slot Type')
                    ->options(UiLabel::spkSlotOptions())
                    ->required(),

                Select::make('responsible_user_id')
                    ->label('Responsible (Production)')
                    ->relationship('responsible', 'name', modifyQueryUsing: fn (Builder $query) => $query
                        ->where('is_active', true)
                        ->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'production'))) // belongsTo User
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('status')
                    ->label('Status')
                    ->options(UiLabel::spkStatusOptions())
                    ->default('draft')
                    ->disabled() // status idealnya diubah via action khusus
                    ->dehydrated(false),

                Textarea::make('notes')->rows(2),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('spk_code')->label('SPK')->searchable()->sortable(),
                TextColumn::make('scheduled_at')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('slot_type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => UiLabel::spkSlot($state))
                    ->colors([
                        'info' => ['fixed_03','fixed_07','fixed_11'],
                        'gray' => ['custom'],
                    ]),
                TextColumn::make('responsible.name')->label('Responsible')->toggleable(),
                TextColumn::make('purchaseOrders_count')->counts('purchaseOrders')->label('PO Count'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => UiLabel::spkStatus($state))
                    ->colors([
                        'warning' => 'draft',
                        'primary' => 'in_process',
                        'success' => 'completed',
                    ]),
            ])
            ->filters([
                SelectFilter::make('slot_type')->options(UiLabel::spkSlotOptions()),
                SelectFilter::make('status')->options(UiLabel::spkStatusOptions()),
                Filter::make('date_range')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('to')->label('To'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $v) => $q->whereDate('scheduled_at', '>=', $v))
                            ->when($data['to'] ?? null, fn (Builder $q, $v) => $q->whereDate('scheduled_at', '<=', $v));
                    })
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
            PurchaseOrdersRelationManager::class, // BelongsToMany (attach/detach) ke PO
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSpks::route('/'),
            'create' => Pages\CreateSpk::route('/create'),
            'edit'   => Pages\EditSpk::route('/{record}/edit'),
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
