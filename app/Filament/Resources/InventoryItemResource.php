<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryItemResource\Pages;
use App\Models\InventoryItem;
use App\Services\InventoryStockAlertService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InventoryItemResource extends Resource
{
    protected static ?string $model = InventoryItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Item Inventaris';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Item Inventaris';

    protected static ?string $pluralModelLabel = 'Item Inventaris';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nama Item')
                    ->required()
                    ->maxLength(255)
                    ->autofocus(),

                Forms\Components\TextInput::make('unit')
                    ->label('Satuan')
                    ->required()
                    ->maxLength(50),

                Forms\Components\Select::make('category')
                    ->label('Kategori')
                    ->options(InventoryItem::categoryOptions())
                    ->required()
                    ->native(false),

                Forms\Components\TextInput::make('minimum_stock_value')
                    ->label('Nilai Stok Minimum')
                    ->prefix('Rp')
                    ->numeric()
                    ->minValue(0)
                    ->helperText(
                        'Alert menyala saat nilai stok turun di bawah angka ini. '
                        .'Kosongkan bila item ini tidak perlu dipantau. '
                        .'Ambang memakai nilai rupiah karena stok di sistem ini dicatat sebagai nilai, bukan kuantitas.'
                    ),

                Forms\Components\Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),

                Forms\Components\Textarea::make('description')
                    ->label('Keterangan')
                    ->rows(3)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama Item')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('unit')
                    ->label('Satuan')
                    ->sortable(),

                TextColumn::make('category')
                    ->label('Kategori')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => InventoryItem::categoryOptions()[$state] ?? $state)
                    ->sortable(),

                TextColumn::make('minimum_stock_value')
                    ->label('Stok Minimum')
                    ->money('idr', true)
                    ->placeholder('Tidak dipantau')
                    ->sortable(),

                // Nilai stok berjalan tidak tersimpan sebagai kolom -- dihitung
                // dari opname terakhir ditambah pembelian sesudahnya.
                TextColumn::make('stock_value')
                    ->label('Nilai Stok')
                    ->state(fn (InventoryItem $record) => app(InventoryStockAlertService::class)
                        ->currentStockValue($record->id)['value'])
                    ->money('idr', true)
                    ->color(fn ($state, InventoryItem $record) => $record->minimum_stock_value !== null
                        && (float) $state < (float) $record->minimum_stock_value
                            ? 'danger'
                            : null)
                    ->description(fn (InventoryItem $record) => $record->minimum_stock_value !== null
                        && (float) app(InventoryStockAlertService::class)->currentStockValue($record->id)['value']
                            < (float) $record->minimum_stock_value
                                ? 'Di bawah minimum'
                                : null),

                TextColumn::make('purchases_count')
                    ->label('Pembelian')
                    ->counts('purchases')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('opnames_count')
                    ->label('Opname')
                    ->counts('opnames')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(InventoryItem::categoryOptions()),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif'),

                Tables\Filters\Filter::make('below_minimum')
                    ->label('Hanya di bawah stok minimum')
                    ->toggle()
                    ->query(function ($query) {
                        $ids = app(InventoryStockAlertService::class)
                            ->alerts()
                            ->pluck('item.id');

                        return $query->whereIn('id', $ids);
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Edit'),
                Tables\Actions\DeleteAction::make()
                    ->label('Hapus')
                    // Item yang sudah dipakai transaksi tidak boleh hilang; sama
                    // seperti aturan pada modul Blade yang digantikan.
                    ->before(function (InventoryItem $record, Tables\Actions\DeleteAction $action) {
                        $blockers = static::transactionBlockers($record);

                        if ($blockers !== []) {
                            Notification::make()
                                ->danger()
                                ->title('Item tidak bisa dihapus')
                                ->body('Item sudah dipakai di '.implode(', ', $blockers).'. Hapus atau koreksi transaksi terkait dulu.')
                                ->send();

                            $action->cancel();
                        }
                    }),
            ])
            ->defaultSort('name');
    }

    /**
     * Ringkasan transaksi yang menahan penghapusan item.
     *
     * @return array<int, string>
     */
    public static function transactionBlockers(InventoryItem $item): array
    {
        $item->loadCount(['openings', 'purchases', 'opnames']);

        return collect([
            'saldo awal' => (int) $item->openings_count,
            'pembelian stok' => (int) $item->purchases_count,
            'stock opname' => (int) $item->opnames_count,
        ])
            ->filter(fn (int $count) => $count > 0)
            ->map(fn (int $count, string $label) => $label.' ('.$count.')')
            ->values()
            ->all();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = app(InventoryStockAlertService::class)->alertCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryItems::route('/'),
            'create' => Pages\CreateInventoryItem::route('/create'),
            'edit' => Pages\EditInventoryItem::route('/{record}/edit'),
        ];
    }
}
