<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryUnitConversionResource\Pages;
use App\Models\InventoryItem;
use App\Models\InventoryUnitConversion;
use App\Services\MissingUnitConversionScanner;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Aturan konversi satuan per bahan.
 *
 * Diberi menu sendiri, bukan diselipkan ke form bahan: aturannya terus
 * bertambah seiring resep baru masuk, dan yang mengisinya belum tentu orang
 * yang mengurus master bahan.
 */
class InventoryUnitConversionResource extends Resource
{
    protected static ?string $model = InventoryUnitConversion::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Konversi Satuan';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Aturan Konversi';

    protected static ?string $pluralModelLabel = 'Aturan Konversi Satuan';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('inventory_item_id')
                    ->label('Bahan')
                    ->relationship(
                        name: 'item',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($query) => $query->ingredients()->orderBy('name'),
                    )
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live()
                    ->helperText(
                        'Kosongkan untuk membuat aturan umum yang berlaku pada semua bahan. '
                        .'Aturan milik bahan selalu menang atas aturan umum.'
                    )
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('factor')
                    ->label('Faktor')
                    ->numeric()
                    ->required()
                    // Faktor nol atau negatif tidak punya arti dan akan membagi nol
                    // saat aturannya dipakai dari arah sebaliknya.
                    ->minValue(0.000001)
                    ->step('any')
                    ->live(onBlur: true)
                    ->prefix('1 satuan asal =')
                    ->helperText('Contoh: bila 1 pcs ayam beratnya 250 gram, isi 250.'),

                Forms\Components\Select::make('from_unit')
                    ->label('Satuan Asal')
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->live()
                    // Nilai yang dibawa dari halaman "Butuh Aturan" bisa berupa satuan
                    // lepas yang tidak ada di registri; ikut ditawarkan supaya
                    // pengisian dari daftar itu tidak kehilangan satuannya.
                    ->options(fn (?InventoryUnitConversion $record, Get $get) => InventoryUnitConversion::unitOptions($record?->from_unit, $get('from_unit'))),

                Forms\Components\Select::make('to_unit')
                    ->label('Satuan Tujuan')
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->options(fn (?InventoryUnitConversion $record, Get $get) => InventoryUnitConversion::unitOptions($record?->to_unit, $get('to_unit')))
                    ->rules([
                        fn (?InventoryUnitConversion $record, Get $get): Closure => static::uniquePairRule($record, $get),
                        fn (Get $get): Closure => static::differentUnitRule($get),
                    ]),

                Forms\Components\Placeholder::make('ringkasan')
                    ->label('Terbaca sebagai')
                    ->content(fn (Get $get) => static::previewText($get))
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('note')
                    ->label('Catatan')
                    ->maxLength(255)
                    ->helperText('Mis. sumber angkanya: hasil timbang, kemasan pemasok, atau kesepakatan dapur.')
                    ->columnSpanFull(),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('item.name')
                    ->label('Bahan')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Aturan umum')
                    ->description(fn (InventoryUnitConversion $record) => $record->isGlobal()
                        ? 'Berlaku untuk semua bahan'
                        : null),

                TextColumn::make('aturan')
                    ->label('Aturan')
                    ->state(fn (InventoryUnitConversion $record) => $record->summary())
                    ->badge()
                    ->color('info'),

                TextColumn::make('from_unit')
                    ->label('Satuan Asal')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('to_unit')
                    ->label('Satuan Tujuan')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('note')
                    ->label('Catatan')
                    ->wrap()
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('umum')
                    ->label('Hanya aturan umum')
                    ->toggle()
                    ->query(fn ($query) => $query->global()),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Edit'),
                Tables\Actions\DeleteAction::make()->label('Hapus'),
            ])
            ->defaultSort('id', 'desc');
    }

    /** Aturan validasi: satu pasangan satuan hanya boleh punya satu aturan per bahan. */
    protected static function uniquePairRule(?InventoryUnitConversion $record, Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record, $get) {
            $itemId = $get('inventory_item_id') ?: null;

            $duplicate = InventoryUnitConversion::query()
                // whereNull dipakai eksplisit: `where(kolom, null)` menjadi
                // `= NULL` di SQL dan tidak pernah cocok, sehingga aturan umum
                // yang bertabrakan akan lolos diam-diam.
                ->when($itemId === null,
                    fn ($query) => $query->whereNull('inventory_item_id'),
                    fn ($query) => $query->where('inventory_item_id', $itemId))
                ->where('from_unit', $get('from_unit'))
                ->where('to_unit', $value)
                ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                ->exists();

            if ($duplicate) {
                $fail('Aturan untuk pasangan satuan ini sudah ada. Sunting aturan yang lama daripada membuat yang kedua.');
            }
        };
    }

    /** Aturan validasi: satuan asal dan tujuan tidak boleh sama. */
    protected static function differentUnitRule(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get) {
            if (filled($value) && mb_strtolower(trim((string) $value)) === mb_strtolower(trim((string) $get('from_unit')))) {
                $fail('Satuan asal dan tujuan sama; aturan ini tidak mengubah apa pun.');
            }
        };
    }

    protected static function previewText(Get $get): string
    {
        $from = $get('from_unit');
        $to = $get('to_unit');
        $factor = $get('factor');

        if (blank($from) || blank($to) || blank($factor)) {
            return 'Lengkapi satuan dan faktor untuk melihat pembacaannya.';
        }

        $itemName = $get('inventory_item_id')
            ? InventoryItem::query()->whereKey($get('inventory_item_id'))->value('name')
            : null;

        return sprintf(
            '1 %s = %s %s%s (berlaku dua arah)',
            $from,
            rtrim(rtrim(number_format((float) $factor, 6, ',', '.'), '0'), ','),
            $to,
            $itemName ? ' untuk '.$itemName : ' untuk semua bahan',
        );
    }

    public static function getNavigationBadge(): ?string
    {
        $missing = app(MissingUnitConversionScanner::class)->summary();

        return $missing['pasangan'] > 0 ? (string) $missing['pasangan'] : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Pasangan satuan yang masih menghalangi perhitungan HPP.';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryUnitConversions::route('/'),
            'create' => Pages\CreateInventoryUnitConversion::route('/create'),
            'missing' => Pages\MissingUnitConversions::route('/butuh-aturan'),
            'edit' => Pages\EditInventoryUnitConversion::route('/{record}/edit'),
        ];
    }
}
