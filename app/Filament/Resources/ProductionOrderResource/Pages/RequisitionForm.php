<?php

namespace App\Filament\Resources\ProductionOrderResource\Pages;

use App\Filament\Resources\ProductionOrderResource;
use App\Models\CashAccount;
use App\Models\ExpenseCategory;
use App\Models\ProductionOrder;
use App\Models\Requisition;
use App\Models\RequisitionLine;
use App\Services\ProductionCompletionService;
use App\Services\ProductionDocumentService;
use App\Services\ProductionOrderService;
use App\Services\RequisitionService;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
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
            'payment_type' => $requisition?->payment_type,
            'expense_category_id' => $requisition?->expense_category_id ?? app(RequisitionService::class)->defaultPurchaseCategoryId(),
            'cash_account_id' => $requisition?->cash_account_id,
            'supplier_name' => $requisition?->supplier_name,
            'due_date' => $requisition?->due_date?->toDateString(),
            'lines' => $requisition
                ? $requisition->lines->map(fn (RequisitionLine $line) => [
                    'id' => $line->id,
                    'name' => $line->name,
                    'unit' => $line->unit,
                    'required_qty' => (float) $line->required_qty,
                    'opening_stock_qty' => $line->opening_stock_qty === null ? null : (float) $line->opening_stock_qty,
                    'purchase_qty' => $line->purchase_qty === null ? null : (float) $line->purchase_qty,
                    'received_qty' => $line->received_qty === null ? null : (float) $line->received_qty,
                    'rejected_reason' => $line->rejected_reason,
                    'rejected_treatment' => $line->rejected_treatment,
                    'purchase_price' => $line->purchase_price === null ? null : (float) $line->purchase_price,
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
        $receivingOpen = $requisition?->isApproved() ?? false;
        $actualsOpen = ($requisition?->isChecked() ?? false) && ! $order->isCompleted();

        // Kolom tiap tahap baru muncul saat tahapnya tiba: tahap Dibuat hanya
        // Kebutuhan/Stok Awal/Beli; penerimaan (Diterima, Ditolak, Harga Beli)
        // sejak Disetujui; pemakaian sejak Diperiksa.
        $receivingVisible = $requisition !== null && ! $requisition->isDraft();
        $actualsVisible = $requisition?->isChecked() ?? false;

        return $form
            ->schema([
                Section::make('Pembayaran belanja')
                    ->description('Saat Periksa, pembelian bahan baku beserta kas keluar / hutangnya dibuat otomatis per bahan sesuai jumlah diterima dan harga beli.')
                    ->visible($receivingVisible)
                    ->columns(4)
                    ->schema([
                        Select::make('payment_type')
                            ->label('Jenis Pembayaran')
                            ->options(Requisition::paymentTypeOptions())
                            ->native(false)
                            ->live()
                            ->disabled(! $receivingOpen)
                            ->dehydrated($receivingOpen),

                        Select::make('expense_category_id')
                            ->label('Kategori Pengeluaran')
                            ->options(fn () => ExpenseCategory::query()
                                ->where('expense_mode', ExpenseCategory::MODE_INVENTORY_PURCHASE)
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->native(false)
                            ->searchable()
                            ->visible(fn (Get $get) => $get('payment_type') === 'cash')
                            ->disabled(! $receivingOpen)
                            ->dehydrated($receivingOpen),

                        Select::make('cash_account_id')
                            ->label('Akun Kas')
                            ->options(fn () => CashAccount::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->native(false)
                            ->searchable()
                            ->visible(fn (Get $get) => $get('payment_type') === 'cash')
                            ->disabled(! $receivingOpen)
                            ->dehydrated($receivingOpen),

                        TextInput::make('supplier_name')
                            ->label('Supplier')
                            ->maxLength(255)
                            ->visible(fn (Get $get) => $get('payment_type') !== null)
                            ->disabled(! $receivingOpen)
                            ->dehydrated($receivingOpen),

                        DatePicker::make('due_date')
                            ->label('Jatuh Tempo')
                            ->native(false)
                            ->visible(fn (Get $get) => $get('payment_type') === 'payable')
                            ->disabled(! $receivingOpen)
                            ->dehydrated($receivingOpen),
                    ]),

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
                            ->suffix(fn ($get) => $receivingVisible ? null : $get('unit'))
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
                            ->suffix(fn ($get) => $receivingVisible ? null : $get('unit'))
                            ->disabled(! $draft)
                            ->dehydrated($draft)
                            ->helperText($draft ? 'Boleh dibulatkan ke kemasan.' : null),

                        Placeholder::make('harga')
                            ->label('Harga Master')
                            ->visible(! $receivingVisible)
                            ->content(fn ($get) => $get('unit_price') === null ? '-' : 'Rp '.number_format((float) $get('unit_price'), 2, ',', '.')),

                        TextInput::make('received_qty')
                            ->label('Diterima')
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->suffix(fn ($get) => $receivingVisible ? null : $get('unit'))
                            ->placeholder('= beli')
                            ->visible($receivingVisible)
                            ->disabled(! $receivingOpen)
                            ->dehydrated($receivingOpen)
                            ->live(onBlur: true),

                        // Ditolak = Beli - Diterima: dihitung, tidak diketik, supaya
                        // tidak ada barang yang "hilang" di antara keduanya.
                        Placeholder::make('ditolak')
                            ->label('Ditolak')
                            ->visible($receivingVisible)
                            ->content(fn ($get) => static::qty(max((float) $get('purchase_qty') - (float) ($get('received_qty') ?? $get('purchase_qty')), 0)).' '.$get('unit')),

                        TextInput::make('rejected_reason')
                            ->label('Alasan Ditolak')
                            ->maxLength(255)
                            ->placeholder('mis. busuk, kemasan rusak')
                            ->columnSpan(2)
                            ->visible($receivingVisible)
                            ->disabled(! $receivingOpen)
                            ->dehydrated($receivingOpen),

                        Select::make('rejected_treatment')
                            ->label('Perlakuan')
                            ->options(RequisitionLine::rejectTreatmentOptions())
                            ->native(false)
                            ->placeholder(app(\App\Support\Settings\Settings::class)->get('requisition.reject_default_treatment') === RequisitionLine::REJECT_PAID ? 'bawaan: dibayar' : 'bawaan: retur')
                            ->columnSpan(2)
                            ->visible($receivingVisible)
                            ->disabled(! $receivingOpen)
                            ->dehydrated($receivingOpen),

                        TextInput::make('purchase_price')
                            ->label('Harga Beli')
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->prefix('Rp')
                            ->placeholder(fn ($get) => $get('unit_price') === null ? 'wajib: master kosong' : number_format((float) $get('unit_price'), 0, ',', '.'))
                            ->helperText(fn ($get) => $receivingOpen ? 'per '.$get('unit').', dari nota; kosong = harga master' : 'per '.$get('unit'))
                            ->columnSpan(2)
                            ->visible($receivingVisible)
                            ->disabled(! $receivingOpen)
                            ->dehydrated($receivingOpen),

                        TextInput::make('actual_used_qty')
                            ->label('Pemakaian Aktual')
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->suffix(fn ($get) => $receivingVisible ? null : $get('unit'))
                            ->placeholder('= kebutuhan')
                            ->visible($actualsVisible)
                            ->disabled(! $actualsOpen)
                            ->dehydrated($actualsOpen),

                        TextInput::make('remaining_qty')
                            ->label('Sisa Stok')
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->suffix(fn ($get) => $receivingVisible ? null : $get('unit'))
                            ->placeholder('tidak dihitung')
                            ->visible($actualsVisible)
                            ->disabled(! $actualsOpen)
                            ->dehydrated($actualsOpen),

                        TextInput::make('notes')
                            ->label('Catatan')
                            ->maxLength(255)
                            ->columnSpan($receivingVisible ? 1 : 2)
                            ->disabled(! ($draft || $receivingOpen || $actualsOpen))
                            ->dehydrated($draft || $receivingOpen || $actualsOpen),
                    ])
                    // Draft: 4 kolom angka + harga + catatan(2). Penerimaan: +Diterima,
                    // Ditolak, Alasan(2), Perlakuan(2), Harga Beli(2). Pemakaian: +2.
                    ->columns($actualsVisible ? 14 : ($receivingVisible ? 12 : 6)),
            ])
            ->statePath('data');
    }

    /** Simpan isian sesuai tahap form saat ini. */
    public function save(): void
    {
        $requisition = $this->getRequisition();

        if (! $requisition) {
            return;
        }

        // Siapa yang boleh mengisi mengikuti tahapnya: produksi menyusun
        // (draft) dan mencatat pemakaian (checked); penerimaan barang diisi
        // pemegang izin Periksa (inventory/accounting/admin).
        abort_unless(static::canFill($requisition), 403);

        $service = app(RequisitionService::class);
        $completion = app(ProductionCompletionService::class);
        $state = $this->form->getState();

        try {
            if ($requisition->isApproved()) {
                $service->recordPaymentHeader($requisition, [
                    'payment_type' => $state['payment_type'] ?? null,
                    'expense_category_id' => $state['expense_category_id'] ?? null,
                    'cash_account_id' => $state['cash_account_id'] ?? null,
                    'supplier_name' => $state['supplier_name'] ?? null,
                    'due_date' => $state['due_date'] ?? null,
                ]);
            }

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
                } elseif ($requisition->isApproved()) {
                    $service->recordReceipt(
                        $line,
                        static::number($row['received_qty'] ?? null),
                        $row['rejected_reason'] ?? null,
                        $row['rejected_treatment'] ?? null,
                        static::number($row['purchase_price'] ?? null),
                    );
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

    /** Izin mengisi form pada tahapnya saat ini. */
    public static function canFill(Requisition $requisition): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $requisition->isApproved()
            ? $user->can('requisition.check')
            : $user->can('production.manage');
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
                ->visible(function () {
                    $requisition = $this->getRequisition();

                    return $requisition !== null
                        && static::canFill($requisition)
                        && ($requisition->isDraft() || $requisition->isApproved() || ($requisition->isChecked() && ! $this->getOrder()->fresh()->isCompleted()));
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
                ->modalDescription('Menandai barang sudah diterima & diperiksa. Jumlah Diterima (= Beli bila kosong) dicatat ke kartu stok dengan harga beli, pembelian bahan baku beserta kas keluar / hutangnya dibuat otomatis, dan barang ditolak yang tetap dibayar masuk Kerugian Barang Rusak. Langkah ini tidak bisa diulang.')
                ->action(function () {
                    // Isian "Diterima" yang belum disimpan ikut dibawa.
                    $this->save();

                    try {
                        app(RequisitionService::class)->check($this->getRequisition(), auth()->id());
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Gagal memeriksa')->body($e->getMessage())->send();

                        return;
                    }

                    $fresh = $this->getRequisition();
                    $jumlah = $fresh?->purchases()->count() ?? 0;
                    $nilai = (float) ($fresh?->purchases()->sum('total_value') ?? 0);

                    Notification::make()->success()
                        ->title('Barang tercatat masuk ke kartu stok')
                        ->body($jumlah > 0
                            ? $jumlah.' pembelian bahan baku dibuat ('.($fresh->paymentTypeLabel() ?? '').'), Rp '.number_format($nilai, 0, ',', '.').'.'
                            : 'Tidak ada pembelian: seluruh kebutuhan dipenuhi dari stok.')
                        ->send();
                    $this->fillFromRequisition();
                }),

            Actions\Action::make('tutup')
                ->label('Tutup SPK (Posting Pemakaian)')
                ->authorize('production.complete')
                ->icon('heroicon-m-lock-closed')
                ->color('warning')
                ->visible(fn () => ($this->getRequisition()?->isChecked() ?? false) && ! $this->getOrder()->fresh()->isCompleted())
                ->requiresConfirmation()
                ->modalDescription('Pemakaian bahan (aktual bila diisi, kebutuhan resep bila tidak) dicatat ke kartu stok. Sisa stok yang diisi menjadi penyesuaian. Langkah ini tidak bisa diulang.')
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
