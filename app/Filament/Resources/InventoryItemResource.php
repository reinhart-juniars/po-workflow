<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryItemResource\Pages;
use App\Filament\Resources\InventoryItemResource\RelationManagers;
use App\Models\InventoryItem;
use App\Services\InventoryStockAlertService;
use App\Support\Units\Unit;
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
    use \App\Filament\Concerns\InInventoryPanel;

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

                Forms\Components\Select::make('unit')
                    ->label('Satuan')
                    ->required()
                    ->searchable()
                    ->native(false)
                    // Satuan lama seperti "All" atau "Unit" tidak ada di daftar;
                    // nilainya tetap ditawarkan supaya menyunting item lama tidak
                    // diam-diam mengganti satuannya.
                    ->options(fn (?InventoryItem $record) => static::unitOptions($record?->unit))
                    ->helperText('Satuan berat dan volume bisa dikonversi otomatis saat resep dihitung; satuan hitung tidak.'),

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

                Forms\Components\Select::make('suppliers')
                    ->label('Supplier')
                    ->relationship('suppliers', 'name', fn ($query) => $query->orderBy('name'))
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->helperText('Supplier yang memasok bahan ini. Terisi otomatis dari pembelian; kelola datanya di Master Supplier.')
                    ->columnSpanFull(),

                // Kolom bahan (dulu milik Master Menu): induk/bucket, kelompok,
                // kemasan, dan harga satuan yang dipakai HPP resep. Harga satuan
                // dihitung dari harga kemasan / isi bila keduanya diisi, tetapi
                // tetap bisa ditulis langsung untuk bahan tanpa kemasan.
                Forms\Components\Section::make('Data Bahan')
                    ->description('Dipakai resep & HPP. Item tanpa induk diperlakukan sebagai bucket (kelompok stok di Laba Rugi).')
                    ->schema([
                        Forms\Components\Select::make('parent_id')
                            ->label('Induk / Bucket')
                            ->options(fn (?InventoryItem $record) => InventoryItem::query()->buckets()
                                ->when($record, fn ($q) => $q->whereKeyNot($record->id))
                                ->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->native(false)
                            ->placeholder('— tidak ada (item ini bucket) —'),

                        Forms\Components\TextInput::make('ingredient_group')
                            ->label('Kelompok Bahan')
                            ->maxLength(100)
                            ->placeholder('mis. sayur, bumbu, protein'),

                        Forms\Components\TextInput::make('pack_qty')
                            ->label('Isi Kemasan')
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->suffix(fn (Forms\Get $get) => $get('unit'))
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => static::syncUnitPrice($get, $set)),

                        Forms\Components\TextInput::make('pack_price')
                            ->label('Harga Kemasan')
                            ->prefix('Rp')
                            ->numeric()
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => static::syncUnitPrice($get, $set)),

                        Forms\Components\TextInput::make('unit_price')
                            ->label('Harga Satuan')
                            ->prefix('Rp')
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->helperText('Per satuan di atas. Terisi otomatis dari harga kemasan / isi; setiap perubahan dicatat ke Histori Harga.'),

                        Forms\Components\Toggle::make('is_prepared')
                            ->label('Bahan olahan (dibuat sendiri)')
                            ->inline(false),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    /** Harga satuan = harga kemasan / isi kemasan, bila keduanya terisi. */
    public static function syncUnitPrice(Forms\Get $get, Forms\Set $set): void
    {
        $qty = (float) $get('pack_qty');
        $price = (float) $get('pack_price');

        if ($qty > 0 && $price > 0) {
            $set('unit_price', round($price / $qty, 4));
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Nama boleh turun baris supaya tabel muat satu layar laptop
                // tanpa digeser ke samping.
                TextColumn::make('name')
                    ->label('Nama Item')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('unit')
                    ->label('Satuan')
                    ->sortable(),

                TextColumn::make('category')
                    ->label('Kategori')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => InventoryItem::categoryOptions()[$state] ?? $state)
                    ->sortable(),

                // Induk hampir selalu sama dengan kategorinya; disembunyikan
                // bawaan supaya tabel tidak melebar, tetap bisa dimunculkan.
                TextColumn::make('parent.name')
                    ->label('Induk')
                    ->placeholder('bucket')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('suppliers.name')
                    ->label('Supplier')
                    ->badge()
                    ->color('gray')
                    ->limitList(2)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('unit_price')
                    ->label('Harga Satuan')
                    ->money('IDR', locale: 'id')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('minimum_stock_value')
                    ->label('Stok Minimum')
                    ->money('IDR', locale: 'id')
                    ->placeholder('Tidak dipantau')
                    ->sortable(),

                // Nilai stok berjalan tidak tersimpan sebagai kolom -- dihitung
                // dari opname terakhir ditambah pembelian sesudahnya.
                TextColumn::make('stock_value')
                    ->label('Nilai Stok')
                    ->state(fn (InventoryItem $record) => app(InventoryStockAlertService::class)
                        ->currentStockValue($record->id)['value'])
                    ->money('IDR', locale: 'id')
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
     * Pilihan satuan, dikelompokkan per besaran.
     *
     * Nilai lama yang tidak dikenal registri tetap disertakan supaya item lama
     * bisa disunting tanpa dipaksa berganti satuan.
     *
     * @return array<string, array<string, string>|string>
     */
    public static function unitOptions(?string $current = null): array
    {
        $options = Unit::groupedOptions();

        if (filled($current) && Unit::tryFromAlias($current) === null) {
            $options['Satuan Lama'][$current] = $current;
        }

        return $options;
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

    public static function getRelations(): array
    {
        return [
            RelationManagers\PriceHistoriesRelationManager::class,
        ];
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
