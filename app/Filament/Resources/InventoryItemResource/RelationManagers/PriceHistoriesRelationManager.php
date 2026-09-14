<?php

namespace App\Filament\Resources\InventoryItemResource\RelationManagers;

use App\Models\InventoryItemPriceHistory;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Histori harga bahan, hanya dibaca: barisnya ditulis otomatis setiap harga
 * berubah (panel, import) atau dibawa dari Master Menu saat migrasi.
 */
class PriceHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'priceHistories';

    protected static ?string $title = 'Histori Harga';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Waktu')->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('action')->label('Perubahan')->badge()
                    ->formatStateUsing(fn (string $state) => InventoryItemPriceHistory::actionLabels()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        InventoryItemPriceHistory::ACTION_NAIK => 'warning',
                        InventoryItemPriceHistory::ACTION_TURUN_DIPAKSA, InventoryItemPriceHistory::ACTION_TURUN_DITOLAK => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('old_unit_price')->label('Harga Satuan Lama')->money('IDR', locale: 'id')->placeholder('-'),
                Tables\Columns\TextColumn::make('new_unit_price')->label('Harga Satuan Baru')->money('IDR', locale: 'id')->placeholder('-'),
                Tables\Columns\TextColumn::make('old_pack_price')->label('Harga Kemasan Lama')->money('IDR', locale: 'id')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('new_pack_price')->label('Harga Kemasan Baru')->money('IDR', locale: 'id')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('source')->label('Sumber')->placeholder('-'),
                Tables\Columns\TextColumn::make('note')->label('Catatan')->placeholder('-')->wrap(),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
