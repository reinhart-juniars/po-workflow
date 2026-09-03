<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use App\Services\InventoryStockAlertService;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar item yang nilai stoknya sudah di bawah ambang minimum.
 *
 * Widget disembunyikan sepenuhnya saat tidak ada yang di bawah ambang, supaya
 * dashboard tidak menampilkan kartu kosong yang lama-lama diabaikan orang.
 */
class LowStockAlertWidget extends BaseWidget
{
    protected static ?string $heading = 'Stok di Bawah Minimum';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return app(InventoryStockAlertService::class)->alertCount() > 0;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => InventoryItem::query()
                ->whereIn('id', app(InventoryStockAlertService::class)->alerts()->pluck('item.id'))
                ->orderBy('name'))
            ->columns([
                TextColumn::make('name')
                    ->label('Item')
                    ->searchable(),

                TextColumn::make('category')
                    ->label('Kategori')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => InventoryItem::categoryOptions()[$state] ?? $state),

                TextColumn::make('stock_value')
                    ->label('Nilai Stok')
                    ->state(fn (InventoryItem $record) => app(InventoryStockAlertService::class)
                        ->currentStockValue($record->id)['value'])
                    ->money('idr', true)
                    ->color('danger'),

                TextColumn::make('minimum_stock_value')
                    ->label('Minimum')
                    ->money('idr', true),

                TextColumn::make('shortfall')
                    ->label('Kekurangan')
                    ->state(fn (InventoryItem $record) => round(
                        (float) $record->minimum_stock_value
                            - (float) app(InventoryStockAlertService::class)->currentStockValue($record->id)['value'],
                        2
                    ))
                    ->money('idr', true)
                    ->weight('bold'),
            ])
            ->actions([
                Tables\Actions\Action::make('kelola')
                    ->label('Buka Item')
                    ->url(fn (InventoryItem $record) => InventoryItemResource::getUrl('edit', ['record' => $record]))
                    ->icon('heroicon-m-arrow-top-right-on-square'),
            ])
            ->paginated([5, 10, 25]);
    }
}
