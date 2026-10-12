<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Pages;
use App\Filament\Resources\SupplierResource\RelationManagers;
use App\Models\InventoryItem;
use App\Models\Supplier;
use Closure;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Master Supplier bahan baku & kemasan.
 *
 * Pembelian bahan, form kebutuhan, dan hutang memilih supplier dari sini;
 * nama supplier tetap disalin ke transaksinya (lihat LinksSupplier).
 */
class SupplierResource extends Resource
{
    use \App\Filament\Concerns\InInventoryPanel;

    protected static ?string $model = Supplier::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Pembelian';

    protected static ?string $navigationLabel = 'Master Supplier';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Supplier';

    protected static ?string $pluralModelLabel = 'Supplier';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identitas')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Supplier')
                            ->required()
                            ->maxLength(255)
                            ->autofocus()
                            ->rules([fn (?Supplier $record): Closure => static::uniqueNameRule($record)]),

                        Forms\Components\TextInput::make('contact_person')
                            ->label('Nama Kontak')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('phone')
                            ->label('Telepon / WhatsApp')
                            ->tel()
                            ->maxLength(50),

                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),

                        Forms\Components\Textarea::make('address')
                            ->label('Alamat')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Pembayaran')
                    ->schema([
                        Forms\Components\TextInput::make('bank_account')
                            ->label('Rekening Bank')
                            ->maxLength(255)
                            ->placeholder('mis. BCA 1234567890 a.n. Budi Santoso'),

                        Forms\Components\TextInput::make('payment_term_days')
                            ->label('Termin Bayar')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(365)
                            ->suffix('hari')
                            ->helperText('Untuk pembelian kredit: jatuh tempo yang dikosongkan diisi tanggal beli + termin ini.'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Bahan yang Dipasok')
                    ->description('Bahan yang pernah dibeli dari supplier ini otomatis ikut tercatat di sini.')
                    ->schema([
                        Forms\Components\Select::make('items')
                            ->label('Bahan')
                            ->relationship(
                                name: 'items',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->whereIn('category', InventoryItem::stockCategories())
                                    ->orderBy('name'),
                            )
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Textarea::make('notes')
                    ->label('Catatan')
                    ->rows(2)
                    ->columnSpanFull(),

                Forms\Components\Toggle::make('is_active')
                    ->label('Aktif')
                    ->helperText('Supplier nonaktif tidak muncul di pilihan transaksi baru, tetapi riwayatnya tetap ada.')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount('items')
                ->withSum('purchases', 'total_value')
                ->withMax('purchases', 'transaction_date'))
            ->columns([
                TextColumn::make('name')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (Supplier $record) => $record->contact_person),

                TextColumn::make('phone')
                    ->label('Telepon')
                    ->searchable()
                    ->placeholder('-'),

                TextColumn::make('items_count')
                    ->label('Bahan')
                    ->suffix(' bahan')
                    ->sortable(),

                TextColumn::make('purchases_sum_total_value')
                    ->label('Total Pembelian')
                    ->money('IDR', locale: 'id')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('purchases_max_transaction_date')
                    ->label('Beli Terakhir')
                    ->date('d M Y')
                    ->placeholder('Belum pernah')
                    ->sortable(),

                TextColumn::make('payment_term_days')
                    ->label('Termin')
                    ->suffix(' hari')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('bank_account')
                    ->label('Rekening')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif')
                    ->default(true),

                Tables\Filters\SelectFilter::make('bahan')
                    ->label('Memasok Bahan')
                    ->relationship('items', 'name', fn (Builder $query) => $query->whereIn('category', InventoryItem::stockCategories()))
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Edit'),
                Tables\Actions\DeleteAction::make()
                    ->label('Hapus')
                    // Supplier yang sudah bertransaksi dinonaktifkan saja; FK
                    // di database juga menolak penghapusannya.
                    ->before(function (Supplier $record, Tables\Actions\DeleteAction $action) {
                        $blockers = $record->transactionBlockers();

                        if ($blockers !== []) {
                            Notification::make()
                                ->danger()
                                ->title('Supplier tidak bisa dihapus')
                                ->body('Supplier sudah dipakai di '.implode(', ', $blockers).'. Nonaktifkan saja supaya tidak muncul di transaksi baru.')
                                ->send();

                            $action->cancel();
                        }
                    }),
            ])
            ->defaultSort('name');
    }

    /**
     * Pilihan supplier untuk form transaksi (pembelian, form kebutuhan).
     *
     * Pengguna yang boleh mengelola supplier bisa menambah supplier baru
     * langsung dari form tanpa pindah halaman.
     */
    public static function picker(string $name = 'supplier_id'): Forms\Components\Select
    {
        return Forms\Components\Select::make($name)
            ->label('Supplier')
            ->options(fn (mixed $state) => Supplier::options(filled($state) ? (int) $state : null))
            ->searchable()
            ->native(false)
            ->placeholder('Pilih supplier')
            ->createOptionForm([
                Forms\Components\TextInput::make('name')
                    ->label('Nama Supplier')
                    ->required()
                    ->maxLength(255)
                    ->rules([fn (): Closure => static::uniqueNameRule(null)]),
                Forms\Components\TextInput::make('phone')
                    ->label('Telepon / WhatsApp')
                    ->tel()
                    ->maxLength(50),
            ])
            ->createOptionAction(fn (FormAction $action) => $action
                ->modalHeading('Tambah Supplier')
                ->visible(fn () => Auth::user()?->can('create', Supplier::class) ?? false))
            ->createOptionUsing(function (array $data): int {
                // Tombolnya hanya tampil bagi yang berizin; pagar diulang di
                // sini karena aksi Livewire bisa dipanggil langsung.
                abort_unless(Auth::user()?->can('create', Supplier::class), 403);

                return Supplier::query()->create([
                    'name' => $data['name'],
                    'phone' => $data['phone'] ?? null,
                    'is_active' => true,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ])->getKey();
            });
    }

    /**
     * Aturan validasi: nama supplier unik tanpa peduli huruf besar/kecil,
     * supaya "Toko Makmur" dan "toko makmur" tidak menjadi dua supplier.
     */
    public static function uniqueNameRule(?Supplier $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record) {
            $existing = Supplier::findByName((string) $value);

            if ($existing && $existing->getKey() !== $record?->getKey()) {
                $fail('Supplier dengan nama ini sudah ada.');
            }
        };
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PurchasesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}
