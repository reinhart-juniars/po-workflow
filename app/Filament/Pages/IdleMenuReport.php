<?php

namespace App\Filament\Pages;

use App\Exports\IdleMenusExport;
use App\Services\IdleMenuReportService;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Bagian B.3 -- Menu Tidak Diproduksi: menu aktif yang tidak pernah masuk
 * SPK Produksi dalam rentang yang dipilih (bawaan N bulan terakhir dari
 * Pengaturan). Bahan evaluasi menu: dihentikan, dipromosikan ulang, atau
 * sekadar belum dipetakan ke resep.
 */
class IdleMenuReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-eye-slash';

    protected static ?string $navigationGroup = 'Resep & HPP';

    protected static ?string $navigationLabel = 'Menu Tidak Diproduksi';

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'menu-tidak-diproduksi';

    protected static string $view = 'filament.pages.idle-menu-report';

    protected static ?string $title = 'Menu Tidak Diproduksi';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('recipe.view') ?? false;
    }

    public function mount(): void
    {
        $months = app(IdleMenuReportService::class)->defaultMonths();

        $this->form->fill([
            'date_from' => now()->subMonths($months)->toDateString(),
            'date_to' => now()->toDateString(),
            'active_only' => true,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(3)->schema([
                    DatePicker::make('date_from')->label('Tidak diproduksi sejak')->required()->native(false)->displayFormat('d/m/Y')->live(),
                    DatePicker::make('date_to')->label('Sampai')->required()->native(false)->displayFormat('d/m/Y')->live(),
                    Toggle::make('active_only')->label('Hanya menu aktif')->inline(false)->live(),
                ]),
            ])
            ->statePath('data');
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getRows(): Collection
    {
        $from = Carbon::parse($this->data['date_from'] ?? now()->subMonths(3));
        $to = Carbon::parse($this->data['date_to'] ?? now());

        if ($to->lt($from)) {
            return collect();
        }

        return app(IdleMenuReportService::class)->rows($from, $to, (bool) ($this->data['active_only'] ?? true));
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')
                ->label('Export Excel')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(new IdleMenusExport($this->getRows()), 'menu-tidak-diproduksi-'.now()->format('Ymd_His').'.xlsx')),
        ];
    }
}
