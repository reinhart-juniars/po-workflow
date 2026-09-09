<?php

namespace App\Filament\Resources\InventoryUnitConversionResource\Pages;

use App\Filament\Resources\InventoryUnitConversionResource;
use App\Support\Units\Unit;
use Filament\Resources\Pages\CreateRecord;

class CreateInventoryUnitConversion extends CreateRecord
{
    protected static string $resource = InventoryUnitConversionResource::class;

    /**
     * Isi awal form dari parameter URL.
     *
     * Halaman "Butuh Aturan" mengirim bahan dan pasangan satuannya lewat query
     * string, supaya pengguna tinggal mengisi angkanya saja -- bagian yang
     * paling mudah salah ketik justru nama bahan dan satuannya.
     */
    protected function fillForm(): void
    {
        $this->form->fill(array_filter([
            'inventory_item_id' => request()->integer('inventory_item_id') ?: null,
            'from_unit' => static::canonicalUnit(request()->query('from_unit')),
            'to_unit' => static::canonicalUnit(request()->query('to_unit')),
        ]));
    }

    /**
     * Satuan mentah dari data resep, disamakan dengan nilai pilihan di form.
     *
     * Data menyimpan singkatannya ("gr", "btl"), sedangkan daftar pilihan
     * memakai nilai bakunya ("gram", "botol"). Tanpa disamakan, isian awalnya
     * menunjuk pilihan yang tidak ada dan kolomnya tampil kosong.
     */
    protected static function canonicalUnit(mixed $raw): ?string
    {
        $text = trim((string) $raw);

        if ($text === '') {
            return null;
        }

        return Unit::tryFromAlias($text)?->value ?? $text;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
