<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RequisitionResource\Pages;
use App\Models\Requisition;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Daftar Form Kebutuhan lintas SPK -- daftar kerja bagi yang menyetujui dan
 * yang memeriksa. Pengisiannya tetap di halaman Form Kebutuhan milik SPK.
 */
class RequisitionResource extends Resource
{
    use \App\Filament\Concerns\InInventoryPanel;

    protected static ?string $model = Requisition::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Produksi';

    protected static ?string $navigationLabel = 'Form Kebutuhan';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'Form Kebutuhan';

    protected static ?string $pluralModelLabel = 'Form Kebutuhan';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Nomor')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('productionOrder.number')
                    ->label('SPK Produksi')
                    ->searchable()
                    ->description(fn (Requisition $record) => $record->productionOrder?->title),

                TextColumn::make('productionOrder.production_date')
                    ->label('Tanggal Produksi')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('lines_count')
                    ->label('Bahan')
                    ->counts('lines')
                    ->alignRight(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Requisition::statusOptions()[$state] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        Requisition::STATUS_CHECKED => 'success',
                        Requisition::STATUS_APPROVED => 'info',
                        Requisition::STATUS_SUBMITTED => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('approvedBy.name')
                    ->label('Disetujui oleh')
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('checkedBy.name')
                    ->label('Diperiksa oleh')
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(Requisition::statusOptions()),
            ])
            ->actions([
                Tables\Actions\Action::make('buka')
                    ->label('Buka')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (Requisition $record) => ProductionOrderResource::getUrl('kebutuhan', ['record' => $record->productionOrder])),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getNavigationBadge(): ?string
    {
        // Yang menunggu tindakan: belum disetujui, atau sudah disetujui tetapi
        // barangnya belum diperiksa.
        $count = Requisition::query()->whereIn('status', [Requisition::STATUS_DRAFT, Requisition::STATUS_SUBMITTED, Requisition::STATUS_APPROVED])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRequisitions::route('/'),
        ];
    }
}
