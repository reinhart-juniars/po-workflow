<?php

namespace App\Filament\Resources\InventoryPurchaseResource\Pages;

use App\Filament\Resources\InventoryPurchaseResource;
use App\Models\InventoryPurchase;
use App\Services\InventoryPurchaseFlowService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditInventoryPurchase extends EditRecord
{
    protected static string $resource = InventoryPurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Hapus')
                ->visible(fn () => Auth::user()?->hasAnyRole(['owner', 'superadmin']) ?? false),
        ];
    }

    /**
     * Nilai pembelian dan pasangan jurnalnya tidak tersimpan di tabel pembelian
     * saja, jadi form diisi dari CashOut/Payable yang menempel.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var InventoryPurchase $record */
        $record = $this->getRecord();
        $record->loadMissing(['cashOut', 'payable']);

        $data['total_cost'] = (float) $record->total_value;
        $data['expense_category_id'] = $record->cashOut?->expense_category_id;
        $data['cash_account_id'] = $record->cashOut?->cash_account_id;
        $data['due_date'] = $record->payable?->due_date;

        return $data;
    }

    /**
     * Penyimpanan dialihkan ke service yang sama dengan modul Blade, supaya
     * CashOut/Payable terbentuk dan terlepas dengan aturan yang identik.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var InventoryPurchase $record */
        return app(InventoryPurchaseFlowService::class)->update($record, $data, Auth::id());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
