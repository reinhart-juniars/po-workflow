<?php

namespace App\Filament\Resources\SupplierResource\RelationManagers;

use App\Models\InventoryPurchase;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Riwayat pembelian dari supplier ini. Hanya baca: pembelian lahir dan
 * dikoreksi lewat menu Pembelian Bahan Baku / Pengeluaran, yang sekaligus
 * menjaga kas keluar atau hutangnya.
 */
class PurchasesRelationManager extends RelationManager
{
    protected static string $relationship = 'purchases';

    protected static ?string $title = 'Riwayat Pembelian';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('item'))
            ->columns([
                TextColumn::make('transaction_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('item.name')
                    ->label('Bahan')
                    ->searchable(),

                TextColumn::make('qty')
                    ->label('Qty')
                    ->numeric(decimalPlaces: 2, locale: 'id'),

                TextColumn::make('total_value')
                    ->label('Nilai')
                    ->money('IDR', locale: 'id')
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
                    ->color(fn (?string $state) => $state === InventoryPurchase::CONDITION_DAMAGED ? 'danger' : 'success'),
            ])
            ->defaultSort('transaction_date', 'desc');
    }
}
