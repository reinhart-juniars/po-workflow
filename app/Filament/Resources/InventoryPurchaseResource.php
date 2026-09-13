<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryPurchaseResource\Pages;
use App\Models\CashAccount;
use App\Models\ExpenseCategory;
use App\Models\InventoryItem;
use App\Models\InventoryPurchase;
use App\Models\PeriodClosing;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Pembelian bahan baku.
 *
 * Sengaja tanpa halaman Create. Pembelian tidak lahir dari modul ini melainkan
 * dari modul Pengeluaran, yang sekaligus membentuk CashOut atau Payable-nya.
 * Tombol Create di sini akan menghasilkan pembelian tanpa jurnal kas, dan
 * selisihnya baru ketahuan saat tutup buku.
 */
class InventoryPurchaseResource extends Resource
{
    protected static ?string $model = InventoryPurchase::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Pembelian Bahan Baku';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Pembelian Bahan Baku';

    protected static ?string $pluralModelLabel = 'Pembelian Bahan Baku';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Pembelian')
                    ->schema([
                        Forms\Components\Select::make('inventory_item_id')
                            ->label('Item')
                            ->options(fn () => InventoryItem::query()->orderBy('name')->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->native(false),

                        Forms\Components\DatePicker::make('transaction_date')
                            ->label('Tanggal Transaksi')
                            ->required()
                            ->native(false)
                            ->helperText(fn (?string $state) => $state && static::isPeriodClosed($state)
                                ? 'Tanggal ini berada di periode yang sudah ditutup buku.'
                                : null),

                        Forms\Components\TextInput::make('total_cost')
                            ->label('Nilai Pembelian')
                            ->prefix('Rp')
                            ->numeric()
                            ->required()
                            ->minValue(0.01),

                        Forms\Components\Select::make('payment_type')
                            ->label('Jenis Pembayaran')
                            ->options(['cash' => 'Tunai', 'payable' => 'Kredit (Hutang)'])
                            ->required()
                            ->live()
                            ->native(false),

                        Forms\Components\Select::make('requisition_id')
                            ->label('Untuk Form Kebutuhan')
                            ->options(fn () => \App\Models\Requisition::query()
                                ->with('productionOrder')
                                ->orderByDesc('id')
                                ->limit(100)
                                ->get()
                                ->mapWithKeys(fn ($r) => [$r->id => $r->number.' — '.$r->productionOrder?->number.' ('.$r->statusLabel().')'])
                                ->all())
                            ->searchable()
                            ->native(false)
                            ->columnSpanFull()
                            ->helperText('Opsional. Menautkan pembelian ini ke form kebutuhan SPK yang menjadi alasannya, sebagai bukti langkah "Diperiksa saat barang dibeli".'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Tunai')
                    ->schema([
                        Forms\Components\Select::make('expense_category_id')
                            ->label('Kategori Pengeluaran')
                            ->options(fn () => ExpenseCategory::query()
                                ->where('expense_mode', ExpenseCategory::MODE_INVENTORY_PURCHASE)
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->helperText('Hanya kategori bertipe Pembelian Stok yang boleh dipakai.'),

                        Forms\Components\Select::make('cash_account_id')
                            ->label('Akun Kas')
                            ->options(fn () => CashAccount::query()->orderBy('name')->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->native(false),
                    ])
                    ->columns(2)
                    ->visible(fn (Get $get) => $get('payment_type') === 'cash'),

                Forms\Components\Section::make('Kredit')
                    ->schema([
                        Forms\Components\TextInput::make('supplier_name')
                            ->label('Supplier')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\DatePicker::make('due_date')
                            ->label('Jatuh Tempo')
                            ->native(false),
                    ])
                    ->columns(2)
                    ->visible(fn (Get $get) => $get('payment_type') === 'payable'),

                Forms\Components\Section::make('Kondisi Barang Saat Datang')
                    ->description('Barang berkondisi Tidak Baik tidak menambah stok tersedia. Nilainya tetap tercatat, tetapi dipindahkan ke pos Kerugian Barang Rusak di Laba Rugi.')
                    ->schema([
                        Forms\Components\Radio::make('condition')
                            ->label('Kondisi')
                            ->options(InventoryPurchase::conditionOptions())
                            ->default(InventoryPurchase::CONDITION_GOOD)
                            ->required()
                            ->live()
                            ->inline(),

                        Forms\Components\Textarea::make('condition_notes')
                            ->label('Catatan Kondisi')
                            ->rows(2)
                            ->required(fn (Get $get) => $get('condition') === InventoryPurchase::CONDITION_DAMAGED)
                            ->visible(fn (Get $get) => $get('condition') === InventoryPurchase::CONDITION_DAMAGED),
                    ])
                    ->columns(1),

                Forms\Components\Textarea::make('notes')
                    ->label('Catatan')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable()
                    ->description(fn (InventoryPurchase $record) => static::isPeriodClosed($record->transaction_date->toDateString())
                        ? 'Periode tutup buku'
                        : null),

                TextColumn::make('item.name')
                    ->label('Item')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('supplier_name')
                    ->label('Supplier')
                    ->searchable()
                    ->placeholder('-'),

                TextColumn::make('total_value')
                    ->label('Nilai')
                    ->money('idr', true)
                    ->weight(FontWeight::SemiBold)
                    ->sortable(),

                TextColumn::make('payment_type')
                    ->label('Pembayaran')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'cash' ? 'Tunai' : 'Kredit')
                    ->color(fn (string $state) => $state === 'cash' ? 'success' : 'warning'),

                TextColumn::make('condition')
                    ->label('Kondisi')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => InventoryPurchase::conditionOptions()[$state] ?? $state)
                    ->color(fn (?string $state) => $state === InventoryPurchase::CONDITION_DAMAGED ? 'danger' : 'success')
                    ->description(fn (InventoryPurchase $record) => $record->isDamaged() ? $record->condition_notes : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('inventory_item_id')
                    ->label('Item')
                    ->options(fn () => InventoryItem::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),

                Tables\Filters\SelectFilter::make('payment_type')
                    ->label('Pembayaran')
                    ->options(['cash' => 'Tunai', 'payable' => 'Kredit']),

                Tables\Filters\SelectFilter::make('condition')
                    ->label('Kondisi')
                    ->options(InventoryPurchase::conditionOptions()),

                Tables\Filters\Filter::make('periode')
                    ->form([
                        Forms\Components\DatePicker::make('dari')->label('Dari Tanggal')->native(false),
                        Forms\Components\DatePicker::make('sampai')->label('Sampai Tanggal')->native(false),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['dari'] ?? null, fn ($q, $date) => $q->whereDate('transaction_date', '>=', $date))
                        ->when($data['sampai'] ?? null, fn ($q, $date) => $q->whereDate('transaction_date', '<=', $date))),
            ])
            ->actions([
                // Jalur cepat untuk petugas penerimaan: menandai kondisi tanpa
                // membuka form pembelian yang penuh field keuangan.
                Tables\Actions\Action::make('cek_kondisi')
                    ->label('Cek Kondisi')
                    ->icon('heroicon-m-clipboard-document-check')
                    ->form([
                        Forms\Components\Radio::make('condition')
                            ->label('Kondisi Barang Saat Datang')
                            ->options(InventoryPurchase::conditionOptions())
                            ->required()
                            ->live()
                            ->inline(),

                        Forms\Components\Textarea::make('condition_notes')
                            ->label('Catatan Kondisi')
                            ->rows(2)
                            ->required(fn (Get $get) => $get('condition') === InventoryPurchase::CONDITION_DAMAGED)
                            ->visible(fn (Get $get) => $get('condition') === InventoryPurchase::CONDITION_DAMAGED),
                    ])
                    ->fillForm(fn (InventoryPurchase $record) => [
                        'condition' => $record->condition,
                        'condition_notes' => $record->condition_notes,
                    ])
                    ->action(function (InventoryPurchase $record, array $data) {
                        $record->update([
                            'condition' => $data['condition'],
                            'condition_notes' => $data['condition_notes'] ?? null,
                            'condition_checked_at' => now(),
                            'condition_checked_by' => Auth::id(),
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Kondisi barang tersimpan')
                            ->body($data['condition'] === InventoryPurchase::CONDITION_DAMAGED
                                ? 'Barang ditandai Tidak Baik, jadi tidak menambah stok tersedia.'
                                : 'Barang ditandai Baik dan menambah stok tersedia.')
                            ->send();
                    }),

                Tables\Actions\EditAction::make()->label('Edit'),

                Tables\Actions\DeleteAction::make()
                    ->label('Hapus')
                    // Penghapusan ikut membuang CashOut/Payable-nya, jadi hanya
                    // owner dan superadmin -- sama dengan aturan modul Blade.
                    ->visible(fn () => Auth::user()?->hasAnyRole(['owner', 'superadmin']) ?? false),
            ])
            ->defaultSort('transaction_date', 'desc');
    }

    /** Periode akuntansi bulan tersebut sudah ditutup. */
    public static function isPeriodClosed(string $date): bool
    {
        $parsed = Carbon::parse($date);

        return PeriodClosing::query()
            ->where('period_month', $parsed->month)
            ->where('period_year', $parsed->year)
            ->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryPurchases::route('/'),
            'edit' => Pages\EditInventoryPurchase::route('/{record}/edit'),
        ];
    }
}
