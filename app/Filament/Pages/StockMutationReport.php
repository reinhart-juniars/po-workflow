<?php

namespace App\Filament\Pages;

use App\Models\InventoryItem;
use App\Services\InventoryUsageService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Laporan mutasi stok.
 *
 * Memakai InventoryUsageService yang sama dengan Laporan Pemakaian Bahan pada
 * modul Blade, supaya angka di kedua tempat tidak pernah berbeda.
 */
class StockMutationReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Laporan Mutasi Stok';

    protected static ?int $navigationSort = 50;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('inventory.view') ?? false;
    }

    protected static string $view = 'filament.pages.stock-mutation-report';

    protected static ?string $title = 'Laporan Mutasi Stok';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'inventory_item_id' => InventoryItem::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->value('id'),
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->endOfMonth()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(3)->schema([
                    Select::make('inventory_item_id')
                        ->label('Item')
                        ->options(fn () => InventoryItem::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->native(false)
                        ->live(),

                    DatePicker::make('date_from')
                        ->label('Dari Tanggal')
                        ->native(false)
                        ->live(),

                    DatePicker::make('date_to')
                        ->label('Sampai Tanggal')
                        ->native(false)
                        ->live(),
                ]),
            ])
            ->statePath('data');
    }

    public function getSelectedItem(): ?InventoryItem
    {
        $id = $this->data['inventory_item_id'] ?? null;

        return $id ? InventoryItem::query()->find($id) : null;
    }

    /** @return array<string, mixed>|null */
    public function getSummary(): ?array
    {
        return $this->report()['summary'] ?? null;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getDetailRows(): Collection
    {
        return collect($this->report()['detail_rows'] ?? []);
    }

    /** @return array<string, mixed> */
    protected function report(): array
    {
        $itemId = $this->data['inventory_item_id'] ?? null;

        if (! $itemId) {
            return [];
        }

        return app(InventoryUsageService::class)->buildItemReport(
            (int) $itemId,
            Carbon::parse($this->data['date_from'] ?? now()->startOfMonth()),
            Carbon::parse($this->data['date_to'] ?? now()->endOfMonth()),
        );
    }
}
