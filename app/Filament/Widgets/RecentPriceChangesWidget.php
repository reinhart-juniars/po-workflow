<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\InventoryItemResource;
use App\Models\InventoryItemPriceHistory;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/** Perubahan harga bahan terakhir: yang paling sering menggeser HPP tanpa disadari. */
class RecentPriceChangesWidget extends BaseWidget
{
    protected static ?string $heading = 'Perubahan Harga Bahan Terakhir';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('inventory.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(InventoryItemPriceHistory::query()->with('item')->latest('created_at')->limit(8))
            ->paginated(false)
            ->emptyStateHeading('Belum ada perubahan harga')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Waktu')->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('item.name')->label('Bahan')->weight('semibold'),
                Tables\Columns\TextColumn::make('action')->label('Perubahan')->badge()
                    ->formatStateUsing(fn (string $state) => InventoryItemPriceHistory::actionLabels()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        InventoryItemPriceHistory::ACTION_NAIK => 'warning', InventoryItemPriceHistory::ACTION_TURUN_DIPAKSA => 'info', default => 'gray'
                    }),
                Tables\Columns\TextColumn::make('old_unit_price')->label('Lama')->money('IDR', locale: 'id')->placeholder('-'),
                Tables\Columns\TextColumn::make('new_unit_price')->label('Baru')->money('IDR', locale: 'id')->placeholder('-'),
                Tables\Columns\TextColumn::make('source')->label('Sumber')->placeholder('-'),
            ])
            ->recordUrl(fn (InventoryItemPriceHistory $r) => $r->inventory_item_id ? InventoryItemResource::getUrl('edit', ['record' => $r->inventory_item_id]) : null);
    }
}
