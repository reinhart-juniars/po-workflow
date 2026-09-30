<?php

namespace App\Filament\Pages;

use App\Models\InventoryItem;
use App\Services\InventoryShrinkageService;
use App\Support\Settings\Settings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Susut Bahan: indikator hijau/kuning/merah per bahan, dan pelacakan ke mana
 * susutnya pergi (SPK dan menu yang memakai bahan itu).
 *
 * Permintaan Owner: bahan yang susut (mis. ayam 1 kg dipotong 12, potongan
 * terakhir kecil sehingga opname tidak bulat) harus bisa dilacak, bukan
 * hilang di dalam HPP. Hitungannya di InventoryShrinkageService.
 */
class ShrinkageReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Resep & HPP';

    protected static ?string $navigationLabel = 'Susut Bahan';

    protected static ?int $navigationSort = 45;

    protected static ?string $slug = 'susut-bahan';

    protected static string $view = 'filament.pages.shrinkage-report';

    protected static ?string $title = 'Susut Bahan';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Bahan yang sedang dilacak (rincian SPK & menu). */
    public ?int $selectedItemId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ledger.view') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->endOfMonth()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(2)->schema([
                    DatePicker::make('date_from')->label('Dari')->required()->native(false)->displayFormat('d/m/Y')->live(),
                    DatePicker::make('date_to')->label('Sampai')->required()->native(false)->displayFormat('d/m/Y')->live(),
                ]),
            ])
            ->statePath('data');
    }

    public function selectItem(?int $itemId): void
    {
        $this->selectedItemId = $this->selectedItemId === $itemId ? null : $itemId;
    }

    /** @return array{0: Carbon, 1: Carbon}|null */
    protected function range(): ?array
    {
        $from = Carbon::parse($this->data['date_from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['date_to'] ?? now()->endOfMonth());

        return $to->lt($from) ? null : [$from, $to];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getRows(): Collection
    {
        $range = $this->range();

        return $range ? app(InventoryShrinkageService::class)->report(...$range) : collect();
    }

    /** @return array<string, mixed>|null */
    public function getDetail(): ?array
    {
        $range = $this->range();
        $item = $this->selectedItemId ? InventoryItem::query()->find($this->selectedItemId) : null;

        if (! $range || ! $item) {
            return null;
        }

        return app(InventoryShrinkageService::class)->detail($item, ...$range);
    }

    /** @return array{green: float, yellow: float} */
    public function getThresholds(): array
    {
        $settings = app(Settings::class);

        return [
            'green' => (float) $settings->get('shrinkage.green_max_pct'),
            'yellow' => (float) $settings->get('shrinkage.yellow_max_pct'),
        ];
    }
}
