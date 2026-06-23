<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseOrderResource\Pages;
use App\Filament\Resources\PurchaseOrderResource\RelationManagers\PurchaseOrderItemsRelationManager;
use App\Models\PurchaseOrder;
use App\Models\Product;
use App\Support\UiLabel;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use App\Models\Customer;

// Form components
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Section;

// Table columns
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;

class PurchaseOrderResource extends \Filament\Resources\Resource
{
    protected static ?string $model = PurchaseOrder::class;
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationGroup = 'Orders';
    protected static ?string $navigationLabel = 'Purchase Orders';
    protected static ?string $modelLabel = 'Purchase Order';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Header')
                ->columns(2)
                ->schema([
                    // Tampilkan nomor PO tapi biarkan model yang mengisi (auto-number di booted())
                    TextInput::make('po_number')
                        ->label('PO Number')
                        ->default(fn () => app('autonumber')->peek('PO'))
                        ->disabled()
                        ->dehydrated(false),
                    Select::make('customer_id')
                        ->label('Customer')
                        ->relationship('customer', 'name', modifyQueryUsing: fn (Builder $query) => $query->where('active', true))
                        ->searchable()
                        ->preload()
                        // ⬇️ tombol "Create new" muncul di dropdown
                        ->createOptionForm([
                            TextInput::make('name')
                                ->label('Nama Customer')
                                ->required(),
                            TextInput::make('phone')
                                ->label('No. HP')
                                ->tel(),
                            Textarea::make('address')
                                ->label('Alamat')
                                ->rows(2),
                            Select::make('area_id')
                                ->label('Area')
                                ->relationship('area','name')
                                ->preload()
                                ->required(),
                        ])
                        ->createOptionUsing(function (array $data): int {
                            // pastikan kolom-kolom ini ada di $fillable pada model Customer
                            return Customer::create($data)->getKey();
                        }),

                    TextInput::make('recipient_name')
                        ->label('Recipient')
                        ->required(),

                    Textarea::make('shipping_address')
                        ->label('Shipping Address')
                        ->rows(2)
                        ->required(),

                    Select::make('area_id')
                        ->label('Area')
                        ->relationship('area', 'name')
                        ->preload()
                        ->required(),

                    DatePicker::make('delivery_date')
                        ->label('Delivery Date')
                        ->required(),

                    TimePicker::make('delivery_time')
                        ->label('Delivery Time')
                        ->seconds(false)
                        ->required(),

                    TextInput::make('discount_amount')
                        ->numeric()
                        ->default(0)
                        ->label('Discount'),
                ]),

            // Catatan: item PO dikelola via Relation Manager (tab tersendiri di bawah),
            // jadi tidak perlu Repeater di form utama kecuali kamu mau inline.
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('po_number')
                    ->label('PO')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('recipient_name')
                    ->label('Recipient')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('area.name')
                    ->label('Area')
                    ->sortable(),

                TextColumn::make('delivery_date')
                    ->date()
                    ->sortable(),

                TextColumn::make('delivery_time')
                    ->time()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => UiLabel::purchaseOrderStatus($state))
                    ->colors([
                        'warning' => 'pending',
                        'info'    => 'scheduled',
                        'primary' => 'in_production',
                        'gray'    => 'ready_for_delivery',
                        'success' => 'delivered',
                    ]),
            ])
            ->filters([
                SelectFilter::make('area_id')->relationship('area','name'),
                SelectFilter::make('status')->options([
                    'pending'           => UiLabel::purchaseOrderStatus('pending'),
                    'scheduled'         => UiLabel::purchaseOrderStatus('scheduled'),
                    'in_production'     => UiLabel::purchaseOrderStatus('in_production'),
                    'ready_for_delivery'=> UiLabel::purchaseOrderStatus('ready_for_delivery'),
                    'delivered'         => UiLabel::purchaseOrderStatus('delivered'),
                ]),
                Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('to')->label('To'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('delivery_date', '>=', $date))
                            ->when($data['to'] ?? null, fn ($q, $date) => $q->whereDate('delivery_date', '<=', $date));
                    }),
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
            PurchaseOrderItemsRelationManager::class, // Tab "Items"
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPurchaseOrders::route('/'),
            'create' => Pages\CreatePurchaseOrder::route('/create'),
            'edit'   => Pages\EditPurchaseOrder::route('/{record}/edit'),
            // View page opsional: aktifkan jika kamu generate sendiri
            // 'view' => Pages\ViewPurchaseOrder::route('/{record}'),
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

