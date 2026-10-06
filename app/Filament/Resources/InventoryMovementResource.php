<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryMovementResource\Pages;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Services\InventoryLedgerService;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Kartu Stok: ledger kuantitas per bahan -- hanya dibaca. Di UI disebut
 * "Kartu Stok" (istilah gudang yang dikenal user), bukan "ledger".
 *
 * Barisnya lahir dari Form Kebutuhan (saldo awal, pembelian), penutupan SPK
 * (pemakaian, penyesuaian) dan Opname Bahan (penyesuaian). Tidak ada tombol
 * tambah/ubah/hapus: ledger yang bisa disunting tangan tidak bisa dipakai
 * membuktikan HPP. Penyesuaian tampil sebagai Barang Hilang / Barang Temuan.
 */
class InventoryMovementResource extends Resource
{
    protected static ?string $model = InventoryMovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'Produksi';

    protected static ?string $navigationLabel = 'Kartu Stok';

    protected static ?int $navigationSort = 50;

    protected static ?string $modelLabel = 'Mutasi Stok';

    protected static ?string $pluralModelLabel = 'Kartu Stok';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('moved_at')
                    ->label('Tanggal')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('item.name')
                    ->label('Bahan')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (?string $state, InventoryMovement $record) => $record->typeLabel())
                    ->color(fn (?string $state, InventoryMovement $record) => match ($state) {
                        InventoryMovement::TYPE_USAGE => 'danger',
                        InventoryMovement::TYPE_ADJUSTMENT => (float) $record->qty < 0 ? 'warning' : 'info',
                        InventoryMovement::TYPE_OPENING => 'gray',
                        default => 'success',
                    }),

                TextColumn::make('qty')
                    ->label('Qty')
                    ->formatStateUsing(fn ($state, InventoryMovement $record) => rtrim(rtrim(number_format((float) $state, 4, ',', '.'), '0'), ',').' '.$record->unit)
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : null)
                    ->alignRight(),

                TextColumn::make('total_value')
                    ->label('Nilai')
                    ->money('IDR', locale: 'id')
                    ->placeholder('-')
                    ->alignRight(),

                // Saldo berjalan dihitung, bukan disimpan: satu-satunya kebenaran
                // adalah jumlah baris ledger.
                TextColumn::make('saldo')
                    ->label('Saldo s/d Tanggal')
                    ->state(fn (InventoryMovement $record) => rtrim(rtrim(number_format(
                        app(InventoryLedgerService::class)->balance($record->inventory_item_id, $record->moved_at->toDateString()),
                        4, ',', '.'), '0'), ',').' '.$record->unit)
                    ->alignRight(),

                TextColumn::make('productionOrder.number')
                    ->label('SPK Produksi')
                    ->placeholder('-')
                    ->searchable(),

                TextColumn::make('notes')
                    ->label('Keterangan')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('inventory_item_id')
                    ->label('Bahan')
                    ->options(fn () => InventoryItem::query()->ingredients()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),

                Tables\Filters\SelectFilter::make('type')
                    ->label('Jenis')
                    ->options(InventoryMovement::typeLabels()),
            ])
            ->defaultSort('moved_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryMovements::route('/'),
        ];
    }
}
