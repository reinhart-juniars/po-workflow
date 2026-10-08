<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseBillResource\Pages;
use App\Models\InventoryItem;
use App\Models\PurchaseBill;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Tagihan Pembelian (modul Pembelian, aplikasi Inventory).
 *
 * Gudang belanja lalu menagih ke accounting: tiap Form Kebutuhan yang
 * diperiksa melahirkan satu tagihan draft; belanja di luar SPK dicatat lewat
 * "Belanja Lepas". Gudang melampirkan foto nota lalu mengajukan; accounting
 * membayar dari aplikasi Accounting › Tagihan Pembelian.
 */
class PurchaseBillResource extends Resource
{
    use \App\Filament\Concerns\InInventoryPanel;

    protected static ?string $model = PurchaseBill::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Pembelian';

    protected static ?string $navigationLabel = 'Tagihan Pembelian';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Tagihan Pembelian';

    protected static ?string $pluralModelLabel = 'Tagihan Pembelian';

    protected static ?string $recordTitleAttribute = 'number';

    /** Badge sidebar: tagihan yang masih di tangan gudang (draft / dikembalikan). */
    public static function getNavigationBadge(): ?string
    {
        $count = PurchaseBill::query()->whereIn('status', [PurchaseBill::STATUS_DRAFT, PurchaseBill::STATUS_RETURNED])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return PurchaseBill::query()->where('status', PurchaseBill::STATUS_RETURNED)->exists() ? 'danger' : 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Tagihan yang belum diajukan ke accounting atau dikembalikan.';
    }

    /** Form detail tagihan: yang boleh dilengkapi gudang sebelum diajukan. */
    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Nota belanja')
                ->description('Lampirkan foto/scan nota supaya accounting bisa mencocokkan sebelum membayar.')
                ->columns(2)
                ->schema([
                    SupplierResource::picker()
                        ->helperText('Toko / supplier tempat belanja.'),

                    Forms\Components\FileUpload::make('receipt_path')
                        ->label('Foto / scan nota')
                        ->disk('local')
                        ->directory('purchase-bills')
                        ->visibility('private')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                        ->maxSize(5120)
                        ->downloadable(false)
                        ->openable(false)
                        ->helperText('JPG, PNG, WebP, atau PDF; maks. 5 MB.'),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan untuk accounting')
                        ->rows(2)
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Form belanja lepas: barang yang dibeli di luar SPK Produksi. Tiap baris
     * langsung masuk stok saat disimpan (PurchaseBillService::createStandalone).
     */
    public static function standaloneForm(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Belanja')
                ->columns(3)
                ->schema([
                    Forms\Components\DatePicker::make('bill_date')
                        ->label('Tanggal belanja')
                        ->native(false)
                        ->default(now())
                        ->maxDate(now())
                        ->required(),

                    SupplierResource::picker()->columnSpan(2),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan')
                        ->rows(2)
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Bahan yang dibeli')
                ->description('Jumlah dalam satuan bahan. Barang langsung tercatat masuk di Kartu Stok saat disimpan.')
                ->schema([
                    Forms\Components\Repeater::make('lines')
                        ->label('')
                        ->addActionLabel('Tambah bahan')
                        ->minItems(1)
                        ->defaultItems(1)
                        ->columns(6)
                        ->schema([
                            Forms\Components\Select::make('inventory_item_id')
                                ->label('Bahan')
                                ->options(fn () => InventoryItem::query()->ingredients()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                                ->searchable()
                                ->native(false)
                                ->live()
                                ->afterStateUpdated(function ($state, Forms\Set $set) {
                                    $item = $state ? InventoryItem::query()->find($state) : null;
                                    $set('unit', $item?->unit);
                                    $set('unit_cost', $item?->effectiveUnitPrice());
                                })
                                ->required()
                                ->columnSpan(3),

                            Forms\Components\TextInput::make('qty')
                                ->label('Jumlah')
                                ->numeric()
                                ->minValue(0.0001)
                                ->required(),

                            Forms\Components\TextInput::make('unit')
                                ->label('Satuan')
                                ->disabled()
                                ->dehydrated(false),

                            Forms\Components\TextInput::make('unit_cost')
                                ->label('Harga / satuan')
                                ->numeric()
                                ->minValue(0)
                                ->prefix('Rp')
                                ->required()
                                ->helperText(fn (Get $get) => filled($get('qty')) && filled($get('unit_cost'))
                                    ? 'Rp '.number_format((float) $get('qty') * (float) $get('unit_cost'), 0, ',', '.')
                                    : null),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('Nomor')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('bill_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('supplier_name')
                    ->label('Supplier')
                    ->getStateUsing(fn (PurchaseBill $record) => $record->displaySupplier())
                    ->searchable(['supplier_name']),

                TextColumn::make('requisition.number')
                    ->label('Sumber')
                    ->placeholder('Belanja lepas')
                    ->color('gray'),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('IDR', locale: 'id')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => PurchaseBill::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state) => PurchaseBill::statusColor($state)),

                Tables\Columns\IconColumn::make('receipt_path')
                    ->label('Nota')
                    ->boolean()
                    ->getStateUsing(fn (PurchaseBill $record) => filled($record->receipt_path))
                    ->trueIcon('heroicon-o-paper-clip')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('gray')
                    ->falseColor('gray')
                    ->tooltip(fn (PurchaseBill $record) => filled($record->receipt_path) ? 'Nota terlampir' : 'Belum ada foto nota'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(PurchaseBill::statusOptions()),
            ])
            // Selalu bisa dibuka (lihat); mengubah hanya selama di tangan gudang.
            ->actions([
                Tables\Actions\Action::make('buka')
                    ->label('Buka')
                    ->icon('heroicon-m-eye')
                    ->iconButton()
                    ->tooltip('Buka')
                    ->url(fn (PurchaseBill $record) => static::getUrl('edit', ['record' => $record])),
            ])
            ->recordUrl(fn (PurchaseBill $record) => static::getUrl('edit', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseBills::route('/'),
            'create' => Pages\CreatePurchaseBill::route('/belanja-lepas'),
            'edit' => Pages\EditPurchaseBill::route('/{record}'),
        ];
    }
}
