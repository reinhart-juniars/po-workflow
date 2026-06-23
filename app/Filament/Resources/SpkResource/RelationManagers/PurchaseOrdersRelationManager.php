<?php

namespace App\Filament\Resources\SpkResource\RelationManagers;

use App\Support\UiLabel;
use Filament\Tables;
use Filament\Forms;
use Filament\Tables\Table;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Actions\AttachAction;

class PurchaseOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'purchaseOrders';
    protected static ?string $title = 'Purchase Orders';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('po_number')->label('PO')->searchable(),
                TextColumn::make('customer.name')->label('Customer'),
                TextColumn::make('area.name')->label('Area'),
                TextColumn::make('delivery_date')->date()->label('Delivery'),
                TextColumn::make('status')->badge()->colors([
                    'warning' => 'pending',
                    'info'    => 'scheduled',
                    'primary' => 'in_production',
                    'gray'    => 'ready_for_delivery',
                    'success' => 'delivered',
                ])->formatStateUsing(fn (?string $state) => UiLabel::purchaseOrderStatus($state)),
            ])
            ->headerActions([
                AttachAction::make() // attach PO ke SPK
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['po_number'])
                    ->recordSelectOptionsQuery(function ($query) {
                        // Filter PO yang eligible dimasukkan ke SPK
                        return $query->whereIn('status', ['pending','scheduled']);
                    }),
            ])
            ->actions([
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DetachBulkAction::make(),
            ]);
    }
}
