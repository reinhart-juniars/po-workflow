<?php

namespace App\Filament\Resources\ProductionOrderResource\Pages;

use App\Filament\Resources\ProductionOrderResource;
use App\Filament\Resources\SupplierResource;
use App\Models\ProductionOrder;
use App\Models\Requisition;
use App\Models\RequisitionLine;
use App\Services\ProductionCompletionService;
use App\Services\ProductionDocumentService;
use App\Services\ProductionOrderService;
use App\Services\RequisitionService;
use Filament\Actions;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
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
        return 'Form Kebutuhan';
    }

    public function getSubheading(): ?string
    {
        return $this->getOrder()->number;
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
            'supplier_id' => $requisition?->supplier_id,
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

    /**
     * Tahap isian baris saat ini: draft (Stok Awal & Beli), receiving
     * (Diterima, Ditolak, Harga Beli), actuals (Pemakaian & Sisa), locked.
     * Dibaca view tabel bahan untuk memilih kolom mana yang berupa input.
     */
    public function lineStage(): string
    {
        $requisition = $this->getRequisition();

        if (! $requisition) {
            return 'locked';
        }

        if ($requisition->isDraft()) {
            return 'draft';
        }

        if ($requisition->isApproved()) {
            return 'receiving';
        }

        if ($requisition->isSubmitted()) {
            return 'locked';
        }

        return $this->getOrder()->isCompleted() ? 'locked' : 'actuals';
    }

    /**
     * Keterangan meja untuk pengguna yang sedang membuka form: apa yang
     * terjadi sekarang dan siapa yang harus bertindak.
     */
    public function stageNotice(): array
    {
        $requisition = $this->getRequisition();
        $user = auth()->user();

        if (! $requisition) {
            return ['color' => 'gray', 'text' => 'Form belum disusun. Produksi menekan "Susun Form" untuk membuat baris kebutuhan dari resep × jumlah menu.'];
        }

        if ($requisition->isDraft()) {
            return $user?->can('production.manage')
                ? ['color' => 'info', 'text' => 'Meja produksi: isi Stok Awal hasil hitungan fisik (Beli = Kebutuhan − Stok Awal, boleh dibulatkan ke kemasan), Simpan, lalu Ajukan ke supervisor gudang.']
                : ['color' => 'gray', 'text' => 'Masih disusun produksi; belum diajukan ke supervisor gudang.'];
        }

        if ($requisition->isSubmitted()) {
            return $user?->can('requisition.approve')
                ? ['color' => 'warning', 'text' => 'Meja supervisor gudang: periksa angka Stok Awal & Beli, lalu Setujui — atau Tolak dengan alasan supaya produksi memperbaiki.']
                : ['color' => 'gray', 'text' => 'Diajukan '.($requisition->submittedBy?->name ?? '').' '.$requisition->submitted_at?->format('d/m H:i').'; menunggu persetujuan supervisor gudang. Isian terkunci.'];
        }

        if ($requisition->isApproved()) {
            return $user?->can('requisition.check')
                ? ['color' => 'info', 'text' => 'Meja gudang: saat barang datang isi Diterima (ditolak = Beli − Diterima, beri alasannya), Harga Beli dari nota, dan cara pembayaran. Simpan, lalu Periksa — stok, pembelian, dan kas/hutang tercatat sekaligus.']
                : ['color' => 'gray', 'text' => 'Disetujui '.($requisition->approvedBy?->name ?? '').'; gudang sedang belanja / menerima barang. Isian terkunci sampai diperiksa.'];
        }

        if (! $this->getOrder()->isCompleted()) {
            return $user?->can('production.manage')
                ? ['color' => 'info', 'text' => 'Meja produksi: barang sudah masuk kartu stok. Setelah produksi, isi Pemakaian Aktual dan Sisa Stok bila dihitung, lalu Tutup SPK.']
                : ['color' => 'gray', 'text' => 'Diperiksa '.($requisition->checkedBy?->name ?? '').'; menunggu produksi menutup SPK.'];
        }

        return ['color' => 'success', 'text' => 'SPK sudah ditutup; pemakaian sudah dicatat ke kartu stok.'];
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
        $receivingVisible = $requisition !== null && ($requisition->isApproved() || $requisition->isChecked());
        $actualsVisible = $requisition?->isChecked() ?? false;

        return $form
            ->schema([
                // Gudang tidak memilih akun kas: saat Periksa, belanja form ini
                // menjadi satu Tagihan Pembelian yang diajukan ke accounting
                // (revisi 7 Okt 2026). Yang dicatat di sini hanya tokonya.
                Section::make('Belanja')
                    ->description(fn () => ($bill = $this->getRequisition()?->purchaseBill)
                        ? 'Belanja form ini ditagihkan lewat '.$bill->number.' ('.$bill->statusLabel().').'
                        : 'Saat Periksa, pembelian bahan baku dibuat per bahan sesuai jumlah diterima dan harga beli, lalu dikumpulkan menjadi satu Tagihan Pembelian untuk diajukan ke accounting.')
                    ->visible($receivingVisible)
                    ->columns(4)
                    ->schema([
                        SupplierResource::picker()
                            ->helperText(fn () => ($requisition = $this->getRequisition()) && ! $requisition->supplier_id && filled($requisition->supplier_name)
                                ? 'Tercatat sebagai "'.$requisition->supplier_name.'" (belum ada di Master Supplier).'
                                : 'Toko / supplier tempat belanja. Boleh kosong bila belanja di beberapa tempat.')
                            ->columnSpan(2)
                            ->disabled(! $receivingOpen)
                            ->dehydrated($receivingOpen),
                    ]),
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
        // Header (cara pembayaran) lewat form Filament; baris bahan dibaca
        // langsung dari $data karena dirender sebagai tabel, bukan Repeater.
        $state = $this->form->getState();
        $rows = array_values((array) ($this->data['lines'] ?? []));

        try {
            if ($requisition->isApproved()) {
                $service->recordPaymentHeader($requisition, [
                    'supplier_id' => $state['supplier_id'] ?? null,
                ]);
            }

            foreach ($rows as $row) {
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

        if ($requisition->isSubmitted()) {
            return false; // menunggu keputusan supervisor gudang; tidak ada yang mengisi
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
                        && ! $requisition->isSubmitted()
                        && static::canFill($requisition)
                        && ($requisition->isDraft() || $requisition->isApproved() || ($requisition->isChecked() && ! $this->getOrder()->fresh()->isCompleted()));
                })
                ->action(fn () => $this->save()),

            // Meja produksi: mengajukan. Meja supervisor gudang: menyetujui /
            // menolak. Tombolnya tidak pernah tampil bersamaan.
            Actions\Action::make('ajukan')
                ->label('Ajukan ke Supervisor Gudang')
                ->authorize('production.manage')
                ->icon('heroicon-m-paper-airplane')
                ->color('info')
                ->visible(fn () => $this->getRequisition()?->isDraft() ?? false)
                ->requiresConfirmation()
                ->modalDescription('Setelah diajukan, Stok Awal dan Beli tidak bisa diubah sampai supervisor gudang menyetujui atau menolak.')
                ->action(function () {
                    $this->save();

                    try {
                        app(RequisitionService::class)->submit($this->getRequisition(), auth()->id());
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Belum bisa diajukan')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title('Form diajukan ke supervisor gudang')->send();
                    $this->fillFromRequisition();
                }),

            Actions\Action::make('setujui')
                ->label('Setujui')
                ->authorize('requisition.approve')
                ->icon('heroicon-m-hand-thumb-up')
                ->color('success')
                ->visible(fn () => $this->getRequisition()?->isSubmitted() ?? false)
                ->requiresConfirmation()
                ->modalDescription('Stok Awal dan Beli dikunci; gudang mulai belanja / menerima barang.')
                ->action(function () {
                    try {
                        app(RequisitionService::class)->approve($this->getRequisition(), auth()->id());
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Belum bisa disetujui')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title('Form disetujui')->send();
                    $this->fillFromRequisition();
                }),

            Actions\Action::make('tolak')
                ->label('Tolak')
                ->authorize('requisition.approve')
                ->icon('heroicon-m-hand-thumb-down')
                ->color('danger')
                ->visible(fn () => $this->getRequisition()?->isSubmitted() ?? false)
                ->form([
                    Textarea::make('reason')
                        ->label('Alasan penolakan')
                        ->helperText('Dibaca produksi untuk memperbaiki form; form kembali ke tahap Dibuat.')
                        ->rows(3)
                        ->required()
                        ->maxLength(255),
                ])
                ->action(function (array $data) {
                    try {
                        app(RequisitionService::class)->reject($this->getRequisition(), (string) $data['reason'], auth()->id());
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Gagal menolak')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->warning()->title('Form dikembalikan ke produksi')->send();
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

            // Aksi sekunder dikelompokkan supaya bilah tombol tidak melebar
            // melampaui layar laptop (1366px) dan mendorong judul ke dua baris.
            Actions\ActionGroup::make([
                Actions\Action::make('cetak')
                    ->label('Cetak Form')
                    ->icon('heroicon-m-printer')
                    ->visible(fn () => $this->getRequisition() !== null)
                    ->action(fn () => app(ProductionDocumentService::class)->requisitionPdf($this->getRequisition())),

                Actions\Action::make('kembali')
                    ->label('Buka SPK Produksi')
                    ->icon('heroicon-m-fire')
                    ->url(fn () => ProductionOrderResource::getUrl('edit', ['record' => $this->getOrder()])),
            ])
                ->label('Lainnya')
                ->icon('heroicon-m-ellipsis-horizontal')
                ->color('gray')
                ->button(),
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
