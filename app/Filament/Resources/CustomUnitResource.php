<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomUnitResource\Pages;
use App\Models\CustomUnit;
use App\Support\Units\Unit;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Master Satuan: gudang menambah satuan hitung sendiri (roll, karung, ...)
 * tanpa menunggu pengembang sistem. Satuan bawaan -- termasuk cm & meter
 * yang terkonversi otomatis -- ada di App\Support\Units\Unit dan tidak perlu
 * (dan tidak bisa) didaftarkan ulang di sini.
 */
class CustomUnitResource extends Resource
{
    use \App\Filament\Concerns\InInventoryPanel;

    protected static ?string $model = CustomUnit::class;

    protected static ?string $slug = 'master-satuan';

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Master Satuan';

    protected static ?int $navigationSort = 85;

    protected static ?string $modelLabel = 'Satuan';

    protected static ?string $pluralModelLabel = 'Master Satuan';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nama Satuan')
                ->required()
                ->maxLength(30)
                ->placeholder('mis. roll, karung, gulung')
                ->helperText('Ditulis huruf kecil. Setelah dipakai bahan atau resep, nama tidak bisa diganti.')
                // Nama yang sudah dipakai dikunci: baris lama tetap menulis nama lama.
                ->disabled(fn (?CustomUnit $record) => $record !== null && $record->usageCount() > 0)
                ->rule(fn (?CustomUnit $record) => static::uniqueUnitRule($record)),

            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->helperText('Satuan nonaktif tidak muncul di pilihan satuan baru; bahan yang sudah memakainya tetap aman.')
                ->default(true),
        ])->columns(1);
    }

    /**
     * Tolak satuan yang sudah ada -- di Master Satuan (tanpa beda huruf besar
     * kecil) atau sebagai satuan bawaan beserta singkatannya ("m" = Meter).
     */
    public static function uniqueUnitRule(?CustomUnit $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record) {
            $name = CustomUnit::normalize((string) $value);

            // Dicek sesudah dirapikan: spasi di depan/belakang bukan kesalahan.
            if (! preg_match('/^[\pL\pN][\pL\pN .\/-]*$/u', $name)) {
                $fail('Nama satuan hanya boleh huruf, angka, spasi, titik, garis miring, atau tanda hubung.');

                return;
            }

            if ($builtin = Unit::tryFromAlias($name)) {
                $fail('Satuan "'.$name.'" sudah ada bawaan: '.$builtin->label().'. Langsung pilih di daftar satuan.');

                return;
            }

            $taken = CustomUnit::query()
                ->whereRaw('LOWER(name) = ?', [$name])
                ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                ->exists();

            if ($taken) {
                $fail('Satuan "'.$name.'" sudah ada di Master Satuan.');
            }
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Satuan')->searchable()->sortable(),
                TextColumn::make('usage')
                    ->label('Dipakai')
                    ->state(fn (CustomUnit $record) => $record->usageCount())
                    ->formatStateUsing(fn (int $state) => $state > 0 ? number_format($state, 0, ',', '.').' baris' : 'Belum dipakai')
                    ->color(fn (int $state) => $state > 0 ? null : 'gray'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Edit'),
                // Satuan yang sudah dipakai cukup dinonaktifkan; menghapusnya
                // membuat bahan & resep lama menulis satuan yang tidak terdaftar.
                Tables\Actions\DeleteAction::make()->label('Hapus')
                    ->hidden(fn (CustomUnit $record) => $record->usageCount() > 0),
            ])
            ->emptyStateHeading('Belum ada satuan tambahan')
            ->emptyStateDescription('Satuan bawaan (gram, kg, ml, liter, cm, meter, pcs, ikat, dll.) sudah tersedia. Tambahkan di sini hanya satuan yang belum ada.')
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomUnits::route('/'),
            'create' => Pages\CreateCustomUnit::route('/create'),
            'edit' => Pages\EditCustomUnit::route('/{record}/edit'),
        ];
    }
}
