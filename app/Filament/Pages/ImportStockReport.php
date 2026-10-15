<?php

namespace App\Filament\Pages;

use App\Filament\Resources\InventoryItemResource;
use App\Imports\StockReportReader;
use App\Services\StockReportImporter;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Impor laporan stok gudang (Excel harian tim gudang) ke Item Inventaris.
 *
 * Dua langkah seperti Impor Rencana Masak: baca berkas -> pratinjau per barang
 * (Sama / Mirip / Baru, dengan pilihan tautkan / buat baru / lewati) -> simpan.
 * Hasil baca (bisa 1.000+ baris) disimpan di cache, bukan di state Livewire,
 * supaya setiap klik tidak mengirim ratusan KB bolak-balik; yang ada di state
 * hanya pilihan per baris.
 */
class ImportStockReport extends Page
{
    use \App\Filament\Concerns\PageInPanel;
    use WithFileUploads;

    protected static string $ownerPanel = 'admin';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Impor Laporan Stok';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'impor-laporan-stok';

    protected static string $view = 'filament.pages.import-stock-report';

    protected static ?string $title = 'Impor Laporan Stok Gudang';

    /** Baris per halaman pratinjau. */
    public const PER_PAGE = 100;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $upload = null;

    public string $fileName = '';

    /** Kunci cache hasil baca berkas; kosong = belum ada pratinjau. */
    public string $token = '';

    /** @var array<int, string> indeks baris pratinjau => aksi */
    public array $choices = [];

    public string $statusFilter = 'mirip';

    public string $search = '';

    public int $previewPage = 1;

    public bool $withStock = true;

    public ?string $stockDate = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('inventory.manage') ?? false;
    }

    public function readFile(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->validate([
            'upload' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ], [
            'upload.required' => 'Pilih berkas Excel laporan stok dulu.',
            'upload.mimes' => 'Berkas harus Excel (.xlsx atau .xls).',
            'upload.max' => 'Berkas paling besar 10 MB.',
        ]);

        try {
            $read = app(StockReportReader::class)->read($this->upload->getRealPath());
        } catch (RuntimeException $e) {
            $this->addError('upload', $e->getMessage());

            return;
        }

        if ($read['rows'] === []) {
            $this->addError('upload', 'Tidak ada sheet berkolom "Nama Barang" yang bisa dibaca. Kepala kolom harus ada di 10 baris teratas, dan nama sheet menyebut jenisnya (BAHAN BAKU, KEMASAN, PERALATAN, INVENTARIS).');

            return;
        }

        $preview = app(StockReportImporter::class)->preview($read['rows']);

        $this->token = (string) Str::uuid();
        Cache::put($this->cacheKey(), [
            'preview' => $preview,
            'sheets' => $read['sheets'],
            'skipped' => $read['skipped'],
            'date' => $read['date'],
        ], now()->addHours(3));

        $this->fileName = $this->upload->getClientOriginalName();
        $this->choices = array_map(fn (array $row) => $row['default'], $preview);
        $this->stockDate = $read['date'] && Carbon::parse($read['date'])->lte(today()) ? $read['date'] : today()->toDateString();
        $this->statusFilter = collect($preview)->contains('status', 'mirip') ? 'mirip' : 'semua';
        $this->search = '';
        $this->previewPage = 1;
        $this->upload = null;
    }

    public function updatedStatusFilter(): void
    {
        $this->previewPage = 1;
    }

    public function updatedSearch(): void
    {
        $this->previewPage = 1;
    }

    /** Pilihan sekaligus untuk semua baris yang sedang tersaring. */
    public function setAll(string $action): void
    {
        abort_unless(in_array($action, [StockReportImporter::ACTION_NEW, StockReportImporter::ACTION_SKIP], true), 422);

        foreach (array_keys($this->filteredRows()) as $index) {
            $this->choices[$index] = $action;
        }
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->cached();

        if ($data === null) {
            Notification::make()->warning()->title('Pratinjau kedaluwarsa')->body('Baca ulang berkasnya.')->send();
            $this->resetPreview();

            return;
        }

        $choices = [];
        foreach ($data['preview'] as $index => $row) {
            $choices[$row['key']] = (string) ($this->choices[$index] ?? $row['default']);
        }

        try {
            $summary = app(StockReportImporter::class)->apply(
                $data['preview'],
                $choices,
                $this->withStock,
                $this->withStock && $this->stockDate ? Carbon::parse($this->stockDate) : null,
                auth()->user(),
            );
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Belum tersimpan')->body($e->getMessage())->persistent()->send();

            return;
        }

        $body = sprintf('%d item baru, %d ditautkan ke item yang ada (%d harga diperbarui), %d dilewati.',
            $summary['created'], $summary['linked'], $summary['priced'], $summary['skipped']);

        if ($this->withStock) {
            $body .= "\n".$summary['stock'].' item mendapat Saldo Awal di Kartu Stok.';

            if ($summary['stock_skipped'] !== []) {
                $body .= ' Stok tidak diisi untuk '.count($summary['stock_skipped']).' item: '
                    .collect($summary['stock_skipped'])->take(5)->map(fn ($reason, $name) => "{$name} ({$reason})")->implode('; ')
                    .(count($summary['stock_skipped']) > 5 ? '; …' : '.');
            }
        }

        Cache::forget($this->cacheKey());
        Notification::make()->success()->title('Laporan stok diimpor')->body($body)->persistent()->send();

        $this->redirect(InventoryItemResource::getUrl('index'));
    }

    public function resetPreview(): void
    {
        if ($this->token !== '') {
            Cache::forget($this->cacheKey());
        }

        $this->reset(['token', 'choices', 'fileName', 'search', 'previewPage']);
    }

    /** @return array{preview: list<array<string, mixed>>, sheets: array<string, int>, skipped: array<string, string>, date: ?string}|null */
    public function cached(): ?array
    {
        return $this->token === '' ? null : Cache::get($this->cacheKey());
    }

    /** @return array<int, array<string, mixed>> indeks => baris, sesuai saringan */
    public function filteredRows(): array
    {
        $rows = $this->cached()['preview'] ?? [];
        $search = mb_strtolower(trim($this->search));

        return array_filter($rows, fn (array $row) => ($this->statusFilter === 'semua' || $row['status'] === $this->statusFilter)
            && ($search === '' || str_contains(mb_strtolower($row['name']), $search)));
    }

    private function cacheKey(): string
    {
        return 'stock-report-import:'.auth()->id().':'.$this->token;
    }
}
