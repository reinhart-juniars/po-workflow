<?php

namespace App\Filament\Menu\Resources\InventoryUnitConversionResource\Pages;

use App\Filament\Menu\Resources\InventoryUnitConversionResource;
use App\Services\MissingUnitConversionScanner;
use App\Support\Units\PackSizeHint;
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

    /** @return array{pasangan: int, baris: int, usulan: int, bahan: int} */
    public function getSummary(): array
    {
        $rows = $this->getRows();

        return [
            'pasangan' => $rows->count(),
            'baris' => (int) $rows->sum('line_count'),
            'usulan' => $rows->filter(fn (array $row) => $row['hint'] !== null)->count(),
            'bahan' => $rows->pluck('inventory_item_id')->unique()->count(),
        ];
    }

    /**
     * Tautan ke form aturan baru yang sudah terisi bahan dan pasangan satuannya.
     *
     * Bila nama bahan menyebut isi kemasannya sendiri, angkanya ikut diusulkan
     * -- tetap sebagai isian awal yang bisa diubah, bukan aturan yang tersimpan
     * sendiri, karena nama bahan adalah teks bebas.
     */
    public function createUrl(array $row): string
    {
        $hint = $row['hint'] ?? null;

        return InventoryUnitConversionResource::getUrl('create', $hint ? [
            'inventory_item_id' => $row['inventory_item_id'],
            'from_unit' => $hint['from_unit'],
            'to_unit' => $hint['to_unit'],
            'factor' => $hint['factor'],
        ] : [
            'inventory_item_id' => $row['inventory_item_id'],
            'from_unit' => $row['from_unit'],
            'to_unit' => $row['to_unit'],
        ]);
    }

    /** Bacaan usulan untuk sebuah baris, bila ada. */
    public function hintText(array $row): ?string
    {
        return ($row['hint'] ?? null) ? PackSizeHint::describe($row['hint']) : null;
    }
}
