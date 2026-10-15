<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ProductionOrderResource;
use App\Models\ProductionOrder;
use App\Models\ReadyStockClosing;
use App\Services\ReadyStockService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Rencana Masak (revisi v3.1): satu halaman yang dibaca gudang dan admin.
 *
 * - Pesanan PO per tanggal & menu -- gudang melihat PO yang sudah masuk.
 * - Stok siap: disiapkan / terdistribusi ke PO / sisa per menu (KPI).
 * - Rencana belanja: Form Kebutuhan dari rencana masak, dijumlahkan per bahan.
 *
 * Rentang bawaan H-2 s/d H+7: rencana biasanya disusun H-2 s/d H-7, dan hari
 * yang baru lewat perlu terlihat untuk ditutup.
 */
class ReadyStockPlan extends Page implements HasForms
{
    use \App\Filament\Concerns\PageInPanel;

    protected static string $ownerPanel = 'admin';

    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Produksi';

    protected static ?string $navigationLabel = 'Rencana Masak';

    protected static ?int $navigationSort = 29;

    protected static ?string $slug = 'rencana-masak';

    protected static string $view = 'filament.pages.ready-stock-plan';

    protected static ?string $title = 'Rencana Masak & Belanja';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('plan.manage') || $user->can('production.view'));
    }

    public function mount(): void
    {
        $this->form->fill([
            // H-2: hari yang baru selesai (siap ditutup) tetap terlihat.
            'date_from' => now()->subDays(2)->toDateString(),
            'date_to' => now()->addDays(7)->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(2)->schema([
                    DatePicker::make('date_from')->label('Dari tanggal')->required()->native(false)->displayFormat('d/m/Y')->live(),
                    DatePicker::make('date_to')->label('Sampai')->required()->native(false)->displayFormat('d/m/Y')->live(),
                ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('impor')
                ->label('Impor Excel')
                ->icon('heroicon-m-arrow-up-tray')
                ->color('gray')
                ->visible(fn () => auth()->user()?->can('plan.manage') ?? false)
                ->url(ImportReadyStockPlan::getUrl()),

            Action::make('buat')
                ->label('Buat Rencana Masak')
                ->icon('heroicon-m-plus')
                ->visible(fn () => auth()->user()?->can('plan.manage') ?? false)
                ->url(ProductionOrderResource::getUrl('create')),
        ];
    }

    /**
     * Tutup stok siap satu tanggal (sisa -> Barang Sisa). Dipanggil dari tabel
     * "Tutup Stok Siap" dengan argumen tanggal.
     */
    public function closeDayAction(): Action
    {
        return Action::make('closeDay')
            ->label('Tutup Stok Siap')
            ->icon('heroicon-m-lock-closed')
            ->color('warning')
            ->size('sm')
            ->authorize(fn () => auth()->user()?->can('plan.manage') ?? false)
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => 'Tutup stok siap '.Carbon::parse($arguments['date'])->translatedFormat('d M Y').'?')
            ->modalDescription('Porsi yang belum terdistribusi ke PO menjadi Barang Sisa (dinilai HPP resep) dan bisa dijual ke customer lain dari halaman Barang Sisa. Setelah ditutup, PO tanggal ini tidak bisa mengambil stok siap lagi.')
            ->action(function (array $arguments) {
                try {
                    $closing = app(ReadyStockService::class)->closeDay(Carbon::parse($arguments['date']), auth()->user());
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Belum bisa ditutup')->body(collect($e->errors())->flatten()->first())->send();

                    return;
                }

                Notification::make()->success()
                    ->title('Stok siap ditutup')
                    ->body((float) $closing->leftover_qty > 0
                        ? 'Sisa '.rtrim(rtrim(number_format((float) $closing->leftover_qty, 2, ',', '.'), '0'), ',').' porsi masuk Barang Sisa.'
                        : 'Semua porsi sudah terdistribusi; tidak ada Barang Sisa.')
                    ->send();
            });
    }

    /**
     * Tanggal dalam rentang yang punya Rencana Masak, dengan status penutupannya.
     *
     * @return Collection<int, array{date: string, plans: Collection, closing: ?ReadyStockClosing}>
     */
    public function getDays(): Collection
    {
        [$from, $to] = $this->getRange();

        return ProductionOrder::query()->readyStock()
            ->whereBetween('production_date', [$from->toDateString(), $to->toDateString()])
            ->where('status', '!=', ProductionOrder::STATUS_CANCELLED)
            ->orderBy('production_date')
            ->get()
            ->groupBy(fn (ProductionOrder $plan) => $plan->production_date->toDateString())
            ->map(fn (Collection $plans, string $date) => [
                'date' => $date,
                'plans' => $plans,
                'closing' => ReadyStockClosing::query()->whereDate('production_date', $date)->first(),
            ])
            ->values();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function getRange(): array
    {
        $from = Carbon::parse($this->data['date_from'] ?? now())->startOfDay();
        $to = Carbon::parse($this->data['date_to'] ?? now()->addDays(7))->startOfDay();

        // Rentang terbalik ditukar, dan dibatasi 31 hari supaya halaman tetap ringan.
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to->gt($from->copy()->addDays(31)) ? $from->copy()->addDays(31) : $to];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getStock(): Collection
    {
        return app(ReadyStockService::class)->summary(...$this->getRange());
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getDemand(): Collection
    {
        return app(ReadyStockService::class)->orderDemand(...$this->getRange());
    }

    /** @return array{items: Collection, plans: Collection} */
    public function getShopping(): array
    {
        return app(ReadyStockService::class)->shoppingPlan(...$this->getRange());
    }
}
