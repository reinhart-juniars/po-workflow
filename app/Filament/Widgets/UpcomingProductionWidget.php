<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ProductionOrderResource;
use App\Models\ProductionOrder;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * SPK Produksi yang masih terbuka, terdekat dulu: pintu masuk dapur ke
 * Form Kebutuhan hari ini tanpa harus membuka daftar SPK.
 */
class UpcomingProductionWidget extends BaseWidget
{
    protected static ?string $heading = 'SPK Produksi Terbuka';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('production.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ProductionOrder::query()->open()->with(['spk', 'requisition'])->orderBy('production_date')->orderBy('production_time'))
            ->paginated(false)
            ->defaultPaginationPageOption(8)
            ->emptyStateHeading('Tidak ada SPK Produksi terbuka')
            ->emptyStateDescription('SPK Produksi tersusun otomatis saat Admin membuat slot SPK.')
            ->columns([
                Tables\Columns\TextColumn::make('production_date')->label('Tanggal')->date('d/m/Y')->description(fn (ProductionOrder $r) => $r->production_time ? substr((string) $r->production_time, 0, 5) : null),
                Tables\Columns\TextColumn::make('number')->label('SPK Produksi')->weight('semibold'),
                Tables\Columns\TextColumn::make('spk.spk_code')->label('Slot SPK')->placeholder('manual'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => ProductionOrder::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state) => $state === ProductionOrder::STATUS_PLANNED ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('requisition.status')->label('Form Kebutuhan')->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? (\App\Models\Requisition::statusOptions()[$state] ?? $state) : 'Belum disusun')
                    ->color(fn (?string $state) => match ($state) {
                        'checked' => 'success', 'approved' => 'info', 'draft' => 'warning', default => 'gray'
                    })
                    ->placeholder('Belum disusun'),
            ])
            ->actions([
                Tables\Actions\Action::make('kebutuhan')->label('Form Kebutuhan')->icon('heroicon-m-clipboard-document-check')
                    ->url(fn (ProductionOrder $r) => ProductionOrderResource::getUrl('kebutuhan', ['record' => $r])),
            ])
            ->recordUrl(fn (ProductionOrder $r) => ProductionOrderResource::getUrl('edit', ['record' => $r]));
    }
}
