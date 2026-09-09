<?php

namespace App\Filament\Resources\InventoryUnitConversionResource\Pages;

use App\Filament\Resources\InventoryUnitConversionResource;
use App\Services\MissingUnitConversionScanner;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Daftar pasangan satuan yang masih menghalangi perhitungan HPP.
 *
 * Halaman ini menjawab "mulai dari mana": pasangan diurutkan dari yang paling
 * banyak menahan baris resep, jadi beberapa aturan pertama sudah membuka
 * puluhan menu sekaligus.
 */
class MissingUnitConversions extends Page
{
    protected static string $resource = InventoryUnitConversionResource::class;

    protected static string $view = 'filament.resources.inventory-unit-conversions.missing';

    protected static ?string $title = 'Konversi yang Belum Diatur';

    /** @var Collection<int, array<string, mixed>>|null */
    protected ?Collection $rows = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('kembali')
                ->label('Daftar Aturan')
                ->icon('heroicon-m-arrow-uturn-left')
                ->color('gray')
                ->url(InventoryUnitConversionResource::getUrl('index')),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getRows(): Collection
    {
        return $this->rows ??= app(MissingUnitConversionScanner::class)->scan();
    }

    /** @return array{pasangan: int, baris: int, resep: int, bahan: int} */
    public function getSummary(): array
    {
        $rows = $this->getRows();

        return [
            'pasangan' => $rows->count(),
            'baris' => (int) $rows->sum('line_count'),
            // Satu resep bisa muncul di beberapa pasangan sekaligus, jadi angka
            // ini adalah jumlah keterlibatan, bukan jumlah resep unik.
            'resep' => (int) $rows->sum('recipe_count'),
            'bahan' => $rows->pluck('inventory_item_id')->unique()->count(),
        ];
    }

    /** Tautan ke form aturan baru yang sudah terisi bahan dan pasangan satuannya. */
    public function createUrl(array $row): string
    {
        return InventoryUnitConversionResource::getUrl('create', [
            'inventory_item_id' => $row['inventory_item_id'],
            'from_unit' => $row['from_unit'],
            'to_unit' => $row['to_unit'],
        ]);
    }
}
