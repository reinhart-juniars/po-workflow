<?php

namespace App\Filament\Resources\CustomUnitResource\Pages;

use App\Filament\Resources\CustomUnitResource;
use App\Support\Units\Unit;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCustomUnits extends ListRecords
{
    protected static string $resource = CustomUnitResource::class;

    /** Daftar satuan bawaan, supaya gudang tidak menambah yang sudah ada. */
    public function getSubheading(): ?string
    {
        $builtin = collect(Unit::cases())->map(fn (Unit $unit) => $unit->value)->implode(', ');

        return 'Satuan bawaan: '.$builtin.'. Berat (gram/kg), volume (ml/liter), dan panjang (cm/meter) terkonversi otomatis. '
            .'Satuan tambahan di sini tidak terkonversi otomatis; atur hitungannya per bahan di Konversi Satuan (aplikasi Menu).';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Tambah Satuan'),
        ];
    }
}
