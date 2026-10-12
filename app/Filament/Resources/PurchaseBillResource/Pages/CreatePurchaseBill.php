<?php

namespace App\Filament\Resources\PurchaseBillResource\Pages;

use App\Filament\Resources\PurchaseBillResource;
use App\Services\PurchaseBillService;
use Filament\Forms\Form;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Belanja lepas: belanja di luar SPK Produksi (stok umum, kebutuhan
 * mendadak). Barangnya langsung masuk stok, tagihannya lahir sebagai draft.
 */
class CreatePurchaseBill extends CreateRecord
{
    protected static string $resource = PurchaseBillResource::class;

    protected static ?string $title = 'Belanja Lepas';

    protected static bool $canCreateAnother = false;

    public function form(Form $form): Form
    {
        return PurchaseBillResource::standaloneForm($form);
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(PurchaseBillService::class)->createStandalone($data, auth()->id());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Belanja tercatat dan masuk stok. Lampirkan nota lalu ajukan ke accounting.';
    }

    protected function getRedirectUrl(): string
    {
        return PurchaseBillResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
