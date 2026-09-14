<?php

namespace App\Filament\Resources\ProductionOrderResource\Pages;

use App\Filament\Resources\ProductionOrderResource;
use App\Models\ProductionOrder;
use App\Models\Requisition;
use App\Models\RequisitionLine;
use App\Services\ProductionCompletionService;
use App\Services\ProductionDocumentService;
use App\Services\ProductionOrderService;
use App\Services\RequisitionService;
use Filament\Actions;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Throwable;

/**
 * Form Kebutuhan, Stok & Pembelian Barang untuk satu SPK Produksi.
 *
 * Satu halaman untuk seluruh daur hidup form: menyusun, mengisi Stok Awal,
 * menyetujui, memeriksa, mencatat pemakaian aktual, lalu menutup SPK. Kolom
 * mana yang bisa diisi mengikuti status form -- angka yang sudah disetujui
 * tidak bisa diubah diam-diam dari halaman ini.
 */
class RequisitionForm extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = ProductionOrderResource::class;

    protected static string $view = 'filament.resources.production-orders.requisition';

    protected static ?string $title = 'Form Kebutuhan';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->fillFromRequisition();
    }

    public function getTitle(): string
    {
        return 'Form Kebutuhan — '.$this->getOrder()->number;
    }

    public function getOrder(): ProductionOrder
    {
        /** @var ProductionOrder $record */
        $record = $this->getRecord();

        return $record;
    }

    public function getRequisition(): ?Requisition
    {
        return $this->getOrder()->requisition()->with('lines.item')->first();
    }

    /** @return array<string, mixed> */
    public function getRequirementsPreview(): array
    {
        return app(ProductionOrderService::class)->requirements($this->getOrder()->fresh(['lines']));
    }

    protected function fillFromRequisition(): void
    {
        $requisition = $this->getRequisition();

        $this->form->fill([
            'lines' => $requisition
                ? $requisition->lines->map(fn (RequisitionLine $line) => [
                    'id' => $line->id,
                    'name' => $line->name,
                    'unit' => $line->unit,
                    'required_qty' => (float) $line->required_qty,
                    'opening_stock_qty' => $line->opening_stock_qty === null ? null : (float) $line->opening_stock_qty,
                    'purchase_qty' => $line->purchase_qty === null ? null : (float) $line->purchase_qty,
                    'unit_price' => $line->unit_price === null ? null : (float) $line->unit_price,
                    'actual_used_qty' => $line->actual_used_qty === null ? null : (float) $line->actual_used_qty,
                    'remaining_qty' => $line->remaining_qty === null ? null : (float) $line->remaining_qty,
                    'notes' => $line->notes,
                ])->values()->all()
                : [],
        ]);
    }

    public function form(Form $form): Form
    {
        $requisition = $this->getRequisition();
        $order = $this->getOrder();
        $draft = $requisition?->isDraft() ?? false;
        $actualsOpen = ($requisition?->isChecked() ?? false) && ! $order->isCompleted();

        return $form
            ->schema([
                Repeater::make('lines')
                    ->label('')
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->collapsible(false)
                    ->itemLabel(fn (array $state) => $state['name'] ?? '')
                    ->schema([
                        \Filament\Forms\Components\Hidden::make('id'),

                        Placeholder::make('kebutuhan')
                            ->label('Kebutuhan')
                            ->content(fn ($get) => static::qty($get('required_qty')).' '.$get('unit')),

                        TextInput::make('opening_stock_qty')
                            ->label('Stok Awal')
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->suffix(fn ($get) => $get('unit'))
                            ->disabled(! $draft)
                            ->dehydrated($draft)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, $get, $set) {
                                // Rumus form kertas: Beli = Kebutuhan - Stok Awal.
                                $set('purchase_qty', round(max((float) $get('required_qty') - (float) $state, 0), 4));
                            }),

                        TextInput::make('purchase_qty')
                            ->label('Beli')
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->suffix(fn ($get) => $get('unit'))
                            ->disabled(! $draft)
                            ->dehydrated($draft)
                            ->helperText($draft ? 'Boleh dibulatkan ke kemasan.' : null),

                        Placeholder::make('harga')
                            ->label('Harga Satuan')
                            ->content(fn ($get) => $get('unit_price') === null ? '-' : 'Rp '.number_format((float) $get('unit_price'), 2, ',', '.')),

                        TextInput::make('actual_used_qty')
                            ->label('Pemakaian Aktual')
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->suffix(fn ($get) => $get('unit'))
                            ->placeholder('= kebutuhan')
                            ->disabled(! $actualsOpen)
                            ->dehydrated($actualsOpen),

                        TextInput::make('remaining_qty')
                            ->label('Sisa Stok')
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->suffix(fn ($get) => $get('unit'))
                            ->placeholder('tidak dihitung')
                            ->disabled(! $actualsOpen)
                            ->dehydrated($actualsOpen),

                        TextInput::make('notes')
                            ->label('Catatan')
                            ->maxLength(255)
                            ->disabled(! ($draft || $actualsOpen))
                            ->dehydrated($draft || $actualsOpen),
                    ])
                    ->columns(7),
            ])
            ->statePath('data');
    }

    /** Simpan isian sesuai tahap form saat ini. */
    public function save(): void
    {
        abort_unless(auth()->user()?->can('production.manage'), 403);

        $requisition = $this->getRequisition();

        if (! $requisition) {
            return;
        }

        $service = app(RequisitionService::class);
        $completion = app(ProductionCompletionService::class);
        $state = $this->form->getState();

        try {
            foreach ($state['lines'] ?? [] as $row) {
                $line = $requisition->lines->firstWhere('id', (int) ($row['id'] ?? 0));

                if (! $line) {
                    continue;
                }

                if ($requisition->isDraft()) {
                    $service->fillOpeningStock($line, static::number($row['opening_stock_qty'] ?? null));

                    if (($row['purchase_qty'] ?? null) !== null && $row['purchase_qty'] !== '') {
                        $service->overridePurchaseQty($line->fresh(), (float) $row['purchase_qty']);
                    }

                    $line->fresh()->update(['notes' => $row['notes'] ?? null]);
                } elseif ($requisition->isChecked() && ! $this->getOrder()->isCompleted()) {
                    $completion->recordActuals(
                        $line,
                        static::number($row['actual_used_qty'] ?? null),
                        static::number($row['remaining_qty'] ?? null),
                    );
                    $line->fresh()->update(['notes' => $row['notes'] ?? null]);
                }
            }
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Tidak tersimpan')->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Isian tersimpan')->send();
        $this->fillFromRequisition();
    }

    /**
     * Tombol tahap dibaca dari status TERKINI setiap kali dirender, bukan dari
     * status saat halaman dibuka: setelah "Susun" atau "Setujui" halaman yang
     * sama harus langsung menampilkan tombol tahap berikutnya.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('susun')
                ->label(fn () => $this->getRequisition() ? 'Segarkan Kebutuhan' : 'Susun Form')
                ->authorize('production.manage')
                ->icon('heroicon-m-calculator')
                ->color(fn () => $this->getRequisition() ? 'gray' : 'primary')
                ->visible(function () {
                    $order = $this->getOrder()->fresh();
                    $requisition = $this->getRequisition();

                    return ! $order->isCompleted() && ! $order->isCancelled() && ($requisition === null || $requisition->isDraft());
                })
                ->action(function () {
                    try {
                        $result = app(RequisitionService::class)->build($this->getOrder()->fresh(), auth()->id());
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Gagal menyusun form')->body($e->getMessage())->send();

                        return;
                    }

                    $notification = Notification::make()->title($result['requisition']->number.' tersusun')
                        ->body($result['requisition']->lines->count().' bahan.');

                    // Masalah perhitungan disampaikan di sini, bukan disembunyikan:
                    // baris yang tidak terhitung berarti kebutuhan yang kurang.
                    if ($result['issues'] !== []) {
                        $notification->warning()->body(implode("\n", array_slice($result['issues'], 0, 5)))->persistent();
                    } else {
                        $notification->success();
                    }

                    $notification->send();
                    $this->fillFromRequisition();
                }),

            Actions\Action::make('simpan')
                ->label('Simpan Isian')
                ->icon('heroicon-m-check')
                ->authorize('production.manage')
                ->visible(function () {
                    $requisition = $this->getRequisition();

                    return $requisition !== null
                        && ($requisition->isDraft() || ($requisition->isChecked() && ! $this->getOrder()->fresh()->isCompleted()));
                })
                ->action(fn () => $this->save()),

            Actions\Action::make('setujui')
                ->label('Setujui')
                ->authorize('requisition.approve')
                ->icon('heroicon-m-hand-thumb-up')
                ->color('info')
                ->visible(fn () => $this->getRequisition()?->isDraft() ?? false)
                ->requiresConfirmation()
                ->modalDescription('Setelah disetujui, Stok Awal dan Beli dikunci.')
                ->action(function () {
                    $this->save();

                    try {
                        app(RequisitionService::class)->approve($this->getRequisition(), auth()->id());
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Belum bisa disetujui')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title('Form disetujui')->send();
                    $this->fillFromRequisition();
                }),

            Actions\Action::make('periksa')
                ->label('Periksa (Barang Dibeli)')
                ->authorize('requisition.check')
                ->icon('heroicon-m-truck')
                ->color('success')
                ->visible(fn () => $this->getRequisition()?->isApproved() ?? false)
                ->requiresConfirmation()
                ->modalDescription('Menandai barang sudah dibeli & diperiksa. Stok Awal (untuk bahan yang belum punya ledger) dan Beli akan dicatat ke ledger stok. Langkah ini tidak bisa diulang.')
                ->action(function () {
                    try {
                        app(RequisitionService::class)->check($this->getRequisition(), auth()->id());
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Gagal memeriksa')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title('Barang tercatat masuk ke ledger')->send();
                    $this->fillFromRequisition();
                }),

            Actions\Action::make('tutup')
                ->label('Tutup SPK (Posting Pemakaian)')
                ->authorize('production.complete')
                ->icon('heroicon-m-lock-closed')
                ->color('warning')
                ->visible(fn () => ($this->getRequisition()?->isChecked() ?? false) && ! $this->getOrder()->fresh()->isCompleted())
                ->requiresConfirmation()
                ->modalDescription('Pemakaian bahan (aktual bila diisi, kebutuhan resep bila tidak) diposting ke ledger. Sisa stok yang diisi menjadi penyesuaian. Langkah ini tidak bisa diulang.')
                ->action(function () {
                    $this->save();

                    try {
                        $result = app(ProductionCompletionService::class)->complete($this->getOrder()->fresh(), auth()->id());
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Gagal menutup SPK')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('SPK ditutup')
                        ->body('Pemakaian Rp '.number_format($result['usage_value'], 2, ',', '.').' dari '.$result['lines'].' bahan'
                            .($result['adjustment_value'] != 0 ? '; penyesuaian Rp '.number_format($result['adjustment_value'], 2, ',', '.') : '').'.')
                        ->send();
                    $this->fillFromRequisition();
                }),

            Actions\Action::make('cetak')
                ->label('Cetak Form')
                ->icon('heroicon-m-printer')
                ->color('gray')
                ->visible(fn () => $this->getRequisition() !== null)
                ->action(fn () => app(ProductionDocumentService::class)->requisitionPdf($this->getRequisition())),

            Actions\Action::make('kembali')
                ->label('SPK')
                ->color('gray')
                ->url(fn () => ProductionOrderResource::getUrl('edit', ['record' => $this->getOrder()])),
        ];
    }

    public static function qty(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, ',', '.'), '0'), ',');
    }

    protected static function number(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
