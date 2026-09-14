<?php

namespace App\Filament\Pages;

use App\Services\ProductionUsageService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Perbandingan HPP: residual opname vs resep x produksi.
 *
 * Inilah pembuktian yang diminta DoD Phase 3: sebelum opname dipensiunkan
 * sebagai sumber HPP, kedua angka harus dibandingkan pada beberapa periode
 * dan selisihnya bisa dijelaskan. Halaman ini tidak memutuskan apa-apa --
 * ia hanya menaruh keduanya berdampingan.
 */
class HppComparisonReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Produksi';

    protected static ?string $navigationLabel = 'Perbandingan HPP';

    protected static ?int $navigationSort = 40;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ledger.view') ?? false;
    }

    protected static string $view = 'filament.pages.hpp-comparison-report';

    protected static ?string $title = 'Perbandingan HPP: Resep vs Opname';

    /** @var array<string, mixed> */
    public ?array $data = [];

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

    /** @return Collection<int, array<string, mixed>> */
    public function getRows(): Collection
    {
        $from = Carbon::parse($this->data['date_from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['date_to'] ?? now()->endOfMonth());

        if ($to->lt($from)) {
            return collect();
        }

        return app(ProductionUsageService::class)->compareAll($from, $to);
    }
}
