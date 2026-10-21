<?php

namespace App\Filament\Menu\Resources\InventoryUnitConversionResource\Pages;

use App\Filament\Menu\Resources\InventoryUnitConversionResource;
use App\Models\InventoryItem;
use App\Models\InventoryUnitConversion;
use App\Models\RecipeItem;
use App\Services\MissingUnitConversionScanner;
use App\Support\Units\PackSizeHint;
use App\Support\Units\Unit;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
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

    /**
     * Isi satuan baris resep yang kosong ("-") untuk satu bahan.
     *
     * Baris tanpa satuan tidak bisa dibereskan dengan aturan konversi: tidak
     * ada satuan asal yang bisa dijembatani. Yang kurang adalah satuan di baris
     * resepnya sendiri, jadi itu yang diisi -- sekaligus untuk semua baris
     * bahan itu. Bila satuannya sama dengan satuan harga, barisnya langsung
     * terhitung; bila beda, pasangannya muncul lagi di daftar ini untuk dibuat
     * aturannya.
     */
    public function isiSatuanAction(): Actions\Action
    {
        return Actions\Action::make('isiSatuan')
            ->label('Isi satuan resep')
            ->link()
            ->size('sm')
            ->authorize('recipe.manage')
            ->modalHeading(fn (array $arguments) => 'Isi satuan resep: '.InventoryItem::query()->whereKey($arguments['item'] ?? 0)->value('name'))
            ->modalDescription(fn (array $arguments) => sprintf(
                '%d baris resep memakai bahan ini tanpa satuan. Satuan yang diisi berlaku untuk semua baris itu; takarannya tidak berubah.',
                static::blankUnitLines((int) ($arguments['item'] ?? 0))->count(),
            ))
            ->modalSubmitActionLabel('Simpan Satuan')
            ->form(fn (array $arguments) => [
                Forms\Components\TextInput::make('unit')
                    ->label('Satuan di resep')
                    ->required()
                    ->maxLength(50)
                    ->datalist(fn () => InventoryUnitConversion::unitSuggestions())
                    ->default(InventoryItem::query()->whereKey($arguments['item'] ?? 0)->value('unit'))
                    ->helperText('Bawaannya satuan harga bahan ini. Ketik satuan lain bila takaran di resep memakai satuan berbeda.'),
            ])
            ->action(function (array $data, array $arguments) {
                $item = InventoryItem::query()->findOrFail((int) ($arguments['item'] ?? 0));
                $unit = Unit::canonical(trim((string) $data['unit']));
                $lines = static::blankUnitLines($item->id);

                // Lewat model, bukan update massal: ejaan satuan disamakan dan
                // HPP produk yang mengikuti resep dihitung ulang (RecipeItem::booted).
                $lines->each(fn (RecipeItem $line) => $line->update(['unit' => $unit]));

                $this->rows = null;

                Notification::make()->success()
                    ->title($lines->count().' baris resep '.$item->name.' kini bersatuan '.$unit)
                    ->body(Unit::canonical($item->unit) === $unit
                        ? 'Sama dengan satuan harganya; barisnya langsung terhitung.'
                        : 'Beda dengan satuan harga ('.$item->unit.'); buat aturan konversinya di daftar ini.')
                    ->send();
            });
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, RecipeItem> */
    protected static function blankUnitLines(int $itemId)
    {
        return RecipeItem::query()
            ->where('inventory_item_id', $itemId)
            ->where(fn ($query) => $query->whereNull('unit')->orWhere('unit', ''))
            ->get();
    }

    /** Tautan ke aturan tersimpan yang satuannya belum nyambung. */
    public function editUrl(int $ruleId): string
    {
        return InventoryUnitConversionResource::getUrl('edit', ['record' => $ruleId]);
    }

    /** Bacaan usulan untuk sebuah baris, bila ada. */
    public function hintText(array $row): ?string
    {
        return ($row['hint'] ?? null) ? PackSizeHint::describe($row['hint']) : null;
    }
}
