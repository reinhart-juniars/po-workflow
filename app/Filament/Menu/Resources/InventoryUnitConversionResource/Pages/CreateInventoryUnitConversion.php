<?php

namespace App\Filament\Menu\Resources\InventoryUnitConversionResource\Pages;

use App\Filament\Menu\Resources\InventoryUnitConversionResource;
use App\Support\Units\Unit;
use Filament\Notifications\Notification;
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
            // Usulan angka dari nama bahan. Sengaja hanya mengisi form, bukan
            // menyimpan aturan: nama bahan teks bebas, dan angkanya harus
            // dilihat manusia sebelum ikut menentukan HPP.
            'factor' => (float) request()->query('factor') ?: null,
        ]));

        if ((float) request()->query('factor') > 0) {
            // Peringatannya menyusul ke halaman ini, karena di sinilah orang
            // menekan simpan -- konteks "ini cuma usulan" ada di halaman
            // sebelumnya dan mudah tertinggal.
            Notification::make()
                ->warning()
                ->title('Angka diisi dari nama bahan')
                ->body('Ini bacaan atas teks nama bahan, bukan hasil timbang. Periksa dulu sebelum disimpan; angka ini ikut menentukan HPP.')
                ->send();
        }
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
